<?php

namespace App\Tests\Api;

use App\Tests\ApiTestBase;

/**
 * Gardes et formations : la fin d'une période ne peut pas précéder son début (constaté en test
 * navigateur : une formation « de 14:00 à 12:00 » était acceptée). Format de date historique du
 * front : « DD-MM-YYYY, H:mm ».
 */
class PeriodValidationTest extends ApiTestBase
{
    public function testGardeEndingBeforeItStartsIsRefused(): void
    {
        [$client, $year] = $this->residentWithYear();

        $response = $client->request('POST', '/api/gardes', ['json' => $this->garde($year, '25-10-2026, 8:00', '24-10-2026, 20:00')]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame([['propertyPath' => 'dateOfEnd', 'message' => 'La fin doit être postérieure au début.']], $this->violations($response));
    }

    public function testNightGardeAcrossTheClockChangeIsAccepted(): void
    {
        [$client, $year] = $this->residentWithYear();

        $client->request('POST', '/api/gardes', ['json' => $this->garde($year, '24-10-2026, 20:00', '25-10-2026, 8:00')]);

        $this->assertResponseStatusCodeSame(201);
    }

    public function testFormationEndingBeforeItStartsIsRefusedButSameTimeIsAccepted(): void
    {
        [$client, $year] = $this->residentWithYear();

        $response = $client->request('POST', '/api/formations', ['json' => $this->formation($year, '20-10-2026, 14:00', '20-10-2026, 12:00')]);
        $this->assertResponseStatusCodeSame(422);
        $this->assertSame([['propertyPath' => 'dateOfEnd', 'message' => 'La fin doit être postérieure au début.']], $this->violations($response));

        $client->request('POST', '/api/formations', ['json' => $this->formation($year, '20-10-2026, 14:00', '20-10-2026, 14:00')]);
        $this->assertResponseStatusCodeSame(201);
    }

    /** @return array{0: \ApiPlatform\Symfony\Bundle\Test\Client, 1: \App\Entity\Years} */
    private function residentWithYear(): array
    {
        $user = $this->createUser('alice@test.be');
        $year = $this->createYear($user);

        return [$this->clientFor($user), $year];
    }

    private function garde($year, string $start, string $end): array
    {
        return ['dateOfStart' => $start, 'dateOfEnd' => $end, 'number' => '9', 'year' => '/api/years/' . $year->getId()];
    }

    private function formation($year, string $start, string $end): array
    {
        return [
            'event' => 'lesson',
            'dateOfStart' => $start,
            'dateOfEnd' => $end,
            'name' => 'Cours de test',
            'description' => 'Formation fictive',
            'role' => 'auditor',
            'location' => 'local',
            'year' => '/api/years/' . $year->getId(),
        ];
    }

    private function violations($response): array
    {
        return array_map(
            static fn (array $v) => ['propertyPath' => $v['propertyPath'], 'message' => $v['message']],
            $response->toArray(false)['violations'] ?? []
        );
    }
}
