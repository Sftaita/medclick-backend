<?php

namespace App\Tests\Api;

use App\Entity\ErrorLog;
use App\Tests\ApiTestBase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * Capture des erreurs rencontrées par les utilisateurs (table error_log) et consultation admin.
 */
class ErrorCaptureTest extends ApiTestBase
{
    // ---------------------------------------------------------------- Erreurs serveur

    public function testServerCrashIsRecordedAndGrouped(): void
    {
        $client = static::createClient();
        $client->request('GET', '/_test/crash');
        $this->assertResponseStatusCodeSame(500);
        $client->request('GET', '/_test/crash');

        $errors = $this->errors();
        $this->assertCount(1, $errors, 'Une même erreur est regroupée.');
        $error = $errors[0];
        $this->assertSame(ErrorLog::SOURCE_API, $error->getSource());
        $this->assertSame('Boom de test', $error->getMessage());
        $this->assertSame(\RuntimeException::class, $error->getExceptionClass());
        $this->assertSame('tests' . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . 'FailingController.php', $error->getFile());
        $this->assertSame('GET', $error->getMethod());
        $this->assertSame('/_test/crash', $error->getPath());
        $this->assertSame(500, $error->getStatusCode());
        $this->assertSame(2, $error->getOccurrences());
        $this->assertNotEmpty($error->getTrace());
        $this->assertNull($error->getUserId());
    }

    public function testAuthenticatedUserIsAttachedToTheError(): void
    {
        $alice = $this->createUser('alice@test.be');

        $this->clientFor($alice)->request('GET', '/api/_test/crash');

        $this->assertResponseStatusCodeSame(500);
        $this->assertSame($alice->getId(), $this->errors()[0]->getUserId());
    }

    public function testServerErrorResponseWithoutExceptionIsRecorded(): void
    {
        static::createClient()->request('GET', '/_test/http-500');

        $this->assertResponseStatusCodeSame(503);
        $error = $this->errors()[0];
        $this->assertSame(503, $error->getStatusCode());
        $this->assertNull($error->getExceptionClass());
    }

    public function testUserErrorsAreNotRecorded(): void
    {
        $client = static::createClient();
        $client->request('GET', '/_test/not-found');                         // 404
        $client->request('GET', '/api/years');                                // 401
        $client->request('POST', '/api/users', ['json' => ['email' => 'x']]); // 422

        $this->assertCount(0, $this->errors());
    }

    public function testResolvedErrorIsReopenedWhenItHappensAgain(): void
    {
        $client = static::createClient();
        $client->request('GET', '/_test/crash');

        $em = $this->freshEm();
        $em->find(ErrorLog::class, $this->errors()[0]->getId())->setResolvedAt(new \DateTimeImmutable());
        $em->flush();

        $client->request('GET', '/_test/crash');

        $this->assertNull($this->errors()[0]->getResolvedAt());
    }

    public function testRecordingFailureNeverBreaksTheResponse(): void
    {
        $this->em->getConnection()->executeStatement('DROP TABLE error_log');

        static::createClient()->request('GET', '/_test/crash');

        $this->assertResponseStatusCodeSame(500);
    }

    public function testConsoleErrorIsRecorded(): void
    {
        $application = new Application(static::$kernel);
        $application->setAutoExit(false);
        (new ApplicationTester($application))->run(['command' => 'app:test:crash']);

        $error = $this->errors()[0];
        $this->assertSame(ErrorLog::SOURCE_CONSOLE, $error->getSource());
        $this->assertSame('Commande en échec', $error->getMessage());
        $this->assertSame('bin/console app:test:crash', $error->getPath());
    }

    // ---------------------------------------------------------------- Erreurs des fronts

    public function testClientErrorIsRecordedWithPageAndMaskedToken(): void
    {
        static::createClient()->request('POST', '/api/client-errors', ['json' => [
            'source' => 'pwa',
            'message' => "TypeError: Cannot read properties of undefined (reading 'id')",
            'stack' => "TypeError: Cannot read...\n    at YearPage (https://www.medclick.be/static/js/main.abc123.js:2:1234)",
            'url' => 'https://www.medclick.be/#/resetPassword/' . str_repeat('a1', 20),
            'context' => ['status' => 500, 'endpoint' => '/api/years', 'nested' => ['ignoré']],
        ]]);

        $this->assertResponseStatusCodeSame(204);
        $error = $this->errors()[0];
        $this->assertSame(ErrorLog::SOURCE_PWA, $error->getSource());
        $this->assertSame('/#/resetPassword/{token}', $error->getPath());
        $this->assertStringContainsString('YearPage', $error->getTrace());
        $this->assertSame(['status' => '500', 'endpoint' => '/api/years', 'nested' => null], $error->getContext());
    }

    public function testSimilarClientErrorsAreGrouped(): void
    {
        $client = static::createClient();
        foreach ([12, 345] as $id) {
            $client->request('POST', '/api/client-errors', ['json' => [
                'source' => 'admin',
                'message' => "Échec du chargement de l'utilisateur $id",
            ]]);
        }

        $this->assertCount(1, $this->errors());
        $this->assertSame(2, $this->errors()[0]->getOccurrences());
    }

    public function testClientErrorAttachesConnectedUser(): void
    {
        $alice = $this->createUser('alice@test.be');

        $this->clientFor($alice)->request('POST', '/api/client-errors', ['json' => ['source' => 'pwa', 'message' => 'Erreur réseau']]);

        $this->assertSame($alice->getId(), $this->errors()[0]->getUserId());
    }

    public function testInvalidClientReportsAreRejected(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/client-errors', ['json' => ['source' => 'pirate', 'message' => 'x']]);
        $this->assertResponseStatusCodeSame(400);
        $client->request('POST', '/api/client-errors', ['json' => ['source' => 'pwa', 'message' => '  ']]);
        $this->assertResponseStatusCodeSame(400);
        $client->request('POST', '/api/client-errors', ['json' => ['source' => 'pwa', 'message' => str_repeat('x', 40000)]]);
        $this->assertResponseStatusCodeSame(413);

        $this->assertCount(0, $this->errors());
    }

    public function testClientReportsAreRateLimited(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        for ($i = 0; $i < 30; $i++) {
            $client->request('POST', '/api/client-errors', ['json' => ['source' => 'pwa', 'message' => 'Boucle']]);
        }
        $this->assertResponseStatusCodeSame(204);

        $client->request('POST', '/api/client-errors', ['json' => ['source' => 'pwa', 'message' => 'Boucle']]);
        $this->assertResponseStatusCodeSame(429);
    }

    // ---------------------------------------------------------------- Administration

    public function testErrorsAreAdminOnly(): void
    {
        $this->clientFor($this->createUser('alice@test.be'))->request('GET', '/api/admin/errors');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminCanListShowAndResolveErrors(): void
    {
        $crasher = static::createClient();
        $crasher->request('GET', '/_test/crash');
        $crasher->request('POST', '/api/client-errors', ['json' => ['source' => 'pwa', 'message' => 'Erreur front']]);

        $admin = $this->clientFor($this->createUser('admin@test.be', ['ROLE_ADMIN']));

        $list = $admin->request('GET', '/api/admin/errors')->toArray();
        $this->assertSame(2, $list['total']);
        $this->assertArrayNotHasKey('trace', $list['items'][0], 'La liste reste légère.');

        $onlyPwa = $admin->request('GET', '/api/admin/errors?source=pwa')->toArray();
        $this->assertSame(1, $onlyPwa['total']);
        $id = $onlyPwa['items'][0]['id'];

        $detail = $admin->request('GET', '/api/admin/errors/' . $id)->toArray();
        $this->assertSame('Erreur front', $detail['message']);
        $this->assertArrayHasKey('trace', $detail);

        $admin->request('PUT', '/api/admin/errors/' . $id, ['json' => ['resolved' => true]]);
        $this->assertResponseIsSuccessful();

        $this->assertSame(1, $admin->request('GET', '/api/admin/errors')->toArray()['total'], 'Par défaut : erreurs ouvertes.');
        $this->assertSame(1, $admin->request('GET', '/api/admin/errors?status=resolved')->toArray()['total']);
        $this->assertSame(2, $admin->request('GET', '/api/admin/errors?status=all')->toArray()['total']);
    }

    public function testPurgeCommandRemovesOldErrors(): void
    {
        static::createClient()->request('GET', '/_test/crash');
        $this->freshEm()->getConnection()->executeStatement("UPDATE error_log SET last_seen_at = '2020-01-01 00:00:00'");
        static::createClient()->request('POST', '/api/client-errors', ['json' => ['source' => 'pwa', 'message' => 'Récente']]);

        self::bootKernel();
        $application = new Application(static::$kernel);
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->run(['command' => 'app:errors:purge', '--days' => 90]);

        $this->assertSame(0, $tester->getStatusCode());
        $remaining = $this->errors();
        $this->assertCount(1, $remaining);
        $this->assertSame('Récente', $remaining[0]->getMessage());
    }

    // ---------------------------------------------------------------- Helpers

    /** @return ErrorLog[] */
    private function errors(): array
    {
        return $this->freshEm()->getRepository(ErrorLog::class)->findBy([], ['id' => 'ASC']);
    }

    private function freshEm()
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();

        return $em;
    }
}
