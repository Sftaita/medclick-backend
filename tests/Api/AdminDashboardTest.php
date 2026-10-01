<?php

namespace App\Tests\Api;

use App\Entity\ConnectionHistory;
use App\Entity\User;
use App\Tests\ApiTestBase;

/**
 * Tableau de bord admin des connexions (/api/admin/history/quick) et contenu du JWT.
 */
class AdminDashboardTest extends ApiTestBase
{
    public function testDashboardIsAdminOnly(): void
    {
        $alice = $this->createUser('alice@test.be');

        $this->clientFor($alice)->request('GET', '/api/admin/history/quick');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testMonthlyStatsCountConnectionsAndUniqueUsers(): void
    {
        $admin = $this->createUser('admin@test.be', ['ROLE_ADMIN']);
        $alice = $this->createUser('alice@test.be');
        $bob = $this->createUser('bob@test.be');

        // Mois précédent : 3 connexions de 2 utilisateurs ; il y a 2 ans : ignorée.
        $lastMonth = new \DateTime('first day of last month 10:00');
        $this->connection($alice, $lastMonth);
        $this->connection($alice, (clone $lastMonth)->modify('+1 day'));
        $this->connection($bob, (clone $lastMonth)->modify('+2 days'));
        $this->connection($bob, new \DateTime('-2 years'));
        $this->em->flush();

        // La connexion de l'admin (clientFor) ajoute 1 connexion ce mois-ci.
        $data = $this->clientFor($admin)->request('GET', '/api/admin/history/quick')->toArray();

        $this->assertArrayHasKey('monthly_stats', $data);
        $this->assertArrayHasKey('monthly_averages', $data);

        $byMonth = [];
        foreach ($data['monthly_stats'] as $row) {
            $byMonth[sprintf('%d-%02d', $row['year'], $row['month'])] = $row;
        }

        $key = $lastMonth->format('Y-m');
        $this->assertSame(3, $byMonth[$key]['total_connections']);
        $this->assertSame(2, $byMonth[$key]['unique_users']);
        $this->assertSame(1, $byMonth[(new \DateTime())->format('Y-m')]['total_connections']);
        $this->assertCount(2, $data['monthly_stats'], 'La connexion d\'il y a 2 ans est hors période.');
        $this->assertEquals(2, $data['monthly_averages']['average_total_connections']);
    }

    public function testJwtContainsProfileClaims(): void
    {
        $this->createUser('alice@test.be');

        $token = static::createClient()->request('POST', '/api/login_check', ['json' => [
            'username' => 'alice@test.be',
            'password' => self::PASSWORD,
        ]])->toArray()['token'];

        $payload = json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);

        $this->assertSame('alice@test.be', $payload['email']);
        $this->assertSame('Prénom', $payload['firstname']);
        $this->assertSame('Nom', $payload['lastname']);
        $this->assertArrayHasKey('acceptedTerms', $payload);
    }

    private function connection(User $user, \DateTime $date): void
    {
        $this->em->persist((new ConnectionHistory())->setUser($user)->setDate($date));
    }
}
