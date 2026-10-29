<?php

namespace App\Tests\Api;

use App\Entity\Marketing;
use App\Entity\MarketingImage;
use App\Entity\MarketingView;
use App\Entity\PartnerLogo;
use App\Service\MarketingImageStore;
use App\Tests\ApiTestBase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Espaces sponsors (docs/SPONSORS.md) : campagnes par emplacement, exclusivité, rotation,
 * mesure des impressions et des clics, statistiques et export CSV.
 */
class SponsorSpacesTest extends ApiTestBase
{
    use ClockSensitiveTrait;

    private const CARD = [
        'campaign_name' => 'Implants – automne',
        'sponsor_name' => 'OrthoLab',
        'offer' => 'premium',
        'title' => 'La nouvelle prothèse de genou',
        'description' => 'Découvrez la gamme 2027.',
        'status' => 'active',
        'start_date' => '2026-01-01',
        'end_date' => null,
        'redirect_url' => 'https://ortholab.example/genou',
        'show_cta' => true,
        'banner_desktop' => 'https://cdn.example/desktop.webp',
        'banner_mobile' => 'https://cdn.example/mobile.webp',
        'placements' => ['dashboard_desktop', 'dashboard_mobile'],
        'priority' => 5,
        'exclusive' => false,
    ];

    private const VISITOR = 'visiteur-0123456789abcdef';

    public function testLegacyCampaignKeepsItsFullScreenPlacement(): void
    {
        // Ancienne administration : aucun champ des espaces sponsors.
        $this->admin()->request('POST', '/api/admin/marketing', ['json' => [
            'campaign_name' => 'Ancienne campagne', 'status' => 'active', 'start_date' => '2026-01-01',
            'large_screen_format' => 'https://cdn.example/large.jpg',
        ]]);
        $this->assertResponseStatusCodeSame(201);

        $campaign = $this->freshEm()->getRepository(Marketing::class)->findOneBy([]);
        $this->assertSame(['login_interstitial'], $campaign->getPlacements());
        $this->assertSame(5, $campaign->getPriority());
        $this->assertFalse($campaign->isExclusive());
        static::createClient()->request('GET', '/api/marketing/active');
        $this->assertResponseIsSuccessful();
        $this->assertSame(['login' => [], 'dashboard_desktop' => [], 'dashboard_mobile' => []], $this->slots());
    }

    public function testCardCampaignIsServedOnItsPlacementsOnlyWithPublicFieldsOnly(): void
    {
        $this->create(self::CARD);

        $slots = $this->slots();

        $this->assertSame([], $slots['login']);
        $this->assertCount(1, $slots['dashboard_desktop']);
        $this->assertSame($slots['dashboard_desktop'], $slots['dashboard_mobile']);
        $served = $slots['dashboard_desktop'][0];
        $this->assertSame(['id', 'sponsor', 'title', 'description', 'logo', 'imageDesktop', 'imageMobile', 'url', 'cta'], array_keys($served));
        $this->assertSame('OrthoLab', $served['sponsor']);
        $this->assertTrue($served['cta']);
        // Carte uniquement : la campagne n'apparaît pas en plein écran à la connexion.
        static::createClient()->request('GET', '/api/marketing/active');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testInactiveFutureOrExpiredCampaignsAreNotServed(): void
    {
        $this->create(['campaign_name' => 'Inactive', 'status' => 'inactive'] + self::CARD);
        $this->create(['campaign_name' => 'Future', 'start_date' => (new \DateTime('+5 days'))->format('Y-m-d')] + self::CARD);
        $this->create(['campaign_name' => 'Expirée', 'end_date' => (new \DateTime('-2 days'))->format('Y-m-d')] + self::CARD);

        $this->assertSame([], $this->slots()['dashboard_desktop']);
    }

    public function testRotationIsLimitedToThreeCampaignsOrderedByPriority(): void
    {
        foreach ([2, 9, 5, 7, 1] as $i => $priority) {
            $this->create(['campaign_name' => "Campagne $i", 'priority' => $priority] + self::CARD);
        }

        $served = $this->slots()['dashboard_desktop'];

        $this->assertCount(3, $served);
        $this->assertCount(3, array_unique(array_column($served, 'id')));
        $priorities = array_map(fn (array $c) => $this->freshEm()->find(Marketing::class, $c['id'])->getPriority(), $served);
        $sorted = $priorities;
        rsort($sorted);
        $this->assertSame($sorted, $priorities);
    }

    public function testExclusiveCampaignIsShownAlone(): void
    {
        $this->create(['campaign_name' => 'Secondaire A', 'priority' => 9] + self::CARD);
        $this->create(['campaign_name' => 'Premium exclusif', 'exclusive' => true, 'priority' => 3, 'placements' => ['dashboard_desktop']] + self::CARD);

        $slots = $this->slots();

        $this->assertCount(1, $slots['dashboard_desktop']);
        $this->assertSame('Premium exclusif', $this->freshEm()->find(Marketing::class, $slots['dashboard_desktop'][0]['id'])->getCampaignName());
        // Exclusivité limitée à ses emplacements : le mobile garde les autres campagnes.
        $this->assertCount(1, $slots['dashboard_mobile']);
        $this->assertNotSame($slots['dashboard_desktop'][0]['id'], $slots['dashboard_mobile'][0]['id']);
    }

    public function testOverlappingExclusivitiesOnTheSamePlacementAreRefused(): void
    {
        $this->create(['campaign_name' => 'Premium A', 'exclusive' => true, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'] + self::CARD);

        $this->admin()->request('POST', '/api/admin/marketing', ['json' => ['campaign_name' => 'Premium B', 'exclusive' => true, 'start_date' => '2026-06-01', 'placements' => ['dashboard_mobile']] + self::CARD]);
        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('« Premium A » est déjà exclusive', json_decode(static::getClient()->getResponse()->getContent(), true)['message']);

        // Périodes successives, ou autre emplacement : acceptées.
        $this->create(['campaign_name' => 'Premium 2027', 'exclusive' => true, 'start_date' => '2027-01-01'] + self::CARD);
        $this->create(['campaign_name' => 'Connexion exclusive', 'exclusive' => true, 'placements' => ['login']] + self::CARD);
    }

    #[DataProvider('invalidCampaigns')]
    public function testInvalidFieldsAreRefusedWithReadableMessage(array $override, string $message): void
    {
        $this->admin()->request('POST', '/api/admin/marketing', ['json' => array_merge(self::CARD, $override)]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertJsonContains(['message' => $message]);
    }

    public static function invalidCampaigns(): iterable
    {
        yield 'aucun emplacement' => [['placements' => []], "Choisissez au moins un emplacement d'affichage."];
        yield 'emplacement inconnu' => [['placements' => ['popup']], "Choisissez au moins un emplacement d'affichage."];
        yield 'carte sans bannière' => [['banner_desktop' => null, 'banner_mobile' => ''], 'Importez au moins une bannière (ordinateur ou mobile).'];
        yield 'plein écran sans visuel' => [['placements' => ['login_interstitial']], 'Importez au moins un visuel (image principale).'];
        yield 'priorité hors bornes' => [['priority' => 11], 'La priorité doit être un nombre entier de 1 à 10.'];
        yield 'bouton sans site' => [['redirect_url' => ''], "Indiquez le site de l'annonceur pour afficher le bouton « Découvrir le partenaire »."];
        yield 'offre inconnue' => [['offer' => 'gold'], 'Offre inconnue.'];
        yield 'description trop longue' => [['description' => str_repeat('a', 281)], 'Description : 280 caractères au maximum.'];
        yield 'logo inconnu' => [['logo' => str_repeat('a', 64) . '.png'], 'Logo introuvable : envoyez-le de nouveau.'];
        yield 'bannière collée' => [['banner_mobile' => 'data:image/png;base64,AAAA'], "Bannière mobile : importez une image PNG, JPEG ou WebP (ou indiquez l'URL https:// d'une image hébergée ; pas d'image collée)."];
    }

    public function testCampaignLogoReusesPartnerLogos(): void
    {
        $logo = new PartnerLogo('logo-png-fictif', PartnerLogo::MIME_PNG, 512, 128);
        $this->em->persist($logo);
        $this->em->flush();

        $this->create(['logo' => $logo->getFileName()] + self::CARD);

        $this->assertSame($logo->getFileName(), $this->slots()['dashboard_desktop'][0]['logo']);
    }

    public function testBannerVisualsAreKeptByThePurgeAndReleasedWhenReplaced(): void
    {
        $banner = new MarketingImage('webp-banniere', 1600, 900, 1000);
        $other = new MarketingImage('webp-autre', 1600, 900, 1000);
        $this->em->persist($banner);
        $this->em->persist($other);
        $this->em->flush();
        $path = MarketingImage::PATH_PREFIX . $banner->getSha256() . '.webp';
        $id = $this->create(['banner_desktop' => $path, 'banner_mobile' => null] + self::CARD);
        // Imports de plus de 24 h : purgés s'ils ne servent à aucune campagne.
        $this->freshEm()->getConnection()->executeStatement("UPDATE marketing_image SET created_at = '2026-01-01 00:00:00'");

        static::getContainer()->get(MarketingImageStore::class)->purgeOrphans();
        $this->assertNotNull($this->freshEm()->getRepository(MarketingImage::class)->findByPath($path), 'bannière utilisée conservée');
        $this->assertSame(1, $this->freshEm()->getRepository(MarketingImage::class)->count([]));

        $this->admin()->request('PUT', '/api/admin/marketing/' . $id, ['json' => ['banner_desktop' => 'https://cdn.example/new.webp'] + self::CARD]);
        $this->assertResponseIsSuccessful();
        $this->assertNull($this->freshEm()->getRepository(MarketingImage::class)->findByPath($path), 'bannière remplacée libérée');
    }

    public function testReadingSlotsOrActiveCampaignCountsNothing(): void
    {
        $this->create(self::CARD + ['large_screen_format' => 'https://cdn.example/large.jpg']);
        $this->slots();
        $this->slots();

        $this->assertSame(0, $this->freshEm()->getRepository(MarketingView::class)->count([]));
    }

    public function testImpressionIsCountedOncePerVisitorWithinThirtyMinutes(): void
    {
        $id = $this->create(self::CARD);

        $this->assertTrue($this->event($id, 'dashboard_mobile', 'impression'));
        $this->assertFalse($this->event($id, 'dashboard_mobile', 'impression'), 'doublon ignoré');
        $this->assertTrue($this->event($id, 'dashboard_desktop', 'impression'), 'autre emplacement');
        $this->assertTrue($this->event($id, 'dashboard_mobile', 'impression', 'autre-visiteur-0123456789'), 'autre visiteur');
        $this->assertTrue($this->event($id, 'dashboard_mobile', 'click'));
        $this->assertFalse($this->event($id, 'dashboard_mobile', 'click'), 'double clic ignoré');

        // Fenêtre écoulée : de nouveau comptée.
        $this->freshEm()->getConnection()->executeStatement("UPDATE marketing_view SET viewed_at = '2026-01-01 08:00:00'");
        $this->assertTrue($this->event($id, 'dashboard_mobile', 'impression'));
    }

    /** Heure de Belgique simulée (l'empreinte du visiteur change à minuit, heure belge). */
    private static function at(string $time): void
    {
        self::mockTime(new \DateTimeImmutable($time, new \DateTimeZone('Europe/Brussels')));
    }

    public function testDuplicatesAreIgnoredForThirtyMinutesAcrossMidnight(): void
    {
        self::at('2026-10-28 12:00:00');
        $id = $this->create(self::CARD);

        self::at('2026-10-28 23:50:00');
        $this->assertTrue($this->event($id, 'dashboard_mobile', 'impression'));
        $this->assertTrue($this->event($id, 'dashboard_mobile', 'click'));
        // Le lendemain, 20 minutes plus tard : même visiteur, autre empreinte du jour, toujours un doublon.
        self::at('2026-10-29 00:10:00');
        $this->assertFalse($this->event($id, 'dashboard_mobile', 'impression'), 'impression en double après minuit');
        $this->assertFalse($this->event($id, 'dashboard_mobile', 'click'), 'clic en double après minuit');
        $this->assertTrue($this->event($id, 'dashboard_mobile', 'impression', 'autre-visiteur-0123456789'), 'autre visiteur compté');
        // Plus de 30 minutes après le premier événement : de nouveau compté.
        self::at('2026-10-29 00:21:00');
        $this->assertTrue($this->event($id, 'dashboard_mobile', 'impression'));
        $this->assertTrue($this->event($id, 'dashboard_mobile', 'click'));

        $this->assertSame(5, $this->freshEm()->getRepository(MarketingView::class)->count([]));
    }

    public function testConnectedUserIsDeduplicatedAcrossMidnight(): void
    {
        self::at('2026-10-28 12:00:00');
        $id = $this->create(self::CARD);
        $client = $this->clientFor($this->createUser('nuit@test.be'));
        $send = fn (string $visitor) => $client->request('POST', '/api/marketing/events', ['json' => ['campaign' => $id, 'placement' => 'dashboard_desktop', 'type' => 'impression', 'visitor' => $visitor]])->toArray()['recorded'];

        self::at('2026-10-28 23:59:30');
        $this->assertTrue($send('onglet-a-000000000001'));
        self::at('2026-10-29 00:00:30');
        $this->assertFalse($send('onglet-b-000000000002'));
    }

    public function testEventsForAPlacementNotServedAreIgnored(): void
    {
        $id = $this->create(self::CARD);
        $inactive = $this->create(['campaign_name' => 'Inactive', 'status' => 'inactive'] + self::CARD);

        $this->assertFalse($this->event($id, 'login', 'impression'));
        $this->assertFalse($this->event($inactive, 'dashboard_mobile', 'impression'));
        $this->assertFalse($this->event(999999, 'dashboard_mobile', 'impression'));
        $this->assertSame(0, $this->freshEm()->getRepository(MarketingView::class)->count([]));
    }

    public function testInvalidEventsAreRefused(): void
    {
        $id = $this->create(self::CARD);
        $client = static::createClient();
        foreach ([
            ['campaign' => $id, 'placement' => 'dashboard_mobile', 'type' => 'view', 'visitor' => self::VISITOR],
            ['campaign' => $id, 'placement' => 'sidebar', 'type' => 'impression', 'visitor' => self::VISITOR],
            ['campaign' => $id, 'placement' => 'dashboard_mobile', 'type' => 'impression', 'visitor' => 'court'],
            ['campaign' => $id, 'placement' => 'dashboard_mobile', 'type' => 'impression'],
        ] as $payload) {
            $client->request('POST', '/api/marketing/events', ['json' => $payload]);
            $this->assertResponseStatusCodeSame(400);
        }
    }

    public function testConnectedUserIsCountedOnceWhateverTheBrowserIdAndNeverStoredInClear(): void
    {
        $id = $this->create(self::CARD);
        $user = $this->createUser('interne@test.be');
        $client = $this->clientFor($user);

        $client->request('POST', '/api/marketing/events', ['json' => ['campaign' => $id, 'placement' => 'dashboard_mobile', 'type' => 'impression', 'visitor' => self::VISITOR]]);
        $this->assertTrue($client->getResponse()->toArray()['recorded']);
        $client->request('POST', '/api/marketing/events', ['json' => ['campaign' => $id, 'placement' => 'dashboard_mobile', 'type' => 'impression', 'visitor' => 'nouvel-onglet-0123456789']]);
        $this->assertFalse($client->getResponse()->toArray()['recorded']);

        $hash = $this->freshEm()->getRepository(MarketingView::class)->findOneBy([])->getViewerHash();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
        $this->assertNotSame(hash('sha256', 'u:' . $user->getId()), $hash);
    }

    public function testViewerFingerprintsAreErasedAfterADay(): void
    {
        $id = $this->create(self::CARD);
        $this->event($id, 'dashboard_mobile', 'impression');
        $this->freshEm()->getConnection()->executeStatement("UPDATE marketing_view SET viewed_at = '2026-01-01 08:00:00'");

        $this->event($id, 'dashboard_desktop', 'impression');

        $hashes = $this->freshEm()->getConnection()->fetchFirstColumn('SELECT viewer_hash FROM marketing_view ORDER BY viewed_at');
        $this->assertNull($hashes[0]);
        $this->assertNotNull($hashes[1]);
    }

    public function testStatisticsReportImpressionsClicksAndCtrByPlacementAndDay(): void
    {
        $id = $this->create(self::CARD);
        $other = $this->create(['campaign_name' => 'Formation', 'sponsor_name' => 'Académie', 'offer' => 'secondary'] + self::CARD);
        foreach (['v1-0123456789abcdef', 'v2-0123456789abcdef', 'v3-0123456789abcdef', 'v4-0123456789abcdef'] as $visitor) {
            $this->event($id, 'dashboard_mobile', 'impression', $visitor);
        }
        $this->event($id, 'dashboard_mobile', 'click', 'v1-0123456789abcdef');
        $this->event($id, 'dashboard_desktop', 'impression');
        $this->event($other, 'dashboard_desktop', 'impression');
        // Vue historique (ancien comptage) : jamais additionnée aux impressions.
        $campaign = $this->em->find(Marketing::class, $id);
        $this->em->persist((new MarketingView())->setMarketing($campaign)->setViewedAt(new \DateTime())->setKind(MarketingView::KIND_LEGACY));
        $this->em->flush();

        $report = $this->admin()->request('GET', '/api/admin/marketing-stats')->toArray();

        // CTR en % (JSON : 20.0 arrive comme 20, d'où assertEquals).
        $this->assertEquals(['impressions' => 6, 'clicks' => 1, 'ctr' => 16.67], $report['totals']);
        $first = $report['campaigns'][0];
        $this->assertEquals([$id, 5, 1, 20.0, 1], [$first['id'], $first['impressions'], $first['clicks'], $first['ctr'], $first['legacyViews']]);
        $byPlacement = array_column($first['placements'], null, 'placement');
        $this->assertSame(4, $byPlacement['dashboard_mobile']['impressions']);
        $this->assertEquals(25.0, $byPlacement['dashboard_mobile']['ctr']);
        $this->assertSame(1, $byPlacement['dashboard_desktop']['impressions']);
        $this->assertEquals(0, $byPlacement['dashboard_desktop']['ctr']);
        $this->assertSame(['OrthoLab', 'Académie'], array_column($report['sponsors'], 'sponsor'));
        $this->assertCount(30, $report['daily']);
        $this->assertSame(6, end($report['daily'])['impressions']);

        $bySponsor = $this->admin()->request('GET', '/api/admin/marketing-stats?sponsor=Acad%C3%A9mie')->toArray();
        $this->assertSame(1, $bySponsor['totals']['impressions']);
        $this->assertCount(1, $bySponsor['campaigns']);

        $list = $this->admin()->request('GET', '/api/admin/marketing')->toArray();
        $listed = array_column($list, null, 'id')[$id];
        $this->assertEquals([5, 1, 20.0], [$listed['impressions'], $listed['tracked_clicks'], $listed['ctr']]);
    }

    public function testCsvExportIsAnAggregatedReportWithoutPersonalData(): void
    {
        $id = $this->create(['sponsor_name' => '=HYPERLINK("x")'] + self::CARD);
        $this->event($id, 'dashboard_mobile', 'impression');
        $this->event($id, 'dashboard_mobile', 'click');

        $response = $this->admin()->request('GET', '/api/admin/marketing-stats.csv');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('attachment; filename="medclick-publicite-', $response->getHeaders()['content-disposition'][0]);
        $lines = explode("\n", trim(substr($response->getContent(), 3)));
        $this->assertSame('Date;Annonceur;Campagne;Emplacement;Impressions;Clics;"CTR (%)"', $lines[0]);
        $this->assertStringContainsString(";\"'=HYPERLINK(\"\"x\"\")\";", $lines[1], 'formule neutralisée');
        $this->assertStringEndsWith(';"Tableau de bord (mobile)";1;1;100,00', $lines[1]);
        $this->assertStringStartsWith('"Total ', $lines[2]);
        $this->assertStringNotContainsString('@', $response->getContent());
    }

    public function testInvalidPeriodIsRefused(): void
    {
        $admin = $this->admin();
        $admin->request('GET', '/api/admin/marketing-stats?from=2026-05-01&to=2026-04-01');
        $this->assertResponseStatusCodeSame(400);
        $admin->request('GET', '/api/admin/marketing-stats?from=2024-01-01&to=2026-01-01');
        $this->assertResponseStatusCodeSame(400);
        $admin->request('GET', '/api/admin/marketing-stats?from=01/01/2026');
        $this->assertResponseStatusCodeSame(400);
    }

    public function testStatisticsAreReservedToAdmins(): void
    {
        $client = $this->clientFor($this->createUser('alice@test.be'));
        $client->request('GET', '/api/admin/marketing-stats');
        $this->assertResponseStatusCodeSame(403);
        $client->request('GET', '/api/admin/marketing-stats.csv');
        $this->assertResponseStatusCodeSame(403);
        static::createClient()->request('GET', '/api/admin/marketing-stats');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testPublicRoutesNeedNoSession(): void
    {
        $id = $this->create(self::CARD);
        static::createClient()->request('GET', '/api/marketing/slots');
        $this->assertResponseIsSuccessful();
        static::createClient()->request('POST', '/api/marketing/events', ['json' => ['campaign' => $id, 'placement' => 'dashboard_mobile', 'type' => 'impression', 'visitor' => self::VISITOR]]);
        $this->assertResponseStatusCodeSame(202);
    }

    private function create(array $campaign): int
    {
        $this->admin()->request('POST', '/api/admin/marketing', ['json' => $campaign]);
        $this->assertResponseStatusCodeSame(201);

        return $this->freshEm()->getRepository(Marketing::class)->findOneBy(['campaign_name' => $campaign['campaign_name']], ['id' => 'DESC'])->getId();
    }

    private function slots(): array
    {
        return static::createClient()->request('GET', '/api/marketing/slots')->toArray();
    }

    private function event(int $campaign, string $placement, string $type, string $visitor = self::VISITOR): bool
    {
        $response = static::createClient()->request('POST', '/api/marketing/events', ['json' => compact('campaign', 'placement', 'type', 'visitor')]);
        $this->assertSame(202, $response->getStatusCode());

        return $response->toArray()['recorded'];
    }

    private ?\ApiPlatform\Symfony\Bundle\Test\Client $adminClient = null;

    private function admin(): \ApiPlatform\Symfony\Bundle\Test\Client
    {
        if ($this->adminClient === null) {
            $this->adminClient = $this->clientFor($this->createUser('admin@test.be', ['ROLE_ADMIN']));
        }

        return $this->adminClient;
    }

    private function freshEm(): EntityManagerInterface
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();

        return $em;
    }
}
