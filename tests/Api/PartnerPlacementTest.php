<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Partner;
use App\Entity\PartnerLogo;
use App\Entity\PartnerPlacementAssignment;
use App\Tests\ApiTestBase;

/**
 * Firmes partenaires par emplacement (docs/PARTENAIRES.md) : aucune firme privilégiée,
 * remplacement partout ou sur un seul emplacement, retour automatique de la firme précédente,
 * conflits de dates, page partagée, masquage, fiches des firmes, liens, mesure et droits.
 */
final class PartnerPlacementTest extends ApiTestBase
{
    private const ALL = ['login_fallback', 'dashboard_partner', 'sidebar', 'mobile_menu', 'partners_page', 'public_home'];
    private const VISITOR = 'visiteur-0123456789abcdef';

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->clientFor($this->createUser('admin-place@test.be', ['ROLE_ADMIN']));
    }

    // ------------------------------------------------------------- Aucune firme privilégiée

    public function testWithoutAssignmentNothingIsShownEvenForTheLegacyPartner(): void
    {
        // Ancien modèle : BOSI publié comme « partenaire global ». Sans affectation : rien.
        $bosi = $this->firm('BOSI');
        $bosi->setStartsOn(new \DateTimeImmutable($this->day(-5)))->publish();
        $this->em->flush();

        $slots = $this->placements();
        $this->assertSame(self::ALL, array_keys($slots));
        $this->assertSame([], $slots['partners_page']);
        foreach (array_diff(self::ALL, ['partners_page']) as $key) {
            $this->assertNull($slots[$key], $key);
        }
        $this->assertSame(204, static::createClient()->request('GET', '/api/partner/active')->getStatusCode());
    }

    public function testMigratedBosiIsShownOnTheSixPlacementsWithPublicFieldsOnly(): void
    {
        $bosi = $this->firm('BOSI');
        $this->existing($bosi, self::ALL, -5);

        $slots = $this->placements();
        foreach (array_diff(self::ALL, ['partners_page']) as $key) {
            $this->assertSame('BOSI', $slots[$key]['name'], $key);
        }
        $this->assertSame(['BOSI'], array_column($slots['partners_page'], 'name'));
        $this->assertSame(['id', 'name', 'websiteUrl', 'tagline', 'about', 'commitments', 'logo', 'coverImage'], array_keys($slots['sidebar']));
        // Ancienne API (clients d'avant les emplacements) : la même firme.
        $this->assertSame('BOSI', static::createClient()->request('GET', '/api/partner/active')->toArray()['name']);
    }

    // ------------------------------------------------------------- Remplacements

    public function testReplacingBosiEverywhereKeepsItsHistory(): void
    {
        $bosi = $this->firm('BOSI');
        $this->existing($bosi, self::ALL, -30);
        $ortho = $this->firm('OrthoNova');

        $response = $this->assign(self::ALL, $ortho, ['replace' => true]);
        $this->assertSame(201, $this->code());
        $this->assertCount(6, $response['assignments']);

        $slots = $this->placements();
        foreach (array_diff(self::ALL, ['partners_page']) as $key) {
            $this->assertSame('OrthoNova', $slots[$key]['name'], $key);
        }
        // Page partagée : BOSI reste présenté avec OrthoNova (rien n'est remplacé sur une page partagée).
        $this->assertSame(['BOSI', 'OrthoNova'], array_column($slots['partners_page'], 'name'));

        // BOSI s'est arrêté la veille : son historique est conservé.
        $history = $this->index()['assignments'];
        $bosiSidebar = array_values(array_filter($history, static fn ($a) => $a['partnerName'] === 'BOSI' && $a['placement'] === 'sidebar'));
        $this->assertCount(1, $bosiSidebar);
        $this->assertSame([$this->day(-30), $this->day(-1), 'ended'], [$bosiSidebar[0]['startsOn'], $bosiSidebar[0]['endsOn'], $bosiSidebar[0]['state']]);
    }

    public function testReplacingOnlyTheSidebarLeavesTheOtherPlacementsUntouched(): void
    {
        $bosi = $this->firm('BOSI');
        $this->existing($bosi, self::ALL, -30);
        $ortho = $this->firm('OrthoNova');

        $this->assign(['sidebar'], $ortho, ['replace' => true]);
        $this->assertSame(201, $this->code());

        $slots = $this->placements();
        $this->assertSame('OrthoNova', $slots['sidebar']['name']);
        foreach (['login_fallback', 'dashboard_partner', 'mobile_menu', 'public_home'] as $key) {
            $this->assertSame('BOSI', $slots[$key]['name'], $key);
        }
        // Le lien de la barre latérale ouvre la fiche d'OrthoNova, celui du tableau de bord celle de BOSI.
        $this->assertSame('OrthoNova', $this->publicFirm($slots['sidebar']['id'])['name']);
        $this->assertSame('https://example.org/orthonova', $this->publicFirm($slots['sidebar']['id'])['websiteUrl']);
        $this->assertSame('BOSI', $this->publicFirm($slots['dashboard_partner']['id'])['name']);
    }

    public function testTemporaryReplacementBringsBosiBackAutomatically(): void
    {
        $bosi = $this->firm('BOSI');
        $this->existing($bosi, ['sidebar'], -30);
        $ortho = $this->firm('OrthoNova');

        $this->assign(['sidebar'], $ortho, ['startsOn' => $this->day(2), 'endsOn' => $this->day(5), 'replace' => true]);
        $this->assertSame(201, $this->code());

        $periods = array_map(
            static fn ($a) => [$a['partnerName'], $a['startsOn'], $a['endsOn'], $a['state']],
            array_values(array_filter($this->index()['assignments'], static fn ($a) => $a['status'] === 'published')),
        );
        usort($periods, static fn ($a, $b) => $a[1] <=> $b[1]);
        $this->assertSame([
            ['BOSI', $this->day(-30), $this->day(1), 'live'],
            ['OrthoNova', $this->day(2), $this->day(5), 'scheduled'],
            ['BOSI', $this->day(6), null, 'scheduled'],
        ], $periods);
        // Aujourd'hui, rien ne change.
        $this->assertSame('BOSI', $this->placements()['sidebar']['name']);
    }

    public function testFullyCoveredAssignmentIsWithdrawnAndReactivatingBosiLaterIsPossible(): void
    {
        $bosi = $this->firm('BOSI');
        $ortho = $this->firm('OrthoNova');
        $this->assign(['dashboard_partner'], $ortho, ['startsOn' => $this->day(3), 'endsOn' => $this->day(4)]);
        $this->assertSame(201, $this->code());

        // OrthoNova sans fin dès aujourd'hui : l'affectation programmée (3 → 4) est entièrement couverte.
        $this->assign(['dashboard_partner'], $ortho, ['replace' => true]);
        $this->assertSame(201, $this->code());
        $states = array_column($this->index()['assignments'], 'state');
        sort($states);
        $this->assertSame(['live', 'withdrawn'], $states);

        // Réactiver BOSI dans 10 jours : OrthoNova s'arrête la veille.
        $this->assign(['dashboard_partner'], $bosi, ['startsOn' => $this->day(10), 'replace' => true]);
        $live = array_values(array_filter($this->index()['assignments'], static fn ($a) => $a['state'] === 'live'));
        $this->assertSame([['OrthoNova', $this->day(9)]], array_map(static fn ($a) => [$a['partnerName'], $a['endsOn']], $live));
    }

    // ------------------------------------------------------------- Conflits de dates

    public function testConflictIsReportedAndNothingIsWrittenWithoutConfirmation(): void
    {
        $bosi = $this->firm('BOSI');
        $this->existing($bosi, ['sidebar'], -30);
        $ortho = $this->firm('OrthoNova');

        // Deux emplacements demandés, l'un libre, l'autre occupé : tout ou rien.
        $response = $this->assign(['sidebar', 'dashboard_partner'], $ortho, ['startsOn' => $this->day(7)]);
        $this->assertSame(409, $this->code());
        $this->assertStringContainsString('Emplacement déjà attribué', $response['message']);
        $this->assertSame([['sidebar', 'BOSI', $this->day(-30), null]], array_map(static fn ($c) => [$c['placement'], $c['partnerName'], $c['startsOn'], $c['endsOn']], $response['conflicts']));
        $this->assertCount(1, $this->freshAssignments());
        $this->assertNull($this->placements()['dashboard_partner']);
    }

    public function testConsecutivePeriodsAreAcceptedAndInvalidDatesRefused(): void
    {
        $ortho = $this->firm('OrthoNova');
        $bosi = $this->firm('BOSI');
        $this->assign(['public_home'], $ortho, ['endsOn' => $this->day(6)]);
        $this->assertSame(201, $this->code());
        $this->assign(['public_home'], $bosi, ['startsOn' => $this->day(7)]);
        $this->assertSame(201, $this->code());

        $this->assertStringStartsWith('La date de début est passée', $this->assign(['sidebar'], $ortho, ['startsOn' => $this->day(-1)])['message']);
        $this->assertSame(400, $this->code());
        $this->assign(['sidebar'], $ortho, ['startsOn' => $this->day(5), 'endsOn' => $this->day(4)]);
        $this->assertSame(400, $this->code());
        $this->assign(['sidebar'], $ortho, ['startsOn' => '2026-02-30']);
        $this->assertSame(400, $this->code());
        $this->assign(['inconnu'], $ortho);
        $this->assertSame(400, $this->code());
        $this->assign(['sidebar'], null, ['partnerId' => 999999]);
        $this->assertSame(400, $this->code());
    }

    public function testSimultaneousFirmsOnDifferentPlacementsAndOnTheSharedPage(): void
    {
        $bosi = $this->firm('BOSI');
        $ortho = $this->firm('OrthoNova');
        $this->assign(['sidebar', 'partners_page'], $bosi);
        $this->assign(['dashboard_partner', 'partners_page'], $ortho);
        $this->assertSame(201, $this->code());

        $slots = $this->placements();
        $this->assertSame(['BOSI', 'OrthoNova'], [$slots['sidebar']['name'], $slots['dashboard_partner']['name']]);
        $this->assertSame(['BOSI', 'OrthoNova'], array_column($slots['partners_page'], 'name'));

        // La même firme deux fois sur la page partagée, sur la même période : refusé.
        $this->assign(['partners_page'], $ortho, ['startsOn' => $this->day(3)]);
        $this->assertSame(409, $this->code());
        // Confirmé (« Remplacer partout ») : la présence existante est raccourcie, pas de doublon.
        $this->assign(['partners_page'], $ortho, ['startsOn' => $this->day(3), 'endsOn' => $this->day(5), 'replace' => true]);
        $this->assertSame(201, $this->code());
        $this->assertSame(['BOSI', 'OrthoNova'], array_column($this->placements()['partners_page'], 'name'));
    }

    public function testHidingAPlacementOnlyAffectsThatPlacement(): void
    {
        $bosi = $this->firm('BOSI');
        $this->existing($bosi, self::ALL, -3);

        $this->assign(['login_fallback'], null, ['replace' => true]);
        $this->assertSame(201, $this->code());

        $slots = $this->placements();
        $this->assertNull($slots['login_fallback']);
        $this->assertSame('BOSI', $slots['sidebar']['name']);
        // La page partagée ne se « masque » pas : on retire l'affectation de la firme.
        $this->assign(['partners_page'], null);
        $this->assertSame(400, $this->code());
    }

    // ------------------------------------------------------------- Brouillons, retrait, publication

    public function testDraftPublishWithdrawAndDelete(): void
    {
        $ortho = $this->firm('OrthoNova');
        $draft = $this->assign(['sidebar'], $ortho, ['publish' => false])['assignments'][0];
        $this->assertSame('draft', $draft['state']);
        $this->assertNull($this->placements()['sidebar']);

        $published = $this->admin->request('POST', "/api/admin/partner-placements/{$draft['id']}/publish")->toArray();
        $this->assertSame('live', $published['state']);
        $this->assertSame('OrthoNova', $this->placements()['sidebar']['name']);

        $this->admin->request('DELETE', "/api/admin/partner-placements/{$draft['id']}");
        $this->assertSame(409, $this->code());

        $withdrawn = $this->admin->request('POST', "/api/admin/partner-placements/{$draft['id']}/withdraw")->toArray();
        $this->assertSame('withdrawn', $withdrawn['state']);
        $this->assertNull($this->placements()['sidebar']);

        $other = $this->assign(['public_home'], $ortho, ['publish' => false])['assignments'][0];
        $this->admin->request('DELETE', "/api/admin/partner-placements/{$other['id']}");
        $this->assertSame(200, $this->code());
    }

    public function testEditingAPublishedAssignmentRechecksConflicts(): void
    {
        $bosi = $this->firm('BOSI');
        $ortho = $this->firm('OrthoNova');
        $first = $this->assign(['sidebar'], $ortho, ['endsOn' => $this->day(5)])['assignments'][0];
        $this->assign(['sidebar'], $bosi, ['startsOn' => $this->day(6)]);

        $this->admin->request('PUT', "/api/admin/partner-placements/{$first['id']}", ['json' => ['partnerId' => $ortho->getId(), 'startsOn' => $this->day(0), 'endsOn' => $this->day(8)]]);
        $this->assertSame(409, $this->code());
        $this->assertSame($this->day(5), $this->em->getRepository(PartnerPlacementAssignment::class)->find($first['id'])->getEndsOn()->format('Y-m-d'));

        $this->admin->request('PUT', "/api/admin/partner-placements/{$first['id']}", ['json' => ['partnerId' => $bosi->getId(), 'startsOn' => $this->day(0), 'endsOn' => $this->day(5)]]);
        $this->assertSame(200, $this->code());
        $this->assertSame('BOSI', $this->placements()['sidebar']['name']);
    }

    // ------------------------------------------------------------- Fiches des firmes

    public function testFirmProfilesAreManagedWithoutTouchingTheLegacyPeriod(): void
    {
        $logo = $this->logoFile();
        $created = $this->admin->request('POST', '/api/admin/partner-firms', ['json' => ['name' => 'CardioPlus', 'websiteUrl' => 'https://example.org/cardio', 'tagline' => 'Le cœur de la chirurgie', 'logo' => $logo]])->toArray();
        $this->assertSame(201, $this->code());
        $this->assertSame(['CardioPlus', 0], [$created['name'], $created['assignments']]);

        // Firme sans logo : enregistrée, mais jamais diffusée.
        $nologo = $this->admin->request('POST', '/api/admin/partner-firms', ['json' => ['name' => 'Sans logo']])->toArray();
        $this->assertStringContainsString('Ajoutez un logo', $this->admin->request('POST', '/api/admin/partner-placements', ['json' => ['placements' => ['sidebar'], 'partnerId' => $nologo['id'], 'startsOn' => $this->day(0), 'publish' => true]])->toArray(false)['message']);

        // Ancien partenaire publié (BOSI) : modifier sa fiche ne change pas sa période historique.
        $bosi = $this->firm('BOSI');
        $bosi->setStartsOn(new \DateTimeImmutable($this->day(-10)))->publish();
        $this->em->flush();
        $this->admin->request('PUT', "/api/admin/partner-firms/{$bosi->getId()}", ['json' => ['name' => 'BOSI Orthopédie', 'logo' => $bosi->getLogo()->getFileName()]]);
        $this->assertSame(200, $this->code());
        $this->em->clear();
        $reloaded = $this->em->find(Partner::class, $bosi->getId());
        $this->assertSame(['BOSI Orthopédie', $this->day(-10), 'published'], [$reloaded->getName(), $reloaded->getStartsOn()->format('Y-m-d'), $reloaded->getStatus()]);

        $this->admin->request('PUT', "/api/admin/partner-firms/{$created['id']}", ['json' => ['name' => 'CardioPlus', 'coverImage' => 'https://pirate.example/image.png']]);
        $this->assertSame(400, $this->code());

        $names = array_column($this->admin->request('GET', '/api/admin/partner-firms')->toArray()['firms'], 'name');
        $this->assertSame(['BOSI Orthopédie', 'CardioPlus', 'Sans logo'], $names);
    }

    public function testOnlyNeverAssignedFirmsCanBeDeleted(): void
    {
        $ortho = $this->firm('OrthoNova');
        $unused = $this->firm('Inutilisée');
        $this->assign(['sidebar'], $ortho);

        $this->admin->request('DELETE', "/api/admin/partner-firms/{$ortho->getId()}");
        $this->assertSame(409, $this->code());
        $this->admin->request('DELETE', "/api/admin/partner-firms/{$unused->getId()}");
        $this->assertSame(200, $this->code());
    }

    public function testFirmPageIsPublicOnlyWhileTheFirmIsShown(): void
    {
        $ortho = $this->firm('OrthoNova');
        $scheduled = $this->firm('Programmée');
        $this->assign(['partners_page'], $ortho);
        $this->assign(['sidebar'], $scheduled, ['startsOn' => $this->day(3)]);

        $this->assertSame('OrthoNova', $this->publicFirm($ortho->getId())['name']);
        $this->assertSame(404, static::createClient()->request('GET', '/api/partner/' . $scheduled->getId())->getStatusCode());
        $this->assertSame(404, static::createClient()->request('GET', '/api/partner/999999')->getStatusCode());
    }

    // ------------------------------------------------------------- Mesure

    public function testImpressionsAndClicksAreMeasuredPerFirmAndPlacement(): void
    {
        $bosi = $this->firm('BOSI');
        $ortho = $this->firm('OrthoNova');
        $this->assign(['sidebar'], $ortho);
        $this->assign(['dashboard_partner'], $bosi);

        $this->assertTrue($this->event($ortho, 'sidebar', 'impression'));
        $this->assertFalse($this->event($ortho, 'sidebar', 'impression'), 'doublon sur 30 minutes');
        $this->assertTrue($this->event($ortho, 'sidebar', 'impression', 'autre-visiteur-0123456789'));
        $this->assertTrue($this->event($ortho, 'sidebar', 'click'));
        $this->assertTrue($this->event($bosi, 'dashboard_partner', 'impression'));
        // Firme non diffusée sur cet emplacement : rien n'est compté.
        $this->assertFalse($this->event($bosi, 'sidebar', 'impression'));
        $this->assertFalse($this->event($ortho, 'inconnu', 'impression'));

        $report = $this->admin->request('GET', '/api/admin/partner-placements/stats')->toArray();
        $this->assertSame(['impressions' => 3, 'clicks' => 1, 'ctr' => 33.33], $report['totals']);
        $byFirm = array_column($report['partners'], null, 'name');
        $this->assertEquals([2, 1, 50.0], [$byFirm['OrthoNova']['impressions'], $byFirm['OrthoNova']['clicks'], $byFirm['OrthoNova']['ctr']]);
        $this->assertSame('sidebar', $byFirm['OrthoNova']['placements'][0]['placement']);
        $this->assertSame(1, $byFirm['BOSI']['impressions']);

        $this->assertSame(400, static::createClient()->request('POST', '/api/partner/events', ['json' => ['partner' => $ortho->getId(), 'placement' => 'sidebar', 'type' => 'vue', 'visitor' => self::VISITOR]])->getStatusCode());
        $this->assertSame(400, static::createClient()->request('POST', '/api/partner/events', ['json' => ['partner' => $ortho->getId(), 'placement' => 'sidebar', 'type' => 'click', 'visitor' => 'x']])->getStatusCode());
    }

    // ------------------------------------------------------------- Droits

    public function testAdministrationIsReservedToAdmins(): void
    {
        $user = $this->clientFor($this->createUser('assistant-place@test.be'));
        $visitor = static::createClient();
        $routes = [
            ['GET', '/api/admin/partner-placements'],
            ['POST', '/api/admin/partner-placements'],
            ['GET', '/api/admin/partner-placements/stats'],
            ['GET', '/api/admin/partner-firms'],
            ['POST', '/api/admin/partner-firms'],
        ];
        foreach ($routes as [$method, $url]) {
            $this->assertSame(403, $user->request($method, $url, ['json' => ['placements' => ['sidebar'], 'partnerId' => null, 'name' => 'Pirate']])->getStatusCode(), "$method $url (utilisateur)");
            $this->assertSame(401, $visitor->request($method, $url, ['json' => []])->getStatusCode(), "$method $url (visiteur)");
        }
        $this->assertSame(0, $this->em->getRepository(PartnerPlacementAssignment::class)->count([]));
    }

    // ------------------------------------------------------------- Outils

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

    private function logoFile(): string
    {
        $logo = new PartnerLogo('<svg xmlns="http://www.w3.org/2000/svg"><text>Cardio</text></svg>', PartnerLogo::MIME_SVG);
        $this->em->persist($logo);
        $this->em->flush();

        return $logo->getFileName();
    }

    /** Affectations publiées existantes (reprise de l'ancien modèle) commençant il y a |$offset| jours. */
    private function existing(Partner $partner, array $placements, int $offset): void
    {
        foreach ($placements as $placement) {
            $this->em->persist((new PartnerPlacementAssignment())
                ->setPartner($partner)
                ->setPlacement($placement)
                ->setStartsOn(new \DateTimeImmutable($this->day($offset)))
                ->publish());
        }
        $this->em->flush();
    }

    private function assign(array $placements, ?Partner $partner, array $extra = []): array
    {
        return $this->admin->request('POST', '/api/admin/partner-placements', [
            'json' => array_merge(['placements' => $placements, 'partnerId' => $partner?->getId(), 'startsOn' => $this->day(), 'publish' => true], $extra),
        ])->toArray(false);
    }

    private function code(): int
    {
        return $this->admin->getResponse()->getStatusCode();
    }

    private function index(): array
    {
        return $this->admin->request('GET', '/api/admin/partner-placements')->toArray();
    }

    private function placements(): array
    {
        return static::createClient()->request('GET', '/api/partner/placements')->toArray();
    }

    private function publicFirm(int $id): array
    {
        return static::createClient()->request('GET', "/api/partner/$id")->toArray();
    }

    /** @return list<PartnerPlacementAssignment> */
    private function freshAssignments(): array
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();

        return $em->getRepository(PartnerPlacementAssignment::class)->findAll();
    }

    private function event(Partner $partner, string $placement, string $type, string $visitor = self::VISITOR): bool
    {
        $response = static::createClient()->request('POST', '/api/partner/events', ['json' => ['partner' => $partner->getId(), 'placement' => $placement, 'type' => $type, 'visitor' => $visitor]]);
        $this->assertSame(202, $response->getStatusCode());

        return $response->toArray()['recorded'];
    }
}
