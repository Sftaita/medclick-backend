<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Marketing;
use App\Entity\Partner;
use App\Entity\PartnerLogo;
use App\Tests\ApiTestBase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Rattachement des publicités sans entreprise (BOSI Knee Academy) : simulation par défaut,
 * application conditionnelle et idempotente, garde-fous, retour arrière.
 */
final class AttachCampaignsToCompanyCommandTest extends ApiTestBase
{
    private Partner $bosi;
    /** @var list<int> */
    private array $knee;
    private int $arthrex;

    protected function setUp(): void
    {
        parent::setUp();
        $logo = new PartnerLogo('<svg xmlns="http://www.w3.org/2000/svg"/>', PartnerLogo::MIME_SVG);
        $this->bosi = (new Partner())->setName('BOSI')->setLogo($logo);
        $this->em->persist($logo);
        $this->em->persist($this->bosi);
        foreach (['BOSI Knee Academy logo', 'BOSI Knee Academy', 'BOSI Knee academy2'] as $name) {
            $this->knee[] = $this->campaign($name, 'active')->getId();
        }
        $this->arthrex = $this->campaign('Test Arthrex', 'inactive')->getId();
    }

    public function testSimulationWritesNothingAndShowsTheVisibleEffect(): void
    {
        $tester = $this->command(['--campaign' => $this->knee, '--company' => 'bosi']);
        $this->assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Simulation', $display);
        $this->assertStringContainsString("3 publicité(s) à rattacher. Rien n'est écrit", $display);
        $this->assertStringContainsString('Tableau de bord — Carrousel publicitaire', $display);
        $this->assertStringContainsString('affiche « BOSI » et son logo', $display);
        $this->assertSame([null, null, null], $this->partners($this->knee));
    }

    public function testApplyAttachesOnlyTheGivenCampaignsAndIsIdempotent(): void
    {
        $tester = $this->command(['--campaign' => $this->knee, '--company' => 'BOSI', '--expect-name' => 'Knee Academy', '--apply' => true]);
        $this->assertStringContainsString('3 publicité(s) rattachée(s)', $tester->getDisplay());
        $this->assertSame([$this->bosi->getId(), $this->bosi->getId(), $this->bosi->getId()], $this->partners($this->knee));
        $this->assertSame([null], $this->partners([$this->arthrex]));
        // Diffusion : l'entreprise est désormais nommée dans le carrousel.
        $slots = static::createClient()->request('GET', '/api/marketing/slots')->toArray();
        $this->assertSame(['BOSI', 'BOSI', 'BOSI'], array_column($slots['dashboard_desktop'], 'sponsor'));

        $again = $this->command(['--campaign' => $this->knee, '--company' => 'BOSI', '--apply' => true]);
        $this->assertStringContainsString('Rien à modifier', $again->getDisplay());
    }

    public function testGuardsRefuseUnexpectedCampaignsAndAmbiguousCompanies(): void
    {
        $tester = $this->command(['--campaign' => [...$this->knee, $this->arthrex], '--company' => 'BOSI', '--expect-name' => 'Knee Academy', '--apply' => true]);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Test Arthrex', $tester->getDisplay());
        $this->assertSame([null, null, null], $this->partners($this->knee));

        $this->assertSame(1, $this->command(['--campaign' => [999], '--company' => 'BOSI'])->getStatusCode());
        $this->em->persist((new Partner())->setName(' bosi '));
        $this->em->flush();
        $ambiguous = $this->command(['--campaign' => $this->knee, '--company' => 'BOSI']);
        $this->assertSame(2, $ambiguous->getStatusCode());
        $this->assertStringContainsString('--company-id', $ambiguous->getDisplay());
        $this->assertSame(0, $this->command(['--campaign' => $this->knee, '--company-id' => (string) $this->bosi->getId()])->getStatusCode());
    }

    public function testCampaignOfAnotherCompanyIsNeverReassigned(): void
    {
        $other = (new Partner())->setName('MedVue');
        $this->em->persist($other);
        $this->em->flush();
        $this->em->getRepository(Marketing::class)->find($this->knee[0])->setPartner($other);
        $this->em->flush();

        $tester = $this->command(['--campaign' => $this->knee, '--company' => 'BOSI', '--apply' => true]);
        $this->assertStringContainsString('refusée (autre entreprise)', $tester->getDisplay());
        $this->assertSame([$other->getId(), $this->bosi->getId(), $this->bosi->getId()], $this->partners($this->knee));
    }

    public function testDetachRestoresThePreviousState(): void
    {
        $this->command(['--campaign' => $this->knee, '--company' => 'BOSI', '--apply' => true]);
        $tester = $this->command(['--campaign' => $this->knee, '--company' => 'BOSI', '--detach' => true, '--apply' => true]);
        $this->assertStringContainsString('3 publicité(s) détachée(s)', $tester->getDisplay());
        $this->assertSame([null, null, null], $this->partners($this->knee));
        $names = $this->em->getConnection()->fetchFirstColumn('SELECT sponsor_name FROM marketing WHERE id IN (' . implode(',', $this->knee) . ')');
        $this->assertSame([null, null, null], $names);
    }

    private function command(array $options): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:attach-campaigns-to-company'));
        $tester->execute($options);

        return $tester;
    }

    /** @param list<int> $ids */
    private function partners(array $ids): array
    {
        $rows = $this->em->getConnection()->fetchAllKeyValue('SELECT id, partner_id FROM marketing WHERE id IN (' . implode(',', $ids) . ')');

        return array_map(static fn (int $id) => $rows[$id] === null ? null : (int) $rows[$id], $ids);
    }

    private function campaign(string $name, string $status): Marketing
    {
        $campaign = (new Marketing())
            ->setCampaignName($name)
            ->setStatus($status)
            ->setStartDate(new \DateTime('-10 days'))
            ->setBannerDesktop('https://cdn.example/' . md5($name) . '.png')
            ->setPlacements(['dashboard_desktop', 'dashboard_mobile']);
        $this->em->persist($campaign);
        $this->em->flush();

        return $campaign;
    }
}
