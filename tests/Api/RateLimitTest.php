<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Tests\ApiTestBase;

/**
 * Limitation du nombre de tentatives sur les routes publiques.
 *
 * Les compteurs sont vidés au début de chaque test (ApiTestBase::setUp).
 */
class RateLimitTest extends ApiTestBase
{
    public function testLoginIsThrottledAfterFiveFailures(): void
    {
        $this->createUser('alice@test.be');
        $client = $this->persistentClient();

        for ($i = 0; $i < 5; $i++) {
            $client->request('POST', '/api/login_check', ['json' => ['username' => 'alice@test.be', 'password' => 'mauvais']]);
            $this->assertResponseStatusCodeSame(401);
        }

        // Même le bon mot de passe est refusé tant que la fenêtre n'est pas écoulée.
        $client->request('POST', '/api/login_check', ['json' => ['username' => 'alice@test.be', 'password' => self::PASSWORD]]);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testSuccessfulLoginIsNotThrottled(): void
    {
        $this->createUser('alice@test.be');
        $client = $this->persistentClient();

        for ($i = 0; $i < 8; $i++) {
            $client->request('POST', '/api/login_check', ['json' => ['username' => 'alice@test.be', 'password' => self::PASSWORD]]);
            $this->assertResponseIsSuccessful();
        }
    }

    public function testRegistrationIsLimitedPerIp(): void
    {
        $client = $this->persistentClient();

        for ($i = 1; $i <= 5; $i++) {
            $client->request('POST', '/api/users', ['json' => [
                'email' => "user$i@test.be",
                'password' => 'motdepasse',
                'firstname' => 'Pré',
                'lastname' => 'Nom',
            ]]);
            $this->assertResponseStatusCodeSame(201);
        }

        $client->request('POST', '/api/users', ['json' => [
            'email' => 'user6@test.be',
            'password' => 'motdepasse',
            'firstname' => 'Pré',
            'lastname' => 'Nom',
        ]]);
        $this->assertResponseStatusCodeSame(429);
        $this->assertResponseHasHeader('Retry-After');
    }

    public function testForgottenPasswordIsLimitedPerIp(): void
    {
        $client = $this->persistentClient();

        for ($i = 0; $i < 5; $i++) {
            $client->request('POST', '/api/forgottenPassword', ['json' => ['username' => 'inconnu@test.be']]);
            $this->assertResponseIsSuccessful();
        }

        $client->request('POST', '/api/forgottenPassword', ['json' => ['username' => 'inconnu@test.be']]);
        $this->assertResponseStatusCodeSame(429);
    }

    private function persistentClient(): Client
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }
}
