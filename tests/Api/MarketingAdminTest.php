<?php

namespace App\Tests\Api;

use App\Entity\Marketing;
use App\Entity\MarketingView;
use App\Tests\ApiTestBase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Administration des campagnes sponsors (/api/admin/marketing) : validation des champs, date de
 * fin effaçable, suppression d'une campagne déjà affichée.
 */
class MarketingAdminTest extends ApiTestBase
{
    private const VALID = [
        'campaign_name' => 'Sponsor fictif',
        'status' => 'active',
        'start_date' => '2026-10-01',
        'end_date' => '2026-12-31',
        'duration' => 5,
        'redirect_url' => 'https://exemple.be/sponsor',
        'smartphone_format' => 'https://cdn.exemple.be/sponsor-mobile.jpg',
        'large_screen_format' => 'https://cdn.exemple.be/sponsor-large.jpg',
    ];

    public function testAdminCreatesCampaign(): void
    {
        $this->admin()->request('POST', '/api/admin/marketing', ['json' => self::VALID]);

        $this->assertResponseStatusCodeSame(201);
        $campaign = $this->freshEm()->getRepository(Marketing::class)->findOneBy(['campaign_name' => 'Sponsor fictif']);
        $this->assertSame('https://cdn.exemple.be/sponsor-mobile.jpg', $campaign->getSmartphoneFormat());
        $this->assertNull($campaign->getTabletPortraitFormat());
        $this->assertSame('2026-12-31', $campaign->getEndDate()->format('Y-m-d'));
    }

    #[DataProvider('invalidCampaigns')]
    public function testInvalidCampaignIsRefusedWithReadableMessage(array $override, string $message): void
    {
        $this->admin()->request('POST', '/api/admin/marketing', ['json' => array_merge(self::VALID, $override)]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertJsonContains(['message' => $message]);
        $this->assertSame(0, $this->freshEm()->getRepository(Marketing::class)->count([]));
    }

    public static function invalidCampaigns(): iterable
    {
        yield 'nom manquant' => [['campaign_name' => '  '], 'Le nom de la campagne est obligatoire.'];
        yield 'statut inconnu' => [['status' => 'paused'], 'Le statut doit être « active » ou « inactive ».'];
        yield 'date de début absente' => [['start_date' => ''], 'La date de début est obligatoire (AAAA-MM-JJ).'];
        yield 'fin avant début' => [['end_date' => '2026-09-01'], 'La date de fin doit suivre la date de début.'];
        yield 'durée hors limites' => [['duration' => 60], "La durée d'affichage doit être un nombre entier de 1 à 15 secondes."];
        yield 'redirection non http' => [['redirect_url' => 'javascript:alert(1)'], "L'URL de redirection doit commencer par https:// ou http:// (500 caractères maximum)."];
        yield 'image collée (data:)' => [
            ['smartphone_format' => 'data:image/jpeg;base64,' . str_repeat('A', 600)],
            "Visuel smartphone : indiquez l'URL https:// d'une image hébergée (500 caractères maximum, pas d'image collée).",
        ];
        yield 'aucun visuel' => [['smartphone_format' => '', 'large_screen_format' => null], "Indiquez au moins un visuel (URL d'image)."];
    }

    public function testEndDateCanBeCleared(): void
    {
        $campaign = $this->createCampaign();

        $this->admin()->request('PUT', '/api/admin/marketing/' . $campaign->getId(), ['json' => array_merge(self::VALID, ['end_date' => ''])]);

        $this->assertResponseIsSuccessful();
        $this->assertNull($this->freshEm()->find(Marketing::class, $campaign->getId())->getEndDate());
    }

    public function testCampaignAlreadyDisplayedCanBeDeleted(): void
    {
        $campaign = $this->createCampaign();
        $view = (new MarketingView())->setMarketing($campaign)->setViewedAt(new \DateTime());
        $this->em->persist($view);
        $this->em->flush();

        $this->admin()->request('DELETE', '/api/admin/marketing/' . $campaign->getId());

        $this->assertResponseIsSuccessful();
        $em = $this->freshEm();
        $this->assertSame(0, $em->getRepository(Marketing::class)->count([]));
        $this->assertSame(0, $em->getRepository(MarketingView::class)->count([]));
    }

    public function testStatusMustBeKnown(): void
    {
        $campaign = $this->createCampaign();

        $this->admin()->request('PUT', '/api/admin/marketing/' . $campaign->getId() . '/status', ['json' => ['status' => 'whatever']]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('inactive', $this->freshEm()->find(Marketing::class, $campaign->getId())->getStatus());
    }

    public function testNonAdminIsRefused(): void
    {
        $user = $this->createUser('alice@test.be');

        $this->clientFor($user)->request('POST', '/api/admin/marketing', ['json' => self::VALID]);

        $this->assertResponseStatusCodeSame(403);
        $this->assertSame(0, $this->freshEm()->getRepository(Marketing::class)->count([]));
    }

    public function testActiveCampaignIsPublic(): void
    {
        $this->admin()->request('POST', '/api/admin/marketing', ['json' => array_merge(self::VALID, ['start_date' => (new \DateTime('-1 day'))->format('Y-m-d'), 'end_date' => null])]);

        $response = static::createClient()->request('GET', '/api/marketing/active');

        $this->assertResponseIsSuccessful();
        $this->assertSame('https://cdn.exemple.be/sponsor-mobile.jpg', $response->toArray()['formats']['smartphone']);
    }

    public function testCampaignIsShownUntilTheEndOfItsLastDay(): void
    {
        $today = (new \DateTime())->format('Y-m-d');
        $this->admin()->request('POST', '/api/admin/marketing', ['json' => array_merge(self::VALID, ['start_date' => '2026-01-01', 'end_date' => $today])]);

        static::createClient()->request('GET', '/api/marketing/active');

        $this->assertResponseIsSuccessful();
    }

    public function testExpiredOrInactiveCampaignIsNotShown(): void
    {
        $yesterday = (new \DateTime('-1 day'))->format('Y-m-d');
        $admin = $this->admin();
        $admin->request('POST', '/api/admin/marketing', ['json' => array_merge(self::VALID, ['start_date' => '2026-01-01', 'end_date' => $yesterday])]);
        $admin->request('POST', '/api/admin/marketing', ['json' => array_merge(self::VALID, ['status' => 'inactive', 'start_date' => '2026-01-01', 'end_date' => null])]);

        static::createClient()->request('GET', '/api/marketing/active');

        $this->assertResponseStatusCodeSame(404);
    }

    private function admin()
    {
        return $this->clientFor($this->createUser('admin@test.be', ['ROLE_ADMIN']));
    }

    private function createCampaign(): Marketing
    {
        $campaign = (new Marketing())
            ->setCampaignName('Campagne existante')
            ->setStatus('inactive')
            ->setStartDate(new \DateTime('2026-10-01'))
            ->setEndDate(new \DateTime('2026-11-30'))
            ->setLargeScreenFormat('https://cdn.exemple.be/a.jpg');
        $this->em->persist($campaign);
        $this->em->flush();

        return $campaign;
    }

    private function freshEm(): EntityManagerInterface
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();

        return $em;
    }
}
