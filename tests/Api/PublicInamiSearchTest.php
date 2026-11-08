<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\Marketing;
use App\Entity\Nomenclature;
use App\Tests\ApiTestBase;

/**
 * Recherche publique des codes INAMI (/api/public/inami/*, docs/Design/recherche-inami) :
 * accès sans JWT, données publiques seulement, filtres, recherche, pages, validation, cache,
 * limites de débit et statistiques agrégées.
 */
final class PublicInamiSearchTest extends ApiTestBase
{
    private function code(string $name, ?string $ambulant, ?string $hospital, ?string $n, string $speciality = 'ortho'): Nomenclature
    {
        $code = (new Nomenclature())->setSpeciality($speciality)->setName($name)->setCodeAmbulant($ambulant)->setCodeHospitalisation($hospital)->setN($n);
        $this->em->persist($code);

        return $code;
    }

    private function fixtures(): void
    {
        $this->code('Prothèse totale du genou', null, '290275', 'N588');
        $this->code('Arthroscopie diagnostique du genou', '300292', '300303', 'K120');
        $this->code("Ostéosynthèse d'une fracture de la cheville", '293113', '293124', 'N225');
        $this->code('Cystoscopie diagnostique', '261074', '261085', 'K60', 'uro');
        // Inactif et abrogé : jamais proposés.
        $this->code('Ancienne prothèse du genou', '111111', '111122', 'N100')->deactivate(new \DateTimeImmutable('2026-01-01'));
        $this->code('Code abrogé du genou', '222211', '222222', 'N100')->setInamiRepealedAt(new \DateTimeImmutable('2020-01-01'))->setInamiRepealSource('Moniteur belge')->verifyRepeal(new \DateTimeImmutable('2020-01-02'));
        // Code du rachis partagé avec la neurochirurgie.
        $this->code('Arthrodèse lombaire', '281011', '281022', 'N700')->shareWith('neuro');
        // Spécialité dont tous les codes sont inactifs : jamais proposée.
        $this->code('Greffe ancienne', '999911', '999922', 'N100', 'transp')->deactivate(new \DateTimeImmutable('2026-01-01'));
        $this->em->flush();
    }

    private function get(string $url, ?Client $client = null): array
    {
        $response = ($client ?? static::createClient())->request('GET', $url);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent(false));

        return $response->toArray();
    }

    public function testSpecialitiesArePublicAndListOnlyOfferedOnes(): void
    {
        $this->fixtures();

        $data = $this->get('/api/public/inami/specialities');

        $this->assertSame([
            ['id' => 'neuro', 'count' => 1],
            ['id' => 'ortho', 'count' => 4],
            ['id' => 'uro', 'count' => 1],
        ], $data['specialities']);
    }

    public function testCodesOfASpecialityExposeOnlyPublicFields(): void
    {
        $this->fixtures();

        $data = $this->get('/api/public/inami/codes?speciality=ortho');

        $this->assertSame(4, $data['total']);
        $this->assertSame(['name', 'codeAmbulant', 'codeHospitalisation', 'n', 'speciality'], array_keys($data['items'][0]));
        $names = array_column($data['items'], 'name');
        $this->assertNotContains('Ancienne prothèse du genou', $names, 'Code inactif exposé.');
        $this->assertNotContains('Code abrogé du genou', $names, 'Code abrogé exposé.');
        $this->assertNotContains('Cystoscopie diagnostique', $names, 'Autre spécialité exposée.');
        $this->assertSame(['name' => 'Prothèse totale du genou', 'codeAmbulant' => null, 'codeHospitalisation' => '290275', 'n' => 'N588', 'speciality' => 'ortho'], $data['items'][array_search('Prothèse totale du genou', $names, true)]);
    }

    public function testSharedCodesAndAllSpecialities(): void
    {
        $this->fixtures();

        $neuro = $this->get('/api/public/inami/codes?speciality=neuro');
        $this->assertSame(['Arthrodèse lombaire'], array_column($neuro['items'], 'name'));

        $all = $this->get('/api/public/inami/codes?speciality=all');
        $this->assertSame(5, $all['total'], 'Toutes les spécialités : une ligne par code proposé.');
    }

    public function testSearchByNameIgnoresAccentsCaseAndWordOrder(): void
    {
        $this->fixtures();

        $this->assertSame(['Prothèse totale du genou'], array_column($this->get('/api/public/inami/codes?speciality=ortho&q=GENOU%20PROTHESE')['items'], 'name'));
        $this->assertSame(["Ostéosynthèse d'une fracture de la cheville"], array_column($this->get('/api/public/inami/codes?speciality=ortho&q=osteo')['items'], 'name'));
        // Les noms qui commencent par la recherche viennent d'abord.
        $this->assertSame(['Arthroscopie diagnostique du genou', 'Prothèse totale du genou'], array_column($this->get('/api/public/inami/codes?speciality=ortho&q=genou')['items'], 'name'));
        $this->assertSame(0, $this->get('/api/public/inami/codes?speciality=ortho&q=inconnu')['total']);
    }

    public function testWordRootIsTriedWhenNothingMatchesExactly(): void
    {
        $this->code('Méniscectomie interne ou externe du genou', '300270', '300281', 'K180');
        $this->code("Suture d'une déchirure méniscale", '300292', '300303', 'K120');
        $this->code('Prothèse totale du genou', null, '290275', 'N588');
        $this->em->flush();

        // « ménisque » n'existe pas tel quel : racine « menis », résultats signalés comme approchants.
        $approx = $this->get('/api/public/inami/codes?speciality=ortho&q=m%C3%A9nisque');
        $this->assertTrue($approx['approximate']);
        $this->assertSame(['menis'], $approx['terms']);
        $this->assertSame(["Méniscectomie interne ou externe du genou", "Suture d'une déchirure méniscale"], array_column($approx['items'], 'name'));

        // Résultat exact : jamais de second essai.
        $exact = $this->get('/api/public/inami/codes?speciality=ortho&q=genou%20proth');
        $this->assertFalse($exact['approximate']);
        $this->assertSame(['genou', 'proth'], $exact['terms']);
        $this->assertSame(['Prothèse totale du genou'], array_column($exact['items'], 'name'));

        // Mots courts et codes : pas de racine, aucun résultat inventé.
        $this->assertSame(0, $this->get('/api/public/inami/codes?speciality=ortho&q=hanch')['total']);
        $this->assertFalse($this->get('/api/public/inami/codes?speciality=ortho&q=999999')['approximate']);
    }

    public function testSearchByAmbulatoryOrHospitalisationCode(): void
    {
        $this->fixtures();

        $this->assertSame(['Arthroscopie diagnostique du genou'], array_column($this->get('/api/public/inami/codes?speciality=ortho&q=300292')['items'], 'name'), 'Code ambulatoire.');
        $this->assertSame(['Prothèse totale du genou'], array_column($this->get('/api/public/inami/codes?speciality=ortho&q=290-275')['items'], 'name'), 'Code hospitalisation, tiret ignoré.');
        $this->assertSame(['Arthroscopie diagnostique du genou'], array_column($this->get('/api/public/inami/codes?speciality=ortho&q=30%2003')['items'], 'name'), 'Partie de code, espace ignorée.');
    }

    public function testResultsArePaginatedBy25AndSortable(): void
    {
        for ($i = 1; $i <= 30; ++$i) {
            $this->code(sprintf('Acte %02d', $i), sprintf('3%05d', $i), sprintf('4%05d', $i), sprintf('N%d', $i * 10));
        }
        $this->em->flush();

        $first = $this->get('/api/public/inami/codes?speciality=ortho');
        $this->assertSame([30, 1, 25, 2], [$first['total'], $first['page'], count($first['items']), $first['pages']]);
        $second = $this->get('/api/public/inami/codes?speciality=ortho&page=2');
        $this->assertSame(['Acte 26', 'Acte 27', 'Acte 28', 'Acte 29', 'Acte 30'], array_column($second['items'], 'name'));

        $byNk = $this->get('/api/public/inami/codes?speciality=ortho&sort=nk&order=desc');
        $this->assertSame('N300', $byNk['items'][0]['n']);
        $byName = $this->get('/api/public/inami/codes?speciality=ortho&sort=name&order=desc');
        $this->assertSame('Acte 30', $byName['items'][0]['name']);
    }

    public function testInvalidParametersAreRejected(): void
    {
        $this->fixtures();
        $client = static::createClient();

        foreach ([
            '/api/public/inami/codes' => 'Spécialité inconnue.',
            '/api/public/inami/codes?speciality=cardio' => 'Spécialité inconnue.',
            '/api/public/inami/codes?speciality=transp' => 'Spécialité inconnue.',
            '/api/public/inami/codes?speciality=ORTHO%27--' => 'Spécialité inconnue.',
            '/api/public/inami/codes?speciality=ortho&page=0' => 'Page invalide.',
            '/api/public/inami/codes?speciality=ortho&page=abc' => 'Page invalide.',
            '/api/public/inami/codes?speciality=ortho&page=401' => 'Page invalide.',
            '/api/public/inami/codes?speciality=ortho&sort=id' => 'Tri invalide.',
            '/api/public/inami/codes?speciality=ortho&order=random' => 'Tri invalide.',
            '/api/public/inami/codes?speciality=ortho&q=' . str_repeat('a', 101) => 'Recherche : 100 caractères au maximum.',
            '/api/public/inami/codes?speciality=ortho&q[]=genou' => 'Paramètre invalide.',
            '/api/public/inami/codes?speciality=ortho&limit=1000' => 'Paramètre inconnu.',
            '/api/public/inami/specialities?debug=1' => 'Aucun paramètre attendu.',
        ] as $url => $message) {
            $response = $client->request('GET', $url);
            $this->assertSame(400, $response->getStatusCode(), $url);
            $this->assertSame($message, $response->toArray(false)['message'], $url);
        }
        // Lecture seule.
        $this->assertSame(405, $client->request('POST', '/api/public/inami/codes?speciality=ortho', ['json' => []])->getStatusCode());
    }

    public function testProtectedRoutesStillRequireAuthentication(): void
    {
        $this->fixtures();
        $client = static::createClient();

        foreach (['/api/nomenclature/ortho', '/api/surgeries', '/api/favorites', '/api/users', '/api/admin/nomenclature/ortho', '/api/admin/inami-stats'] as $url) {
            $this->assertSame(401, $client->request('GET', $url)->getStatusCode(), $url);
        }
        $doctor = $this->clientFor($this->createUser('medecin-inami@test.be'));
        $this->assertSame(403, $doctor->request('GET', '/api/admin/inami-stats')->getStatusCode());
        // Un utilisateur connecté obtient la même réponse publique.
        $this->assertSame(4, $this->get('/api/public/inami/codes?speciality=ortho', $doctor)['total']);
    }

    public function testResponsesAreCachedBrieflyAndRevalidated(): void
    {
        $this->fixtures();
        $client = static::createClient();

        $response = $client->request('GET', '/api/public/inami/codes?speciality=ortho');
        $cache = $response->getHeaders()['cache-control'][0];
        $this->assertStringContainsString('public', $cache);
        $this->assertStringContainsString('max-age=60', $cache);
        $etag = $response->getHeaders()['etag'][0];

        $this->assertSame(304, $client->request('GET', '/api/public/inami/codes?speciality=ortho', ['headers' => ['If-None-Match' => $etag]])->getStatusCode());

        // Une modification de la nomenclature change la réponse (nouvelle ETag) : rien n'est figé côté serveur.
        $this->code('Nouvel acte du genou', '310011', '310022', 'N150');
        $this->em->flush();
        $updated = $client->request('GET', '/api/public/inami/codes?speciality=ortho', ['headers' => ['If-None-Match' => $etag]]);
        $this->assertSame(200, $updated->getStatusCode());
        $this->assertSame(5, $updated->toArray()['total']);
    }

    public function testSearchIsRateLimitedPerIp(): void
    {
        $this->fixtures();
        $client = static::createClient();
        $client->disableReboot();

        for ($i = 0; $i < 240; ++$i) {
            $client->request('GET', '/api/public/inami/specialities');
        }
        $this->assertResponseStatusCodeSame(200);
        $client->request('GET', '/api/public/inami/codes?speciality=ortho');
        $this->assertResponseStatusCodeSame(429);
    }

    public function testEventsAreCountedPerDayWithoutPersonalData(): void
    {
        $this->fixtures();
        $client = static::createClient();
        $client->disableReboot();

        foreach ([['type' => 'visit'], ['type' => 'visit'], ['type' => 'speciality', 'speciality' => 'ortho'], ['type' => 'speciality', 'speciality' => 'all']] as $event) {
            $client->request('POST', '/api/public/inami/events', ['json' => $event]);
            $this->assertResponseStatusCodeSame(204);
        }
        foreach ([['type' => 'search', 'q' => 'genou'], ['type' => 'speciality', 'speciality' => 'cardio'], ['type' => 'visit', 'speciality' => 'ortho'], ['type' => 'speciality'], []] as $event) {
            $client->request('POST', '/api/public/inami/events', ['json' => $event]);
            $this->assertResponseStatusCodeSame(400);
        }

        $columns = array_column($this->em->getConnection()->fetchAllAssociative('PRAGMA table_info(inami_search_stat)'), 'name');
        $this->assertSame(['id', 'day', 'kind', 'speciality', 'count'], $columns, 'Aucune colonne pour une IP, un visiteur ou une recherche.');

        $admin = $this->clientFor($this->createUser('admin-inami@test.be', ['ROLE_ADMIN']));
        $summary = $admin->request('GET', '/api/admin/inami-stats?days=7')->toArray();
        $this->assertSame(2, $summary['visits']);
        $this->assertCount(7, $summary['daily']);
        $this->assertSame([['speciality' => 'all', 'count' => 1], ['speciality' => 'ortho', 'count' => 1]], $summary['specialities']);
        $this->assertSame(400, $admin->request('GET', '/api/admin/inami-stats?days=0')->getStatusCode());
    }

    public function testEventsAreRateLimitedPerIp(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        for ($i = 0; $i < 30; ++$i) {
            $client->request('POST', '/api/public/inami/events', ['json' => ['type' => 'visit']]);
        }
        $this->assertResponseStatusCodeSame(204);
        $client->request('POST', '/api/public/inami/events', ['json' => ['type' => 'visit']]);
        $this->assertResponseStatusCodeSame(429);
    }
}
