<?php

namespace App\Tests\Api;

use App\Entity\Consultations;
use App\Entity\User;
use App\Entity\Years;
use App\Tests\ApiTestBase;

/**
 * Contrat attendu par les fronts React (medclick-pwa, medclick-admin), qui n'ont pas de tests :
 * toute évolution d'API Platform doit conserver ces formats.
 */
class FrontCompatibilityTest extends ApiTestBase
{
    /** @var User */
    private $alice;
    /** @var Years */
    private $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = $this->createUser('alice@test.be');
        $this->year = $this->createYear($this->alice);
    }

    public function testCollectionsUseHydraPrefix(): void
    {
        // Le front lit response.data["hydra:member"].
        $data = $this->clientFor($this->alice)->request('GET', '/api/years')->toArray();

        $this->assertArrayHasKey('hydra:member', $data);
        $this->assertCount(1, $data['hydra:member']);
        $this->assertSame('/api/years/' . $this->year->getId(), $data['hydra:member'][0]['@id']);
        $this->assertSame('CHU Test', $data['hydra:member'][0]['hospital']);
    }

    public function testPartialPutKeepsOtherFields(): void
    {
        $consultation = (new Consultations())
            ->setDate(new \DateTime('2025-11-01'))
            ->setNumber('3')
            ->setDayPart('morning')
            ->setSpeciality('ortho')
            ->setYear($this->year);
        $this->em->persist($consultation);
        $this->em->flush();

        // Le front envoie des PUT partiels (API Platform 2) : les champs absents sont conservés.
        $this->clientFor($this->alice)->request('PUT', '/api/consultations/' . $consultation->getId(), ['json' => [
            'number' => '5',
            'year' => '/api/years/' . $this->year->getId(),
        ]]);
        $this->assertResponseIsSuccessful();

        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $saved = $em->find(Consultations::class, $consultation->getId());
        $this->assertSame('5', $saved->getNumber());
        $this->assertSame('morning', $saved->getDayPart());
        $this->assertSame('2025-11-01', $saved->getDate()->format('Y-m-d'));
    }

    public function testValidationErrorsExposeViolations(): void
    {
        // Le front parcourt error.response.data.violations[].propertyPath / message.
        $response = static::createClient()->request('POST', '/api/users', ['json' => [
            'email' => 'pas-un-email',
            'password' => 'motdepasse',
            'firstname' => '',
            'lastname' => 'Nom',
        ]]);

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        $this->assertLessThan(500, $response->getStatusCode());
        $data = $response->toArray(false);
        $this->assertArrayHasKey('violations', $data);
        $paths = array_column($data['violations'], 'propertyPath');
        $this->assertContains('email', $paths);
        $this->assertContains('firstname', $paths);
        $this->assertNotEmpty($data['violations'][0]['message']);
    }

    public function testDatesAreSerializedAsIso8601(): void
    {
        $data = $this->clientFor($this->alice)->request('GET', '/api/years/' . $this->year->getId())->toArray();

        $this->assertMatchesRegularExpression('/^2025-10-01T00:00:00[+-]\d{2}:\d{2}$/', $data['dateOfStart']);
    }
}
