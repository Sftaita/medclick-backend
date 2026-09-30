<?php

namespace App\Tests\Api;

use App\Entity\Nomenclature;
use App\Entity\Statistics;
use App\Entity\Surgeons;
use App\Entity\Surgeries;
use App\Entity\User;
use App\Entity\Years;
use App\Tests\ApiTestBase;

/**
 * Aucune donnée d'un autre utilisateur ne doit être lisible, et /api exige un JWT
 * hors liste blanche (audit P0, point 3).
 */
class DataLeakTest extends ApiTestBase
{
    /** @var User */
    private $alice;
    /** @var User */
    private $bob;
    /** @var Years */
    private $aliceYear;
    /** @var Years */
    private $bobYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = $this->createUser('alice@test.be');
        $this->bob = $this->createUser('bob@test.be');
        $this->aliceYear = $this->createYear($this->alice);
        $this->bobYear = $this->createYear($this->bob);
    }

    // ---------------------------------------------------------------- Export Excel

    public function testOldExcelExportRefusesAnotherUsersYear(): void
    {
        $this->clientFor($this->alice)->request('GET', '/api/excel/' . $this->bobYear->getId());

        $this->assertResponseStatusCodeSame(403);
    }

    public function testOldExcelExportRefusesUnknownYear(): void
    {
        $this->clientFor($this->alice)->request('GET', '/api/excel/999999');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testOldExcelExportWorksForOwner(): void
    {
        $this->seedYear($this->aliceYear);

        $this->clientFor($this->alice)->request('GET', '/api/excel/' . $this->aliceYear->getId());

        $this->assertResponseIsSuccessful();
    }

    public function testNewExcelExportRefusesAnotherUsersYear(): void
    {
        $this->clientFor($this->alice)->request('GET', '/api/excel2/' . $this->bobYear->getId());

        $this->assertResponseStatusCodeSame(403);
    }

    public function testNewExcelExportRefusesUnknownYear(): void
    {
        $this->clientFor($this->alice)->request('GET', '/api/excel2/999999');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testNewExcelExportWorksForOwner(): void
    {
        $this->seedYear($this->aliceYear);

        $this->clientFor($this->alice)->request('GET', '/api/excel2/' . $this->aliceYear->getId());

        $this->assertResponseIsSuccessful();
    }

    // ---------------------------------------------------------------- Endpoints custom

    public function testSurgeonsListRefusesAnotherUsersYear(): void
    {
        $this->clientFor($this->alice)->request('GET', '/api/list/' . $this->bobYear->getId());

        $this->assertResponseStatusCodeSame(403);
    }

    public function testSurgeonsListWorksForOwner(): void
    {
        $response = $this->clientFor($this->alice)->request('GET', '/api/list/' . $this->aliceYear->getId());

        $this->assertResponseIsSuccessful();
        $this->assertSame([], $response->toArray());
    }

    public function testUserStatRefusesAnotherUser(): void
    {
        $this->clientFor($this->alice)->request('GET', '/api/userStat/' . $this->bob->getId());

        $this->assertResponseStatusCodeSame(403);
    }

    public function testUserStatWorksForSelf(): void
    {
        $response = $this->clientFor($this->alice)->request('GET', '/api/userStat/' . $this->alice->getId());

        $this->assertResponseIsSuccessful();
        $this->assertSame([['id' => $this->aliceYear->getId()]], $response->toArray());
    }

    public function testUserStatWorksForAdmin(): void
    {
        $admin = $this->createUser('admin@test.be', ['ROLE_ADMIN']);

        $this->clientFor($admin)->request('GET', '/api/userStat/' . $this->bob->getId());

        $this->assertResponseIsSuccessful();
    }

    public function testStatisticsFetchRefusesAnotherUserAndNeverExposesUser(): void
    {
        $bobStats = (new Statistics())->setUser($this->bob)->setFirstHandSurgeries(12);
        $aliceStats = (new Statistics())->setUser($this->alice)->setFirstHandSurgeries(3);
        $this->em->persist($bobStats);
        $this->em->persist($aliceStats);
        $this->em->flush();

        $client = $this->clientFor($this->alice);

        $client->request('GET', '/api/statistics/fetch/' . $this->bob->getId());
        $this->assertResponseStatusCodeSame(403);

        $response = $client->request('GET', '/api/statistics/fetch/' . $this->alice->getId());
        $this->assertResponseIsSuccessful();
        $data = $response->toArray();
        $this->assertSame(3, $data['firstHandSurgeries']);
        $this->assertArrayNotHasKey('user', $data);
    }

    // ---------------------------------------------------------------- Accès anonyme

    /**
     * @dataProvider privateEndpoints
     */
    public function testPrivateEndpointsRequireAuthentication(string $method, string $url): void
    {
        static::createClient()->request($method, $url);

        $this->assertResponseStatusCodeSame(401);
    }

    public function privateEndpoints(): array
    {
        return [
            'nomenclature' => ['GET', '/api/nomenclature/ortho'],
            'liste chirurgiens' => ['GET', '/api/list/1'],
            'years' => ['GET', '/api/years'],
            'patch user' => ['PATCH', '/api/users/1'],
            'excel v2' => ['GET', '/api/excel2/1'],
        ];
    }

    /**
     * @dataProvider publicEndpoints
     */
    public function testPublicEndpointsStayReachable(string $method, string $url): void
    {
        $response = static::createClient()->request($method, $url, ['json' => []]);

        $this->assertNotSame(401, $response->getStatusCode());
    }

    public function publicEndpoints(): array
    {
        return [
            'cgu' => ['GET', '/api/terms-conditions'],
            'marketing' => ['GET', '/api/marketing/active'],
            'inscription' => ['POST', '/api/users'],
        ];
    }

    /**
     * Données minimales réalistes pour l'export : un maître de stage et une intervention.
     * (Les générateurs Excel ne gèrent pas une année vide.)
     */
    private function seedYear(Years $year): void
    {
        $boss = (new Surgeons())->setYear($year)->setFirstName('Jean')->setLastName('Maître')->setBoss(true);

        $nomenclature = (new Nomenclature())
            ->setSpeciality('dig')
            ->setName('Cholécystectomie')
            ->setCodeHospitalisation('242012')
            ->setN('1');

        $surgery = (new Surgeries())
            ->setYear($year)
            ->setNomenclature($nomenclature)
            ->setDate(new \DateTime('2025-11-01'))
            ->setSpeciality('dig')
            ->setName('Cholécystectomie')
            ->setCode('2420121')
            ->setPosition('1')
            ->setFirstHand((string) $year->getUser()->getId());

        $this->em->persist($boss);
        $this->em->persist($nomenclature);
        $this->em->persist($surgery);
        $this->em->flush();
    }
}
