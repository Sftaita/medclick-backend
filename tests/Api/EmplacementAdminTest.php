<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Marketing;
use App\Entity\Partner;
use App\Entity\PartnerLogo;
use App\Entity\PartnerPlacementAssignment;
use App\Tests\ApiTestBase;

/**
 * Administration « Publicités & partenaires » par emplacement (docs/PUBLICITES-PARTENAIRES.md) :
 * emplacements de connexion indépendants, brouillons sans effet public, publication, ordre,
 * capacité et exclusivité, carrousels ordinateur et mobile, publicités et partenaires affichés
 * ensemble, entreprises, statistiques séparées par emplacement, droits.
 */
final class EmplacementAdminTest extends ApiTestBase
{
    private const VISITOR = 'visiteur-0123456789abcdef';

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->clientFor($this->createUser('admin-emplacements@test.be', ['ROLE_ADMIN']));
    }

    public function testTheEightRealEmplacementsAreListedByScreen(): void
    {
        $data = $this->admin->request('GET', '/api/admin/emplacements')->toArray();

        $this->assertSame(
            ['login', 'after_login', 'dashboard_carousel', 'dashboard_partner', 'sidebar', 'mobile_menu', 'public_home', 'partners_page'],
            array_column($data['emplacements'], 'key'),
        );
        $labels = array_column($data['emplacements'], 'label', 'key');
        $this->assertSame("Page d'identification — Publicité", $labels['login']);
        $this->assertSame('Après connexion — Publicité plein écran', $labels['after_login']);
        $this->assertSame('Tableau de bord — Carrousel publicitaire', $labels['dashboard_carousel']);
        $carousel = $data['emplacements'][2];
        $this->assertSame(3, $carousel['capacity']);
        $this->assertSame(['desktop', 'mobile'], $carousel['supports']);
        $this->assertSame(1, $data['emplacements'][1]['capacity']);
        $this->assertTrue($data['emplacements'][7]['shared']);
    }

    public function testNonAdminIsRefused(): void
    {
        $user = $this->clientFor($this->createUser('resident@test.be'));
        $user->request('GET', '/api/admin/emplacements');
        $this->assertSame(403, $user->getResponse()->getStatusCode());
        $user->request('POST', '/api/admin/emplacements/login/publish');
        $this->assertSame(403, $user->getResponse()->getStatusCode());
        $anonymous = static::createClient();
        $anonymous->request('GET', '/api/admin/companies');
        $this->assertSame(401, $anonymous->getResponse()->getStatusCode());
    }

    // ------------------------------------------------------------- Brouillons et publication

    public function testADraftNeverReachesThePublicUntilPublished(): void
    {
        $medvue = $this->firm('MedVue');
        $detail = $this->create('dashboard_carousel', $this->ad($medvue));
        $this->assertSame(201, $this->code());
        $content = $detail['contents'][0];
        $this->assertSame('draft', $content['state']);
        $this->assertSame(1, $detail['pendingCount']);

        // Public : rien. Aperçu « Mes modifications » : la publicité. « En ligne » : rien.
        $this->assertSame([], $this->slots()['dashboard_desktop']);
        $this->assertSame([], $detail['preview']['live']['desktop']);
        $this->assertSame('MedVue', $detail['preview']['draft']['desktop'][0]['sponsor']);

        $published = $this->publish('dashboard_carousel');
        $this->assertSame(1, $published['published']);
        $this->assertSame('live', $published['contents'][0]['state']);
        $slots = $this->slots();
        $this->assertSame([$content['id']], array_column($slots['dashboard_desktop'], 'id'));
        $this->assertSame([$content['id']], array_column($slots['dashboard_mobile'], 'id'));
    }

    public function testEditingAPublishedAdIsPendingUntilPublished(): void
    {
        $medvue = $this->firm('MedVue');
        $id = $this->create('dashboard_carousel', $this->ad($medvue, ['url' => 'https://medvue.example/ancien']))['contents'][0]['id'];
        $this->publish('dashboard_carousel');

        $detail = $this->update('dashboard_carousel', $id, $this->ad($medvue, ['url' => 'https://medvue.example/nouveau']));
        $this->assertTrue($detail['contents'][0]['hasPending']);
        $this->assertSame('https://medvue.example/nouveau', $detail['contents'][0]['url']);
        $this->assertSame('https://medvue.example/ancien', $detail['contents'][0]['published']['url']);
        $this->assertSame('https://medvue.example/ancien', $this->slots()['dashboard_desktop'][0]['url']);
        $this->assertSame('https://medvue.example/nouveau', $detail['preview']['draft']['desktop'][0]['url']);
        $this->assertSame('https://medvue.example/ancien', $detail['preview']['live']['desktop'][0]['url']);

        // Abandon : rien n'a changé ; nouvelle modification puis publication : en ligne.
        $this->admin->request('POST', "/api/admin/emplacements/dashboard_carousel/contents/ad/$id/discard");
        $this->assertFalse($this->detail('dashboard_carousel')['contents'][0]['hasPending']);
        $this->update('dashboard_carousel', $id, $this->ad($medvue, ['url' => 'https://medvue.example/nouveau']));
        $this->publish('dashboard_carousel');
        $this->assertSame('https://medvue.example/nouveau', $this->slots()['dashboard_desktop'][0]['url']);
    }

    public function testDeactivationIsImmediateAndKeepsHistory(): void
    {
        $id = $this->create('login', $this->ad($this->firm('MedVue')))['contents'][0]['id'];
        $this->publish('login');
        $this->assertCount(1, $this->slots()['login']);

        $detail = $this->admin->request('POST', "/api/admin/emplacements/login/contents/ad/$id/deactivate")->toArray();
        $this->assertSame('inactive', $detail['contents'][0]['state']);
        $this->assertSame([], $this->slots()['login']);
        // Publiée : pas de suppression (historique conservé).
        $this->admin->request('DELETE', "/api/admin/emplacements/login/contents/ad/$id");
        $this->assertSame(400, $this->code());
        // Réactivation : en attente, puis publiée.
        $this->admin->request('POST', "/api/admin/emplacements/login/contents/ad/$id/reactivate");
        $this->assertSame([], $this->slots()['login']);
        $this->publish('login');
        $this->assertCount(1, $this->slots()['login']);
    }

    // ------------------------------------------------------------- Connexion : deux emplacements indépendants

    public function testLoginPageAndAfterLoginAreIndependent(): void
    {
        $medvue = $this->firm('MedVue');
        $bosi = $this->firm('BOSI');
        $this->create('login', $this->ad($medvue));
        $this->publish('login');
        $this->create('after_login', $this->ad($bosi, ['duration' => 8]));
        $this->publish('after_login');

        $this->assertSame('MedVue', $this->slots()['login'][0]['sponsor']);
        $active = static::createClient()->request('GET', '/api/marketing/active')->toArray();
        $this->assertSame('BOSI', $active['sponsor']);
        $this->assertSame(8, $active['duration']);
        $this->assertSame('daily', $active['frequency']);
        // Visuels du plein écran : ordinateur et mobile répartis sur les paliers d'écran.
        $this->assertSame('https://cdn.example/desktop.png', $active['formats']['large_screen']);
        $this->assertSame('https://cdn.example/mobile.png', $active['formats']['smartphone']);

        // Plein écran désactivé : la page d'identification continue ; accès direct (404).
        $this->settings('after_login', ['enabled' => false]);
        $this->assertSame(404, $this->lastStatus('/api/marketing/active'));
        $this->assertSame('MedVue', $this->slots()['login'][0]['sponsor']);

        // Et l'inverse.
        $this->settings('after_login', ['enabled' => true, 'frequency' => 'every_login']);
        $this->settings('login', ['enabled' => false]);
        $this->assertSame([], $this->slots()['login']);
        $this->assertSame('every_login', static::createClient()->request('GET', '/api/marketing/active')->toArray()['frequency']);
    }

    public function testFrequencyOnlyAppliesToTheFullScreenAd(): void
    {
        $this->admin->request('PUT', '/api/admin/emplacements/login/settings', ['json' => ['frequency' => 'daily']]);
        $this->assertSame(400, $this->code());
        $this->admin->request('PUT', '/api/admin/emplacements/after_login/settings', ['json' => ['frequency' => 'hourly']]);
        $this->assertSame(400, $this->code());
    }

    public function testLoginPartnerInsertIsManagedInTheLoginEmplacement(): void
    {
        $bosi = $this->firm('BOSI');
        $detail = $this->create('login', ['type' => 'partner', 'companyId' => $bosi->getId(), 'startsOn' => $this->day()]);
        $this->assertSame('fallback', $detail['contents'][0]['role']);
        $this->assertNull($this->partnerPlacements()['login_fallback']);
        $this->publish('login');
        $this->assertSame('BOSI', $this->partnerPlacements()['login_fallback']['name']);
        // Emplacement désactivé : ni publicité ni encart.
        $this->settings('login', ['enabled' => false]);
        $this->assertNull($this->partnerPlacements()['login_fallback']);
    }

    // ------------------------------------------------------------- Carrousel : ordinateur, mobile, ordre, capacité

    public function testDesktopAndMobileVisualsAreSeparateAndAReuseIsExplicit(): void
    {
        $medvue = $this->firm('MedVue');
        // Mobile seulement.
        $this->create('dashboard_carousel', $this->ad($medvue, ['supports' => ['mobile'], 'imageDesktop' => null]));
        // Même visuel sur les deux supports.
        $this->create('dashboard_carousel', $this->ad($medvue, ['imageMobile' => 'https://cdn.example/desktop.png']));
        // Visuel manquant pour un support coché : refusé.
        $this->admin->request('POST', '/api/admin/emplacements/dashboard_carousel/contents', ['json' => $this->ad($medvue, ['imageMobile' => null])]);
        $this->assertSame(400, $this->code());
        $this->publish('dashboard_carousel');

        $slots = $this->slots();
        $this->assertCount(1, $slots['dashboard_desktop']);
        $this->assertCount(2, $slots['dashboard_mobile']);
        $this->assertSame('https://cdn.example/desktop.png', $slots['dashboard_desktop'][0]['imageMobile']);
    }

    public function testReorderIsPendingThenPublished(): void
    {
        $medvue = $this->firm('MedVue');
        $ids = [];
        foreach (['A', 'B', 'C'] as $name) {
            $ids[] = $this->create('dashboard_carousel', $this->ad($medvue, ['name' => $name]))['contents'][count($ids)]['id'];
        }
        $this->publish('dashboard_carousel');
        $this->assertSame(['A', 'B', 'C'], $this->publicNames('dashboard_desktop'));

        $detail = $this->admin->request('PUT', '/api/admin/emplacements/dashboard_carousel/order', ['json' => ['ids' => array_reverse($ids)]])->toArray();
        $this->assertTrue($detail['orderChanged']);
        $this->assertSame(array_reverse($ids), array_column($detail['preview']['draft']['desktop'], 'id'));
        $this->assertSame(['C', 'B', 'A'], $this->names($detail['contents']));
        $this->assertSame(['A', 'B', 'C'], $this->publicNames('dashboard_desktop'));
        $this->publish('dashboard_carousel');
        $this->assertSame(['C', 'B', 'A'], $this->publicNames('dashboard_desktop'));
    }

    public function testCapacityAndExclusivityConflictsBlockPublication(): void
    {
        $medvue = $this->firm('MedVue');
        foreach (['A', 'B', 'C'] as $name) {
            $this->create('dashboard_carousel', $this->ad($medvue, ['name' => $name]));
        }
        $this->publish('dashboard_carousel');
        // Quatrième publicité sur la même période : 3 places seulement.
        $detail = $this->create('dashboard_carousel', $this->ad($medvue, ['name' => 'D', 'startsOn' => $this->day(5), 'endsOn' => $this->day(10)]));
        $this->assertCount(2, $detail['conflicts']); // ordinateur et mobile
        $this->assertSame('capacity', $detail['conflicts'][0]['reason']);
        $this->assertSame($this->day(5), $detail['conflicts'][0]['from']);
        $this->assertSame($this->day(10), $detail['conflicts'][0]['to']);
        $response = $this->admin->request('POST', '/api/admin/emplacements/dashboard_carousel/publish')->toArray(false);
        $this->assertSame(409, $this->code());
        $this->assertNotEmpty($response['conflicts']);
        $this->assertSame(['A', 'B', 'C'], $this->publicNames('dashboard_desktop'));

        // Plein écran : une seule publicité à la fois.
        $this->create('after_login', $this->ad($medvue));
        $this->publish('after_login');
        $this->create('after_login', $this->ad($medvue, ['startsOn' => $this->day(3)]));
        $this->admin->request('POST', '/api/admin/emplacements/after_login/publish');
        $this->assertSame(409, $this->code());
        // Hors chevauchement : accepté (programmation à la suite, sans fin).
        $first = $this->detail('after_login')['contents'];
        $live = array_values(array_filter($first, static fn (array $c) => $c['state'] === 'live'))[0];
        $this->update('after_login', $live['id'], $this->ad($medvue, ['startsOn' => $live['startsOn'], 'endsOn' => $this->day(2)]));
        $this->publish('after_login');
        $this->assertSame([], $this->detail('after_login')['conflicts']);
    }

    public function testExclusiveAdMustBeAloneOnItsPeriod(): void
    {
        $medvue = $this->firm('MedVue');
        $this->create('dashboard_carousel', $this->ad($medvue, ['name' => 'A']));
        $this->publish('dashboard_carousel');
        $this->create('dashboard_carousel', $this->ad($medvue, ['name' => 'Seule', 'exclusive' => true]));
        $this->admin->request('POST', '/api/admin/emplacements/dashboard_carousel/publish');
        $this->assertSame(409, $this->code());
    }

    // ------------------------------------------------------------- Publicités et partenaires ensemble

    public function testCarouselAdsNeverHideThePartnerOfTheMonth(): void
    {
        $medvue = $this->firm('MedVue');
        $bosi = $this->firm('BOSI');
        $this->create('dashboard_carousel', $this->ad($medvue, ['exclusive' => true]));
        $this->publish('dashboard_carousel');
        foreach (['dashboard_partner', 'sidebar', 'mobile_menu'] as $key) {
            $this->create($key, ['type' => 'partner', 'companyId' => $bosi->getId(), 'startsOn' => $this->day()]);
            $this->publish($key);
        }

        $this->assertSame('MedVue', $this->slots()['dashboard_desktop'][0]['sponsor']);
        $placements = $this->partnerPlacements();
        $this->assertSame('BOSI', $placements['dashboard_partner']['name']);
        $this->assertSame('BOSI', $placements['sidebar']['name']);
        $this->assertSame('BOSI', $placements['mobile_menu']['name']);
        // Une même entreprise, plusieurs emplacements : une seule fiche.
        $company = $this->admin->request('GET', '/api/admin/companies/' . $bosi->getId())->toArray();
        $this->assertCount(3, $company['emplacements']);
        $this->assertSame(3, $company['counts']['live']);
    }

    public function testExclusivePartnerEmplacementRefusesOverlapUnlessReplaced(): void
    {
        $bosi = $this->firm('BOSI');
        $medvue = $this->firm('MedVue');
        $this->create('sidebar', ['type' => 'partner', 'companyId' => $bosi->getId(), 'startsOn' => $this->day()]);
        $this->publish('sidebar');
        $detail = $this->create('sidebar', ['type' => 'partner', 'companyId' => $medvue->getId(), 'startsOn' => $this->day(7), 'endsOn' => $this->day(13)]);
        $this->assertCount(1, $detail['conflicts']);
        $this->admin->request('POST', '/api/admin/emplacements/sidebar/publish');
        $this->assertSame(409, $this->code());
        $this->assertSame('BOSI', $this->partnerPlacements()['sidebar']['name']);

        // Remplacement confirmé : BOSI raccourci, puis reprend après MedVue.
        $this->publish('sidebar', ['replace' => true]);
        $detail = $this->detail('sidebar');
        $this->assertSame([], $detail['conflicts']);
        $this->assertSame('MedVue', $this->detail('sidebar', $this->day(8))['preview']['live']['partner']['name']);
        $this->assertSame('BOSI', $this->detail('sidebar', $this->day(20))['preview']['live']['partner']['name']);
        $this->assertSame('BOSI', $this->partnerPlacements()['sidebar']['name']);
    }

    public function testPartnersPageAcceptsSeveralCompanies(): void
    {
        foreach (['MedVue', 'BOSI'] as $name) {
            $this->create('partners_page', ['type' => 'partner', 'companyId' => $this->firm($name)->getId(), 'startsOn' => $this->day()]);
        }
        $this->publish('partners_page');
        $this->assertSame(['BOSI', 'MedVue'], array_column($this->partnerPlacements()['partners_page'], 'name'));
    }

    public function testCalendarShowsFreePeriods(): void
    {
        $bosi = $this->firm('BOSI');
        $this->create('public_home', ['type' => 'partner', 'companyId' => $bosi->getId(), 'startsOn' => $this->day(10), 'endsOn' => $this->day(20)]);
        $calendar = $this->detail('public_home')['calendar'];
        $this->assertSame($this->day(), $calendar['free'][0]['from']);
        $this->assertSame($this->day(9), $calendar['free'][0]['to']);
        $this->assertSame($this->day(21), $calendar['free'][1]['from']);
    }

    // ------------------------------------------------------------- Entreprises

    public function testCompaniesAreUniqueAndKeepTheirHistory(): void
    {
        $created = $this->admin->request('POST', '/api/admin/companies', ['json' => ['name' => 'MedVue', 'websiteUrl' => 'https://medvue.example', 'contactEmail' => 'contact@medvue.example']])->toArray();
        $this->assertSame(201, $this->code());
        $this->assertSame('contact@medvue.example', $created['contactEmail']);
        $this->admin->request('POST', '/api/admin/companies', ['json' => ['name' => ' medvue ']]);
        $this->assertSame(400, $this->code());
        $this->admin->request('POST', '/api/admin/companies', ['json' => ['name' => 'X', 'contactEmail' => 'pas-un-email']]);
        $this->assertSame(400, $this->code());

        $medvue = $this->em->getRepository(Partner::class)->find($created['id']);
        $logo = new PartnerLogo('<svg xmlns="http://www.w3.org/2000/svg"/>', PartnerLogo::MIME_SVG);
        $this->em->persist($logo);
        $medvue->setLogo($logo);
        $this->em->flush();
        $this->create('login', $this->ad($medvue));
        $this->admin->request('DELETE', '/api/admin/companies/' . $created['id']);
        $this->assertSame(409, $this->code());
        // Contact administratif : jamais public.
        $this->publish('login');
        $this->assertArrayNotHasKey('contactEmail', $this->slots()['login'][0]);
    }

    public function testReplaceEverywhereListsThenReplaces(): void
    {
        $bosi = $this->firm('BOSI');
        $medvue = $this->firm('MedVue');
        foreach (['sidebar', 'mobile_menu'] as $key) {
            $this->create($key, ['type' => 'partner', 'companyId' => $bosi->getId(), 'startsOn' => $this->day()]);
            $this->publish($key);
        }
        $preview = $this->admin->request('POST', '/api/admin/companies/' . $bosi->getId() . '/replace-everywhere', ['json' => ['companyId' => $medvue->getId()]])->toArray();
        $concerned = array_column($preview['placements'], 'placement');
        sort($concerned);
        $this->assertSame(['mobile_menu', 'sidebar'], $concerned);
        $this->assertSame('BOSI', $this->partnerPlacements()['sidebar']['name']);

        $this->admin->request('POST', '/api/admin/companies/' . $bosi->getId() . '/replace-everywhere', ['json' => ['companyId' => $medvue->getId(), 'confirm' => true]]);
        $this->assertSame('MedVue', $this->partnerPlacements()['sidebar']['name']);
        $this->assertSame('MedVue', $this->partnerPlacements()['mobile_menu']['name']);
    }

    // ------------------------------------------------------------- Statistiques

    public function testStatisticsAreSeparatedByEmplacementAndCompany(): void
    {
        $medvue = $this->firm('MedVue');
        $bosi = $this->firm('BOSI');
        $adId = $this->create('dashboard_carousel', $this->ad($medvue))['contents'][0]['id'];
        $this->publish('dashboard_carousel');
        $this->create('dashboard_partner', ['type' => 'partner', 'companyId' => $bosi->getId(), 'startsOn' => $this->day()]);
        $this->publish('dashboard_partner');

        // L'administration consulte et prévisualise : rien n'est compté.
        $this->detail('dashboard_carousel');
        $this->detail('dashboard_partner');
        $this->assertSame(0, $this->admin->request('GET', '/api/admin/ad-stats')->toArray()['totals']['impressions']);

        $public = static::createClient();
        $public->request('POST', '/api/marketing/events', ['json' => ['campaign' => $adId, 'placement' => 'dashboard_desktop', 'type' => 'impression', 'visitor' => self::VISITOR]]);
        $public->request('POST', '/api/marketing/events', ['json' => ['campaign' => $adId, 'placement' => 'dashboard_desktop', 'type' => 'click', 'visitor' => self::VISITOR]]);
        $public->request('POST', '/api/partner/events', ['json' => ['partner' => $bosi->getId(), 'placement' => 'dashboard_partner', 'type' => 'impression', 'visitor' => self::VISITOR]]);

        $report = $this->admin->request('GET', '/api/admin/ad-stats')->toArray();
        $this->assertEquals(['impressions' => 2, 'clicks' => 1, 'ctr' => 50], $report['totals']);
        $byPlacement = array_column($report['placements'], null, 'placement');
        $this->assertSame(1, $byPlacement['dashboard_desktop']['impressions']);
        $this->assertEquals(100, $byPlacement['dashboard_desktop']['ctr']);
        $this->assertSame(1, $byPlacement['dashboard_partner']['impressions']);
        $this->assertSame(0, $byPlacement['dashboard_mobile']['impressions']);
        $this->assertSame(['BOSI', 'MedVue'], array_column($report['companies'], 'name'));

        $filtered = $this->admin->request('GET', '/api/admin/ad-stats?emplacement=dashboard_partner')->toArray();
        $this->assertSame(1, $filtered['totals']['impressions']);
        $this->assertSame(0, $filtered['totals']['clicks']);
        $byCompany = $this->admin->request('GET', '/api/admin/ad-stats?company=' . $medvue->getId())->toArray();
        $this->assertSame(1, $byCompany['totals']['clicks']);

        // Statistiques du contenu, dans l'emplacement.
        $this->assertSame(1, $this->detail('dashboard_carousel')['contents'][0]['stats']['clicks']);
        $this->assertSame(1, $this->detail('dashboard_partner')['contents'][0]['stats']['impressions']);
    }

    // ------------------------------------------------------------- Données existantes

    public function testExistingCampaignsKeepTheirDisplayAndAppearInTheirEmplacements(): void
    {
        // Campagne créée avant la refonte : sans entreprise, connexion + tableau de bord ordinateur.
        $legacy = (new Marketing())
            ->setCampaignName('Ancienne campagne')
            ->setStatus('active')
            ->setStartDate(new \DateTime($this->day(-30)))
            ->setSponsorName('Arthrex')
            ->setBannerDesktop('https://cdn.example/legacy.png')
            ->setPlacements(['login', 'dashboard_desktop'])
            ->setPriority(7);
        $this->em->persist($legacy);
        $this->em->flush();

        $this->assertSame('Arthrex', $this->slots()['login'][0]['sponsor']);
        $this->assertSame([$legacy->getId()], array_column($this->slots()['dashboard_desktop'], 'id'));
        $login = $this->detail('login')['contents'][0];
        $this->assertSame('live', $login['state']);
        $this->assertSame(['Tableau de bord — Carrousel publicitaire'], $login['alsoOn']);
        $this->assertSame(['desktop'], $this->detail('dashboard_carousel')['contents'][0]['supports']);
    }

    // ------------------------------------------------------------- Outils

    private function ad(Partner $company, array $extra = []): array
    {
        return array_merge([
            'type' => 'ad',
            'companyId' => $company->getId(),
            'startsOn' => $this->day(),
            'endsOn' => null,
            'url' => 'https://' . strtolower($company->getName()) . '.example',
            'imageDesktop' => 'https://cdn.example/desktop.png',
            'imageMobile' => 'https://cdn.example/mobile.png',
        ], $extra);
    }

    private function create(string $key, array $body): array
    {
        return $this->admin->request('POST', "/api/admin/emplacements/$key/contents", ['json' => $body])->toArray(false);
    }

    private function update(string $key, int $id, array $body): array
    {
        $type = ($body['type'] ?? 'ad') === 'partner' ? 'partner' : 'ad';

        return $this->admin->request('PUT', "/api/admin/emplacements/$key/contents/$type/$id", ['json' => $body])->toArray();
    }

    private function publish(string $key, array $body = []): array
    {
        $response = $this->admin->request('POST', "/api/admin/emplacements/$key/publish", ['json' => $body]);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent(false));

        return $response->toArray();
    }

    private function settings(string $key, array $body): void
    {
        $this->admin->request('PUT', "/api/admin/emplacements/$key/settings", ['json' => $body]);
        $this->assertSame(200, $this->code());
    }

    private function detail(string $key, ?string $date = null): array
    {
        return $this->admin->request('GET', "/api/admin/emplacements/$key" . ($date ? "?date=$date" : ''))->toArray();
    }

    private function slots(): array
    {
        return static::createClient()->request('GET', '/api/marketing/slots')->toArray();
    }

    private function partnerPlacements(): array
    {
        return static::createClient()->request('GET', '/api/partner/placements')->toArray();
    }

    private function lastStatus(string $url): int
    {
        return static::createClient()->request('GET', $url)->getStatusCode();
    }

    /** @return list<string> noms des publicités publiques d'un placement */
    private function publicNames(string $placement): array
    {
        $names = [];
        foreach ($this->slots()[$placement] as $campaign) {
            $names[] = $this->em->getRepository(Marketing::class)->find($campaign['id'])->getCampaignName();
        }

        return $names;
    }

    /** @param list<array<string, mixed>> $contents */
    private function names(array $contents): array
    {
        return array_column($contents, 'name');
    }

    private function code(): int
    {
        return $this->admin->getResponse()->getStatusCode();
    }

    private function day(int $offset = 0): string
    {
        return (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Brussels')))->modify(sprintf('%+d days', $offset))->format('Y-m-d');
    }

    private function firm(string $name): Partner
    {
        $logo = new PartnerLogo('<svg xmlns="http://www.w3.org/2000/svg"><text>' . $name . '</text></svg>', PartnerLogo::MIME_SVG);
        $partner = (new Partner())->setName($name)->setLogo($logo)->setWebsiteUrl('https://example.org/' . strtolower($name));
        $this->em->persist($logo);
        $this->em->persist($partner);
        $this->em->flush();

        return $partner;
    }
}
