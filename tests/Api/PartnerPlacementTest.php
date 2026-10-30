<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Partner;
use App\Entity\PartnerLogo;
use App\Tests\ApiTestBase;

/**
 * La nouvelle API n'altère pas les anciennes routes /api/partner/active.
 * Tests isolés sur SQLite, sans modification de production.
 */
final class PartnerPlacementTest extends ApiTestBase
{
    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->clientFor($this->createUser('admin-place@test.be', ['ROLE_ADMIN']));
    }

    private function day(int $offset = 0): string
    {
        return (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Brussels')))
            ->modify(sprintf('%+d days', $offset))->format('Y-m-d');
    }

    private function partner(string $name, bool $legacy = false): Partner
    {
        $logo = new PartnerLogo('<svg xmlns="http://www.w3.org/2000/svg"></svg>' . $name, PartnerLogo::MIME_SVG);
        $partner = (new Partner())->setName($name)->setLogo($logo)
            ->setWebsiteUrl('https://example.org/' . strtolower($name));
        if ($legacy) {
            $partner->setStartsOn(new \DateTimeImmutable($this->day()))->publish();
        }
        $this->em->persist($logo);
        $this->em->persist($partner);
        $this->em->flush();

        return $partner;
    }

    private function assign(string $placement, ?Partner $partner, array $extra = []): array
    {
        return $this->admin->request('POST', '/api/admin/partner-placements', [
            'json' => array_merge([
                'placement' => $placement,
                'partnerId' => $partner?->getId(),
                'startsOn' => $this->day(),
                'publish' => true,
            ], $extra),
        ])->toArray(false);
    }

    public function testLegacyPartnerIsRetainedInAllSixSlotsUntilOverridden(): void
    {
        $bosi = $this->partner('BOSI', true);
        $slots = static::createClient()->request('GET', '/api/partner/placements')->toArray();

        $this->assertCount(6, $slots);
        foreach ($slots as $slot) {
            $this->assertSame($bosi->getId(), $slot['id']);
        }
        $this->assertSame($bosi->getId(), static::createClient()
            ->request('GET', '/api/partner/active')->toArray()['id']);
    }

    public function testNewSponsorReplacesOnlySidebarWhileBosiStaysElsewhere(): void
    {
        $bosi = $this->partner('BOSI', true);
        $new = $this->partner('ORTHONOVA');
        $created = $this->assign('sidebar', $new);

        $this->assertSame('live', $created['state']);
        $slots = static::createClient()->request('GET', '/api/partner/placements')->toArray();
        $this->assertSame($new->getId(), $slots['sidebar']['id']);
        $this->assertSame($bosi->getId(), $slots['dashboard_partner']['id']);
        $this->assertSame($bosi->getId(), $slots['partners_page']['id']);
    }

    public function testNullAssignmentHidesOnlyItsSlot(): void
    {
        $bosi = $this->partner('BOSI', true);
        $this->assign('login_fallback', null);
        $slots = static::createClient()->request('GET', '/api/partner/placements')->toArray();
        $this->assertNull($slots['login_fallback']);
        $this->assertSame($bosi->getId(), $slots['sidebar']['id']);
    }

    public function testSimultaneousPartnersAllowedInDifferentPlacesAndRejectedOnSamePlace(): void
    {
        $a = $this->partner('BOSI', true);
        $b = $this->partner('ORTHONOVA');
        $this->assign('sidebar', $a, ['endsOn' => $this->day(7)]);
        $this->assertSame('live', $this->assign('dashboard_partner', $b)['state']);

        $this->assign('sidebar', $b, ['startsOn' => $this->day(7)]);
        $this->assertSame(400, $this->admin->getResponse()->getStatusCode());
        $this->assertStringContainsString('déjà attribué', $this->admin->getResponse()->toArray(false)['message']);

        $this->assertSame('scheduled', $this->assign('sidebar', $b, ['startsOn' => $this->day(8)])['state']);
    }

    public function testDraftIsNotShownUntilPublishedAndUnauthorizedWritesAreRejected(): void
    {
        $this->partner('BOSI', true);
        $other = $this->partner('ORTHONOVA');
        $draft = $this->assign('sidebar', $other, ['publish' => false]);
        $this->assertSame('draft', $draft['status']);

        $slots = static::createClient()->request('GET', '/api/partner/placements')->toArray();
        $this->assertSame('BOSI', $slots['sidebar']['name']);

        $user = $this->clientFor($this->createUser('assistant-place@test.be'));
        $this->assertSame(403, $user->request('POST', '/api/admin/partner-placements', [
            'json' => ['placement' => 'sidebar', 'partnerId' => null, 'startsOn' => $this->day()],
        ])->getStatusCode());
        $this->assertSame(401, static::createClient()
            ->request('GET', '/api/admin/partner-placements')->getStatusCode());

        $published = $this->admin->request('POST',
            '/api/admin/partner-placements/' . $draft['id'] . '/publish')->toArray();
        $this->assertSame('live', $published['state']);
        $slots = static::createClient()->request('GET', '/api/partner/placements')->toArray();
        $this->assertSame('ORTHONOVA', $slots['sidebar']['name']);
    }

    public function testInvalidPlacementAndUnknownPartnerAreRejected(): void
    {
        $this->assign('unknown_place', null);
        $this->assertSame(400, $this->admin->getResponse()->getStatusCode());

        $this->admin->request('POST', '/api/admin/partner-placements', [
            'json' => ['placement' => 'sidebar', 'partnerId' => 999999, 'startsOn' => $this->day(), 'publish' => true],
        ]);
        $this->assertSame(400, $this->admin->getResponse()->getStatusCode());
    }
}
