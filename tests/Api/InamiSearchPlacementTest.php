<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Marketing;
use App\Tests\ApiTestBase;

/**
 * Emplacement publicitaire « Recherche INAMI » (inami_search) : page publique, donc jamais de
 * campagne réservée aux professionnels (même pour un utilisateur connecté, même si une telle
 * campagne existait en base), formats propres, refus des configurations incompatibles dans
 * l'administration, mesure par le système existant. Les autres emplacements sont inchangés.
 */
final class InamiSearchPlacementTest extends ApiTestBase
{
    private const AD = [
        'campaign_name' => 'Bannière INAMI',
        'sponsor_name' => 'OrthoLab',
        'status' => 'active',
        'start_date' => '2026-01-01',
        'end_date' => null,
        'redirect_url' => 'https://ortholab.example/',
        'banner_desktop' => 'https://cdn.example/inami-1200x150.webp',
        'banner_mobile' => 'https://cdn.example/inami-640x200.webp',
        'placements' => ['inami_search'],
        'priority' => 5,
        'exclusive' => false,
        'professionals_only' => false,
    ];

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->clientFor($this->createUser('admin-inami-pub@test.be', ['ROLE_ADMIN']));
    }

    private function create(array $campaign): void
    {
        $response = $this->admin->request('POST', '/api/admin/marketing', ['json' => $campaign]);
        $this->assertContains($response->getStatusCode(), [200, 201], $response->getContent(false));
    }

    public function testPublicCampaignIsServedOnTheInamiSearchOnly(): void
    {
        $this->create(self::AD);

        $slots = static::createClient()->request('GET', '/api/marketing/slots')->toArray();
        $this->assertSame(['OrthoLab'], array_column($slots['inami_search'], 'sponsor'));
        $this->assertSame('https://cdn.example/inami-1200x150.webp', $slots['inami_search'][0]['imageDesktop']);
        $this->assertSame([], $slots['login']);
        $this->assertSame([], $slots['dashboard_desktop']);
    }

    public function testProfessionalCampaignIsRefusedOnTheInamiSearch(): void
    {
        $response = $this->admin->request('POST', '/api/admin/marketing', ['json' => array_merge(self::AD, ['professionals_only' => true])]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('la recherche INAMI est une page publique', $response->toArray(false)['message']);
    }

    public function testInamiSearchCannotBeCombinedWithOtherPlacements(): void
    {
        $response = $this->admin->request('POST', '/api/admin/marketing', ['json' => array_merge(self::AD, ['placements' => ['inami_search', 'dashboard_desktop']])]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('formats propres', $response->toArray(false)['message']);
    }

    public function testProfessionalCampaignIsNeverServedThereEvenToConnectedUsers(): void
    {
        // Garantie côté serveur, indépendante de l'administration : campagne enregistrée
        // directement en base (ancienne donnée, import…).
        $this->create(self::AD);
        $pro = (new Marketing())
            ->setCampaignName('Réservée')
            ->setSponsorName('PharmaPro')
            ->setStatus('active')
            ->setStartDate(new \DateTime('2026-01-01'))
            ->setBannerDesktop('https://cdn.example/pro.webp')
            ->setPlacements(['inami_search'])
            ->setPriority(10)
            ->setExclusive(true)
            ->setProfessionalsOnly(true);
        $this->em->persist($pro);
        $this->em->flush();

        $doctor = $this->clientFor($this->createUser('medecin-inami-pub@test.be'));
        foreach ([static::createClient(), $doctor] as $client) {
            $slots = $client->request('GET', '/api/marketing/slots')->toArray();
            $this->assertNotContains('PharmaPro', array_column($slots['inami_search'], 'sponsor'));
            $this->assertSame(['OrthoLab'], array_column($slots['inami_search'], 'sponsor'));
        }

        $event = ['campaign' => $pro->getId(), 'placement' => 'inami_search', 'type' => 'impression', 'visitor' => 'visiteur-0123456789abcdef'];
        $this->assertFalse($doctor->request('POST', '/api/marketing/events', ['json' => $event])->toArray()['recorded']);
    }

    public function testImpressionsAndClicksAreMeasuredWithTheExistingStatistics(): void
    {
        $this->create(self::AD);
        $id = $this->em->getRepository(Marketing::class)->findOneBy(['campaign_name' => 'Bannière INAMI'])->getId();
        $client = static::createClient();
        foreach (['impression', 'click'] as $type) {
            $event = ['campaign' => $id, 'placement' => 'inami_search', 'type' => $type, 'visitor' => 'page-0123456789abcdef'];
            $this->assertTrue($client->request('POST', '/api/marketing/events', ['json' => $event])->toArray()['recorded']);
        }

        $report = $this->admin->request('GET', '/api/admin/marketing-stats')->toArray();
        $placements = array_column($report['placements'], null, 'placement');
        $this->assertSame('Recherche INAMI', $placements['inami_search']['label']);
        $this->assertSame(1, $placements['inami_search']['impressions']);
        $this->assertSame(1, $placements['inami_search']['clicks']);
    }
}
