<?php

namespace App\Tests\Api;

use App\EmailCampaign\EmailCampaignCatalog;
use App\EmailCampaign\EmailCampaignMailer;
use App\Entity\ConnectionHistory;
use App\Entity\EmailCampaignSend;
use App\Entity\EmailUnsubscribe;
use App\Tests\ApiTestBase;
use App\Tests\Export\Support\SentEmailCollector;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Campagnes e-mail (docs/CAMPAGNES-EMAIL.md) : aperçu, e-mail test, contenu validé, désinscription,
 * sélection des destinataires, protection contre les doubles envois, verrou de l'envoi collectif.
 */
class EmailCampaignTest extends ApiTestBase
{
    private const SLUG = 'recherche-inami';

    protected function setUp(): void
    {
        parent::setUp();
        SentEmailCollector::$emails = [];
        SentEmailCollector::$fail = false;
    }

    protected function tearDown(): void
    {
        SentEmailCollector::$fail = false;
        unset($_SERVER['EMAIL_CAMPAIGN_BULK_ENABLED'], $_ENV['EMAIL_CAMPAIGN_BULK_ENABLED']);
        putenv('EMAIL_CAMPAIGN_BULK_ENABLED');
        parent::tearDown();
    }

    public function testAdminRoutesAreAdminOnly(): void
    {
        $client = $this->clientFor($this->createUser('alice@test.be'));
        foreach (['/api/admin/email-campaigns', '/api/admin/email-campaigns/' . self::SLUG, '/api/admin/email-campaigns/' . self::SLUG . '/preview', '/api/admin/email-unsubscribes'] as $url) {
            $client->request('GET', $url);
            $this->assertResponseStatusCodeSame(403, $url);
        }
        $client->request('POST', '/api/admin/email-campaigns/' . self::SLUG . '/test', ['json' => ['email' => 'alice@test.be']]);
        $this->assertResponseStatusCodeSame(403);
        $this->assertCount(0, SentEmailCollector::$emails);
    }

    public function testCampaignListAndDetail(): void
    {
        $client = $this->clientFor($this->createUser('admin@test.be', ['ROLE_ADMIN']));

        $list = $client->request('GET', '/api/admin/email-campaigns')->toArray();
        $this->assertSame('recherche-inami', $list['campaigns'][0]['slug']);
        $this->assertSame('Vos codes INAMI en quelques secondes !', $list['campaigns'][0]['subject']);
        $this->assertSame(['ready' => true, 'sender' => 'contact@medclick.be', 'reason' => null], $list['sender']);

        $detail = $client->request('GET', '/api/admin/email-campaigns/' . self::SLUG)->toArray();
        $this->assertSame('https://www.medclick.be/codes-inami', $detail['destination_url']);
        $this->assertFalse($detail['bulk_enabled']);
        $this->assertSame(['admin@test.be'], $detail['test_recipients']);

        $client->request('GET', '/api/admin/email-campaigns/inconnue');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testPreviewIsTheValidatedContent(): void
    {
        $client = $this->clientFor($this->createUser('admin@test.be', ['ROLE_ADMIN']));
        $response = $client->request('GET', '/api/admin/email-campaigns/' . self::SLUG . '/preview');
        $this->assertResponseIsSuccessful();
        $html = $response->getContent();

        $this->assertStringContainsString('data:image/jpeg;base64,', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertValidatedContent($html);
    }

    public function testTestEmailHasTheCampaignRendering(): void
    {
        $admin = $this->createUser('admin@test.be', ['ROLE_ADMIN']);
        $response = $this->clientFor($admin)->request('POST', '/api/admin/email-campaigns/' . self::SLUG . '/test', ['json' => ['email' => 'Admin@Test.be ']]);

        $this->assertResponseIsSuccessful();
        $this->assertSame('sent', $response->toArray()['status']);
        $this->assertCount(1, SentEmailCollector::$emails);
        $email = SentEmailCollector::$emails[0];

        $this->assertSame('contact@medclick.be', $email->getFrom()[0]->getAddress());
        $this->assertSame('MedClick', $email->getFrom()[0]->getName());
        $this->assertSame('contact@medclick.be', $email->getReplyTo()[0]->getAddress());
        $this->assertSame(['admin@test.be'], array_map(fn ($a) => $a->getAddress(), $email->getTo()));
        $this->assertSame([], $email->getCc());
        $this->assertSame([], $email->getBcc());
        $this->assertSame('Vos codes INAMI en quelques secondes !', $email->getSubject());

        // Image intégrée (pièce « inline »), référencée par cid: ; bouton = vrai lien.
        $inline = array_values(array_filter($email->getAttachments(), fn (DataPart $p) => $p->getDisposition() === 'inline'));
        $this->assertCount(1, $inline);
        $this->assertSame('image/jpeg', $inline[0]->getMediaType() . '/' . $inline[0]->getMediaSubtype());
        $this->assertLessThan(300_000, strlen($inline[0]->getBody()), 'Visuel optimisé (< 300 Ko).');
        $html = $email->getHtmlBody();
        $this->assertStringContainsString('src="cid:recherche-inami.jpg"', $html);
        $this->assertValidatedContent($html);
        $this->assertStringContainsString('Rechercher un code INAMI : https://www.medclick.be/codes-inami', $email->getTextBody());

        // Désinscription : lien propre à l'envoi (sans l'adresse) et en-têtes un clic.
        $send = $this->em->getRepository(EmailCampaignSend::class)->findOneBy([]);
        $url = 'https://api-medclick.easymed.fun/email/desinscription/' . $send->getUnsubscribeToken();
        $this->assertStringContainsString('href="' . $url . '"', $html);
        $this->assertStringNotContainsString('admin@test.be', $url);
        $this->assertStringContainsString('<' . $url . '>', $email->getHeaders()->get('List-Unsubscribe')->getBodyAsString());
        $this->assertSame('List-Unsubscribe=One-Click', $email->getHeaders()->get('List-Unsubscribe-Post')->getBodyAsString());
        $this->assertSame(EmailCampaignSend::KIND_TEST, $send->getKind());
        $this->assertNull($send->getDedupKey());

        // Aucun pixel de suivi : la seule image est le visuel.
        $this->assertSame(1, substr_count($html, '<img'));
    }

    public function testTestEmailOnlyToAdminAddress(): void
    {
        $this->createUser('alice@test.be');
        $client = $this->clientFor($this->createUser('admin@test.be', ['ROLE_ADMIN']));

        $client->request('POST', '/api/admin/email-campaigns/' . self::SLUG . '/test', ['json' => ['email' => 'alice@test.be']]);
        $this->assertResponseStatusCodeSame(400);
        $this->assertCount(0, SentEmailCollector::$emails);
    }

    public function testTestEmailFailureIsRecorded(): void
    {
        SentEmailCollector::$fail = true;
        $client = $this->clientFor($this->createUser('admin@test.be', ['ROLE_ADMIN']));

        $response = $client->request('POST', '/api/admin/email-campaigns/' . self::SLUG . '/test', ['json' => ['email' => 'admin@test.be']]);
        $this->assertResponseStatusCodeSame(502);
        $this->assertSame('failed', $response->toArray(false)['status']);
    }

    public function testUnsubscribeLinkConfirmsThenRecords(): void
    {
        $admin = $this->createUser('admin@test.be', ['ROLE_ADMIN']);
        $client = $this->clientFor($admin);
        $client->request('POST', '/api/admin/email-campaigns/' . self::SLUG . '/test', ['json' => ['email' => 'admin@test.be']]);
        $token = $this->em->getRepository(EmailCampaignSend::class)->findOneBy([])->getUnsubscribeToken();

        $public = static::createClient();
        // Ouvrir le lien (antivirus des messageries) ne désinscrit pas.
        $page = $public->request('GET', '/email/desinscription/' . $token);
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Confirmer la désinscription', $page->getContent());
        $this->assertSame(0, $this->em->getRepository(EmailUnsubscribe::class)->count([]));

        $public->request('POST', '/email/desinscription/' . $token);
        $this->assertResponseIsSuccessful();
        $this->em->clear();
        $unsubscribe = $this->em->getRepository(EmailUnsubscribe::class)->findOneBy(['email' => 'admin@test.be']);
        $this->assertNotNull($unsubscribe);
        $this->assertSame(EmailUnsubscribe::SOURCE_LINK, $unsubscribe->getSource());
        $this->assertSame(self::SLUG, $unsubscribe->getCampaign());

        // Deuxième clic : sans erreur ni doublon.
        $public->request('POST', '/email/desinscription/' . $token);
        $this->assertResponseIsSuccessful();
        $this->assertSame(1, $this->em->getRepository(EmailUnsubscribe::class)->count([]));

        // Plus aucun envoi vers cette adresse, même test.
        $refused = $client->request('POST', '/api/admin/email-campaigns/' . self::SLUG . '/test', ['json' => ['email' => 'admin@test.be']]);
        $this->assertSame(409, $refused->getStatusCode());
        $this->assertCount(1, SentEmailCollector::$emails);
    }

    public function testOneClickUnsubscribe(): void
    {
        $send = $this->mailer()->sendTest($this->campaign(), 'bob@test.be', null);

        static::createClient()->request('POST', '/email/desinscription/' . $send->getUnsubscribeToken(), [
            'extra' => ['parameters' => ['List-Unsubscribe' => 'One-Click']],
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSame(EmailUnsubscribe::SOURCE_ONE_CLICK, $this->em->getRepository(EmailUnsubscribe::class)->findOneBy(['email' => 'bob@test.be'])->getSource());
    }

    public function testUnknownUnsubscribeToken(): void
    {
        static::createClient()->request('POST', '/email/desinscription/' . str_repeat('a', 48));
        $this->assertResponseStatusCodeSame(404);
        $this->assertSame(0, $this->em->getRepository(EmailUnsubscribe::class)->count([]));
    }

    public function testAdminManagesUnsubscribes(): void
    {
        $client = $this->clientFor($this->createUser('admin@test.be', ['ROLE_ADMIN']));

        $client->request('POST', '/api/admin/email-unsubscribes', ['json' => ['email' => ' Carole@Test.be']]);
        $this->assertResponseStatusCodeSame(201);
        $list = $client->request('GET', '/api/admin/email-unsubscribes?q=carole')->toArray();
        $this->assertSame(1, $list['total']);
        $this->assertSame('carole@test.be', $list['items'][0]['email']);
        $this->assertSame('admin', $list['items'][0]['source']);

        $client->request('DELETE', '/api/admin/email-unsubscribes/' . $list['items'][0]['id']);
        $this->assertResponseIsSuccessful();
        $this->assertSame(0, $client->request('GET', '/api/admin/email-unsubscribes')->toArray()['total']);
    }

    public function testAudienceSegmentsAndExclusions(): void
    {
        $admin = $this->createUser('admin@test.be', ['ROLE_ADMIN']);
        $active = $this->createUser('active@test.be');
        $former = $this->createUser('former@test.be');
        $this->createUser('pending@test.be')->setToken(str_repeat('x', 32));
        $this->createUser('stop@test.be');
        $this->createUser('done@test.be');
        $this->em->persist((new ConnectionHistory())->setUser($former)->setDate(new \DateTime('-2 years')));
        $this->em->persist((new ConnectionHistory())->setUser($active)->setDate(new \DateTime('-1 month')));
        $this->em->persist(new EmailUnsubscribe('STOP@test.be', EmailUnsubscribe::SOURCE_ADMIN));
        $this->em->flush();
        $this->em->getConnection()->executeStatement("UPDATE user SET created_at = '2020-01-01 00:00:00' WHERE email = 'former@test.be'");
        $this->mailer()->sendCampaign($this->campaign(), 'done@test.be');

        $data = $this->clientFor($admin)->request('GET', '/api/admin/email-campaigns/' . self::SLUG . '/audience')->toArray();

        // admin et active : actifs (connexion récente, l'admin vient de se connecter) ; former : ancien.
        $this->assertSame(2, $data['eligible']['actifs']);
        $this->assertSame(1, $data['eligible']['anciens']);
        $this->assertSame(3, $data['eligible']['total']);
        $this->assertSame(['non_activés' => 1, 'adresse_invalide' => 0, 'désinscrits' => 1, 'déjà_envoyés' => 1], $data['excluded']);
        $this->assertStringNotContainsString('@test.be', json_encode($data), 'Aucune adresse dans la sélection.');
    }

    public function testCampaignSendIsNeverDuplicated(): void
    {
        $mailer = $this->mailer();
        $this->assertNotNull($mailer->sendCampaign($this->campaign(), 'alice@test.be'));
        $this->assertNull($mailer->sendCampaign($this->campaign(), ' ALICE@test.be'));
        $this->assertCount(1, SentEmailCollector::$emails);
    }

    public function testBulkSendIsLockedAndDryRunByDefault(): void
    {
        $this->createUser('alice@test.be');
        $tester = $this->command();

        $tester->execute(['campaign' => self::SLUG, '--audience' => 'tous']);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Simulation', $tester->getDisplay());

        $tester->execute(['campaign' => self::SLUG, '--audience' => 'tous', '--confirm' => true]);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('verrouillé', $tester->getDisplay());
        $this->assertCount(0, SentEmailCollector::$emails);
        $this->assertSame(0, $this->em->getRepository(EmailCampaignSend::class)->count([]));
    }

    public function testBulkSendWhenUnlockedSendsOncePerAddress(): void
    {
        $_SERVER['EMAIL_CAMPAIGN_BULK_ENABLED'] = $_ENV['EMAIL_CAMPAIGN_BULK_ENABLED'] = '1';
        putenv('EMAIL_CAMPAIGN_BULK_ENABLED=1');
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine')->getManager();

        $this->createUser('alice@test.be');
        $this->createUser('bob@test.be');
        $tester = $this->command();

        $tester->execute(['campaign' => self::SLUG, '--audience' => 'tous', '--confirm' => true, '--pause-ms' => '0']);
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertCount(2, SentEmailCollector::$emails);
        foreach (SentEmailCollector::$emails as $email) {
            $this->assertInstanceOf(Email::class, $email);
            $this->assertCount(1, $email->getTo(), 'Un destinataire par message.');
        }

        // Relance : rien de plus.
        $tester->execute(['campaign' => self::SLUG, '--audience' => 'tous', '--confirm' => true, '--pause-ms' => '0']);
        $this->assertCount(2, SentEmailCollector::$emails);
    }

    private function assertValidatedContent(string $html): void
    {
        foreach ([
            'Nouveau sur MedClick : vos codes INAMI en quelques secondes !',
            'Bonjour,',
            '<strong>MedClick évolue et vous propose un nouvel outil gratuit !</strong>',
            'Vous pouvez désormais retrouver rapidement vos <strong>codes INAMI</strong>, directement depuis MedClick, sans inscription ni connexion.',
            '<strong>Une recherche simple et rapide :</strong>',
            'Recherche par spécialité, intervention ou numéro de code.',
            'Affichage instantané des résultats pendant la saisie.',
            'Codes ambulatoires et hospitalisation, avec valeurs N/K.',
            'Copie des codes en un clic.',
            'Accessible sur ordinateur et smartphone.',
            '>Rechercher un code INAMI</a>',
            'href="https://www.medclick.be/codes-inami"',
            '<strong>Et votre carnet de stage ?</strong>',
            'Que vous soyez encore en formation ou déjà diplômé, MedClick continue de vous accompagner dans votre pratique quotidienne.',
            'À bientôt sur MedClick !',
            "<strong>Sarah de l'équipe MedClick</strong>",
            'href="https://www.medclick.be"',
            'href="mailto:contact@medclick.be"',
            'Vous recevez cet e-mail parce que',
            'désinscrivez-vous</a>',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        $this->assertMatchesRegularExpression('/<img [^>]*alt="[^"]{20,}"/', $html);
    }

    private function mailer(): EmailCampaignMailer
    {
        return static::getContainer()->get(EmailCampaignMailer::class);
    }

    private function campaign(): \App\EmailCampaign\EmailCampaignDefinition
    {
        return static::getContainer()->get(EmailCampaignCatalog::class)->get(self::SLUG);
    }

    private function command(): CommandTester
    {
        return new CommandTester((new Application(static::$kernel))->find('app:email-campaign:send'));
    }
}
