<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Marketing;
use App\Tests\ApiTestBase;

/**
 * Campagnes réservées aux professionnels (jamais servies à un visiteur non connecté), plein
 * écran après la connexion avec priorité et exclusivité, exclusivité contrôlée à l'activation.
 */
final class SponsorAudienceTest extends ApiTestBase
{
    private const CARD = [
        'campaign_name' => 'Prothèse NovaFlex',
        'sponsor_name' => 'OrthoLab',
        'status' => 'active',
        'start_date' => '2026-01-01',
        'end_date' => null,
        'redirect_url' => 'https://ortholab.example/genou',
        'banner_desktop' => 'https://cdn.example/desktop.webp',
        'banner_mobile' => 'https://cdn.example/mobile.webp',
        'placements' => ['dashboard_desktop', 'dashboard_mobile'],
        'priority' => 5,
        'exclusive' => false,
        'professionals_only' => true,
    ];

    private const INTERSTITIAL = [
        'campaign_name' => 'Plein écran',
        'sponsor_name' => 'CardioPlus',
        'status' => 'active',
        'start_date' => '2026-01-01',
        'end_date' => null,
        'large_screen_format' => 'https://cdn.example/large.webp',
        'placements' => ['login_interstitial'],
        'priority' => 5,
        'exclusive' => false,
    ];

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->clientFor($this->createUser('admin-audience@test.be', ['ROLE_ADMIN']));
    }

    public function testProfessionalCampaignIsRefusedOnThePublicLoginPage(): void
    {
        $response = $this->admin->request('POST', '/api/admin/marketing', ['json' => array_merge(self::CARD, ['placements' => ['login', 'dashboard_desktop']])]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('page de connexion est publique', $response->toArray(false)['message']);
    }

    public function testProfessionalCampaignIsServedToConnectedUsersOnly(): void
    {
        $this->create(self::CARD);
        $this->create(array_merge(self::CARD, ['campaign_name' => 'Congrès', 'professionals_only' => false]));

        $anonymous = static::createClient()->request('GET', '/api/marketing/slots')->toArray();
        $this->assertSame(['Congrès'], $this->names($anonymous['dashboard_desktop']));

        $doctor = $this->clientFor($this->createUser('medecin-audience@test.be'));
        $connected = $doctor->request('GET', '/api/marketing/slots')->toArray();
        $this->assertCount(2, $connected['dashboard_desktop']);

        $pro = $this->em->getRepository(Marketing::class)->findOneBy(['campaign_name' => 'Prothèse NovaFlex']);
        $event = ['campaign' => $pro->getId(), 'placement' => 'dashboard_desktop', 'type' => 'impression', 'visitor' => 'visiteur-0123456789abcdef'];
        $this->assertFalse(static::createClient()->request('POST', '/api/marketing/events', ['json' => $event])->toArray()['recorded']);
        $this->assertTrue($doctor->request('POST', '/api/marketing/events', ['json' => $event])->toArray()['recorded']);
        $this->assertTrue($doctor->request('GET', '/api/admin/marketing/' . $pro->getId())->getStatusCode() === 403);
    }

    public function testInterstitialRespectsExclusivityForVisitorsAndConnectedUsers(): void
    {
        $this->create(array_merge(self::INTERSTITIAL, ['campaign_name' => 'Prioritaire', 'priority' => 10]));
        $this->create(array_merge(self::INTERSTITIAL, ['campaign_name' => 'Exclusive', 'priority' => 1, 'exclusive' => true]));

        // Avant la connexion (affichée dès le clic sur « Se connecter ») : sans les compteurs.
        $visitor = static::createClient()->request('GET', '/api/marketing/active');
        $this->assertSame('Exclusive', $visitor->toArray()['campaign_name']);
        $this->assertArrayNotHasKey('views', $visitor->toArray());
        $this->assertArrayNotHasKey('clicks', $visitor->toArray());
        $this->assertStringContainsString('no-store', $visitor->getHeaders()['cache-control'][0]);
        $doctor = $this->clientFor($this->createUser('medecin-plein@test.be'));
        for ($i = 0; $i < 5; ++$i) {
            $active = $doctor->request('GET', '/api/marketing/active');
            $this->assertSame('Exclusive', $active->toArray()['campaign_name']);
            $this->assertStringContainsString('no-store', $active->getHeaders()['cache-control'][0]);
        }
    }

    public function testProfessionalInterstitialIsNeverDisclosedBeforeLogin(): void
    {
        // Exclusive réservée aux professionnels : un visiteur ne reçoit ni elle (contenu), ni la
        // campagne publique à sa place (l'exclusivité serait contournée).
        $this->create(array_merge(self::INTERSTITIAL, ['campaign_name' => 'Publique', 'priority' => 10]));
        $this->create(array_merge(self::INTERSTITIAL, ['campaign_name' => 'Réglementée', 'exclusive' => true, 'professionals_only' => true]));

        for ($i = 0; $i < 5; ++$i) {
            $visitor = static::createClient()->request('GET', '/api/marketing/active');
            $this->assertSame(200, $visitor->getStatusCode());
            $this->assertSame(['after_login' => true], $visitor->toArray());
        }
        $doctor = $this->clientFor($this->createUser('medecin-reglemente@test.be'));
        $this->assertSame('Réglementée', $doctor->request('GET', '/api/marketing/active')->toArray()['campaign_name']);
    }

    public function testVisitorGetsNotFoundWithoutLiveInterstitial(): void
    {
        $this->create(array_merge(self::INTERSTITIAL, ['status' => 'inactive']));

        $this->assertSame(404, static::createClient()->request('GET', '/api/marketing/active')->getStatusCode());
    }

    public function testInterstitialRotationIsWeightedByPriority(): void
    {
        $this->create(array_merge(self::INTERSTITIAL, ['campaign_name' => 'Forte', 'priority' => 10]));
        $this->create(array_merge(self::INTERSTITIAL, ['campaign_name' => 'Faible', 'priority' => 1]));

        $doctor = $this->clientFor($this->createUser('medecin-rotation@test.be'));
        $counts = ['Forte' => 0, 'Faible' => 0];
        for ($i = 0; $i < 40; ++$i) {
            ++$counts[$doctor->request('GET', '/api/marketing/active')->toArray()['campaign_name']];
        }
        // Poids 10 contre 1 : la forte est tirée bien plus souvent (≈ 91 %), la faible reste possible.
        $this->assertGreaterThan($counts['Faible'], $counts['Forte']);
    }

    public function testActivatingAnExclusiveCampaignByStatusChecksConflicts(): void
    {
        $card = array_merge(self::CARD, ['professionals_only' => false, 'exclusive' => true]);
        $this->create($card);
        $this->create(array_merge($card, ['campaign_name' => 'Concurrente', 'status' => 'inactive']));
        $other = $this->em->getRepository(Marketing::class)->findOneBy(['campaign_name' => 'Concurrente']);

        $response = $this->admin->request('PUT', '/api/admin/marketing/' . $other->getId() . '/status', ['json' => ['status' => 'active']]);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('Exclusivité impossible', $response->toArray(false)['message']);
        $this->em->clear();
        $this->assertSame('inactive', $this->em->getRepository(Marketing::class)->find($other->getId())->getStatus());
    }

    private function create(array $campaign): void
    {
        $this->admin->request('POST', '/api/admin/marketing', ['json' => $campaign]);
        $this->assertResponseStatusCodeSame(201);
    }

    private function names(array $served): array
    {
        $names = [];
        foreach ($served as $campaign) {
            $names[] = $this->em->getRepository(Marketing::class)->find($campaign['id'])->getCampaignName();
        }

        return $names;
    }
}
