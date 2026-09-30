<?php

namespace App\Tests\Api;

use App\Entity\Consultations;
use App\Entity\Favorites;
use App\Entity\Surgeries;
use App\Entity\User;
use App\Entity\Years;
use App\Tests\ApiTestBase;

/**
 * Un utilisateur ne doit jamais pouvoir créer, déplacer ou modifier des données
 * dans l'espace d'un autre utilisateur (audit P0, points 1 et 2).
 */
class OwnershipTest extends ApiTestBase
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

    // ---------------------------------------------------------------- Inscription / User

    public function testRegistrationIgnoresRoles(): void
    {
        static::createClient()->request('POST', '/api/users', ['json' => [
            'email' => 'pirate@test.be',
            'password' => 'motdepasse',
            'firstname' => 'Pi',
            'lastname' => 'Rate',
            'speciality' => 'ortho',
            'roles' => ['ROLE_ADMIN'],
            'token' => null,
        ]]);

        $this->assertResponseStatusCodeSame(201);

        $user = $this->freshEm()->getRepository(User::class)->findOneBy(['email' => 'pirate@test.be']);
        $this->assertSame(['ROLE_USER'], $user->getRoles());
        $this->assertNotNull($user->getToken(), 'Le compte doit rester à activer par email.');
        $this->assertTrue(password_verify('motdepasse', $user->getPassword()), 'Le mot de passe doit être hashé.');
    }

    public function testUserUpdateCannotChangeRolesAndHashesPassword(): void
    {
        $this->clientFor($this->alice)->request('PUT', '/api/users/' . $this->alice->getId(), ['json' => [
            'password' => 'nouveaumdp',
            'roles' => ['ROLE_ADMIN'],
        ]]);

        $this->assertResponseIsSuccessful();

        $user = $this->freshEm()->find(User::class, $this->alice->getId());
        $this->assertSame(['ROLE_USER'], $user->getRoles());
        $this->assertTrue(password_verify('nouveaumdp', $user->getPassword()), 'Le mot de passe ne doit pas être stocké en clair.');
    }

    // ---------------------------------------------------------------- API Platform
    // Un IRI pointant vers la ressource d'un autre utilisateur n'est pas résolu (CurrentUserExtension
    // filtre aussi la résolution des IRI) => 400 "Item not found". Le Voter OWNER (security_post_denormalize)
    // sert de seconde barrière.

    public function testCannotCreateSurgeryInAnotherUsersYear(): void
    {
        $this->clientFor($this->alice)->request('POST', '/api/surgeries', ['json' => $this->surgeryPayload($this->bobYear)]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame(0, $this->freshEm()->getRepository(Surgeries::class)->count([]));
    }

    public function testCanCreateSurgeryInOwnYear(): void
    {
        $this->clientFor($this->alice)->request('POST', '/api/surgeries', ['json' => $this->surgeryPayload($this->aliceYear)]);

        $this->assertResponseStatusCodeSame(201);
    }

    public function testCannotMoveConsultationToAnotherUsersYear(): void
    {
        $consultation = (new Consultations())
            ->setDate(new \DateTime('2025-11-01'))
            ->setNumber('3')
            ->setYear($this->aliceYear);
        $this->em->persist($consultation);
        $this->em->flush();

        $this->clientFor($this->alice)->request('PUT', '/api/consultations/' . $consultation->getId(), ['json' => [
            'year' => '/api/years/' . $this->bobYear->getId(),
        ]]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame(
            $this->aliceYear->getId(),
            $this->freshEm()->find(Consultations::class, $consultation->getId())->getYear()->getId()
        );
    }

    public function testCannotGiveOwnYearToAnotherUser(): void
    {
        $this->clientFor($this->alice)->request('PUT', '/api/years/' . $this->aliceYear->getId(), ['json' => [
            'user' => '/api/users/' . $this->bob->getId(),
        ]]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame(
            $this->alice->getId(),
            $this->freshEm()->find(Years::class, $this->aliceYear->getId())->getUser()->getId()
        );
    }

    public function testCanUpdateOwnYear(): void
    {
        $this->clientFor($this->alice)->request('PUT', '/api/years/' . $this->aliceYear->getId(), ['json' => [
            'hospital' => 'CHU Liège',
        ]]);

        $this->assertResponseIsSuccessful();
        $this->assertSame('CHU Liège', $this->freshEm()->find(Years::class, $this->aliceYear->getId())->getHospital());
    }

    public function testRegularUserCannotWriteStatistics(): void
    {
        $this->clientFor($this->alice)->request('POST', '/api/statistics', ['json' => [
            'user' => '/api/users/' . $this->bob->getId(),
        ]]);

        $this->assertResponseStatusCodeSame(403);
    }

    // ---------------------------------------------------------------- Contrôleurs custom

    public function testAddNewSurgeryRejectsAnotherUsersYear(): void
    {
        $nomenclature = $this->createNomenclature();

        $this->clientFor($this->alice)->request('POST', '/api/surgeries/addNewSurgery', ['json' => [
            'year' => $this->bobYear->getId(),
            'surgeryId' => $nomenclature->getId(),
            'date' => '2025-11-02',
            'position' => 1,
        ]]);

        $this->assertResponseStatusCodeSame(403);
        $this->assertSame(0, $this->freshEm()->getRepository(Surgeries::class)->count([]));
    }

    public function testAddNewSurgeryInOwnYear(): void
    {
        $nomenclature = $this->createNomenclature();

        $this->clientFor($this->alice)->request('POST', '/api/surgeries/addNewSurgery', ['json' => [
            'year' => $this->aliceYear->getId(),
            'surgeryId' => $nomenclature->getId(),
            'date' => '2025-11-02',
            'position' => 1,
        ]]);

        $this->assertResponseIsSuccessful();
        $this->assertSame(1, $this->freshEm()->getRepository(Surgeries::class)->count([]));
    }

    public function testAddNewSurgeryWithUnknownYearReturns400(): void
    {
        $this->clientFor($this->alice)->request('POST', '/api/surgeries/addNewSurgery', ['json' => [
            'year' => 999999,
            'surgeryId' => $this->createNomenclature()->getId(),
            'date' => '2025-11-02',
            'position' => 1,
        ]]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testAddSurgeryV1RejectsAnotherUsersYear(): void
    {
        $nomenclature = $this->createNomenclature();

        $this->clientFor($this->alice)->request('POST', '/api/surgeries/add', ['json' => [
            'year' => $this->bobYear->getId(),
            'surgeryId' => $nomenclature->getId(),
            'date' => '2025-11-02',
            'position' => 1,
        ]]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testUpdateSurgeryCannotMoveToAnotherUsersYear(): void
    {
        $nomenclature = $this->createNomenclature();
        $surgery = $this->createSurgery($this->aliceYear);

        $this->clientFor($this->alice)->request('PUT', '/api/surgeries/update/' . $surgery->getId(), ['json' => [
            'year' => $this->bobYear->getId(),
            'surgeryId' => $nomenclature->getId(),
            'date' => '2025-11-02',
            'position' => 1,
        ]]);

        $this->assertResponseStatusCodeSame(403);
        $this->assertSame(
            $this->aliceYear->getId(),
            $this->freshEm()->find(Surgeries::class, $surgery->getId())->getYear()->getId()
        );
    }

    public function testUpdateUnknownSurgeryReturns400(): void
    {
        $this->clientFor($this->alice)->request('PUT', '/api/surgeries/update/999999', ['json' => [
            'year' => $this->aliceYear->getId(),
        ]]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCannotUpdateAnotherUsersFavorite(): void
    {
        $nomenclature = $this->createNomenclature();
        $favorite = (new Favorites())
            ->setUser($this->bob)
            ->setSurgery($nomenclature)
            ->setShortcut('PTH')
            ->setSurgeryName($nomenclature->getName())
            ->setCodeHospitalisation('2890851');
        $this->em->persist($favorite);
        $this->em->flush();

        $this->clientFor($this->alice)->request('PUT', '/api/favorites/updateNew', ['json' => [
            'favoriteId' => $favorite->getId(),
            'surgeryId' => $nomenclature->getId(),
            'shortcut' => 'PIRATE',
        ]]);

        $this->assertResponseStatusCodeSame(404);
        $this->assertSame('PTH', $this->freshEm()->find(Favorites::class, $favorite->getId())->getShortcut());
    }

    // ---------------------------------------------------------------- Helpers

    private function surgeryPayload(Years $year): array
    {
        return [
            'date' => '2025-11-02',
            'speciality' => 'ortho',
            'name' => 'Prothèse totale de hanche',
            'position' => '1',
            'year' => '/api/years/' . $year->getId(),
        ];
    }

    private function createSurgery(Years $year): Surgeries
    {
        $surgery = (new Surgeries())
            ->setYear($year)
            ->setDate(new \DateTime('2025-11-01'))
            ->setSpeciality('ortho')
            ->setName('Prothèse totale de hanche')
            ->setPosition('1');

        $this->em->persist($surgery);
        $this->em->flush();

        return $surgery;
    }

    /**
     * Entity manager du kernel courant, vidé, pour relire l'état réel de la base.
     */
    private function freshEm()
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();

        return $em;
    }
}
