<?php

namespace App\Tests;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\Nomenclature;
use App\Entity\User;
use App\Entity\Years;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Base des tests fonctionnels : recrée le schéma SQLite (voir .env.test) avant chaque test
 * et fournit des helpers pour créer des utilisateurs et s'authentifier via /api/login_check.
 */
abstract class ApiTestBase extends ApiTestCase
{
    protected const PASSWORD = 'motdepasse';

    protected static ?bool $alwaysBootKernel = true;

    /** @var EntityManagerInterface */
    protected $em;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        // Repart de compteurs vierges pour le rate limiter (pools de cache fichier).
        (new Filesystem())->remove(static::$kernel->getCacheDir() . '/pools');

        $this->em = static::getContainer()->get('doctrine')->getManager();

        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($this->em);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    protected function createUser(string $email, array $roles = []): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstname('Prénom')
            ->setLastname('Nom')
            ->setRoles($roles)
            // L'encodeur "auto" (natif) vérifie les hash produits par password_hash().
            ->setPassword(password_hash(self::PASSWORD, PASSWORD_BCRYPT));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    protected function createYear(User $user, string $yearOfFormation = '1'): Years
    {
        $year = (new Years())
            ->setUser($user)
            ->setYearOfFormation($yearOfFormation)
            ->setDateOfStart(new \DateTime('2025-10-01'))
            ->setHospital('CHU Test')
            ->setMaster('Maître');

        $this->em->persist($year);
        $this->em->flush();

        return $year;
    }

    protected function createNomenclature(): Nomenclature
    {
        $nomenclature = (new Nomenclature())
            ->setSpeciality('ortho')
            ->setName('Prothèse totale de hanche')
            ->setCodeHospitalisation('289085')
            ->setN('1');

        $this->em->persist($nomenclature);
        $this->em->flush();

        return $nomenclature;
    }

    /**
     * Client authentifié par un vrai login JWT.
     */
    protected function clientFor(User $user): Client
    {
        $client = static::createClient();

        $response = $client->request('POST', '/api/login_check', ['json' => [
            'username' => $user->getEmail(),
            'password' => self::PASSWORD,
        ]]);

        $token = $response->toArray()['token'];

        return static::createClient([], ['headers' => ['Authorization' => 'Bearer ' . $token]]);
    }
}
