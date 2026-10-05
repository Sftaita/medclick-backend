<?php

namespace App\Tests\Api;

use App\Entity\Favorites;
use App\Tests\ApiTestBase;

/**
 * Routes personnalisées sous /api/ : aucune ne doit être interceptée par une route générique
 * d'API Platform (ex. /api/favorites/{id}), ce qui est arrivé à getMyList et updateNew après la
 * migration Symfony 8 (ordre de chargement des routes modifié).
 */
class RouteIntegrityTest extends ApiTestBase
{
    public function testEveryCustomApiRouteIsTheOneMatched(): void
    {
        $router = static::getContainer()->get('router');
        $shadowed = [];
        $checked = 0;

        foreach ($router->getRouteCollection() as $name => $route) {
            // _api_* : opérations API Platform ; api_* : routes internes d'API Platform et login_check.
            if (str_starts_with($name, '_api_') || str_starts_with($name, 'api_') || !str_starts_with($route->getPath(), '/api/')) {
                continue;
            }
            $path = preg_replace('/\{[^}]+\}/', '42', $route->getPath());
            foreach ($route->getMethods() ?: ['GET'] as $method) {
                $router->getContext()->setMethod($method);
                $matched = $router->match($path)['_route'];
                $checked++;
                if ($matched !== $name) {
                    $shadowed[] = "$method $path ($name) → $matched";
                }
            }
        }

        $this->assertGreaterThan(30, $checked);
        $this->assertSame([], $shadowed);
    }

    public function testFavoritesListAndUpdateUseTheCustomRoutes(): void
    {
        $alice = $this->createUser('alice@test.be');
        $nomenclature = $this->createNomenclature();
        $favorite = (new Favorites())
            ->setUser($alice)
            ->setSurgery($nomenclature)
            ->setShortcut('PTH')
            ->setSurgeryName($nomenclature->getName())
            ->setCodeHospitalisation('2890851')
            ->setSpeciality('ortho');
        $this->em->persist($favorite);
        $this->em->flush();
        $client = $this->clientFor($alice);

        // Liste (page Favoris, choix « Mes favoris ») : tableau brut, clé « shorcut ».
        $list = $client->request('GET', '/api/favorites/getMyList')->toArray();
        $this->assertResponseIsSuccessful();
        $this->assertSame([['id' => $favorite->getId(), 'surgeryId' => $nomenclature->getId(), 'codeHospitalisation' => '2890851', 'name' => $nomenclature->getName(), 'shorcut' => 'PTH', 'speciality' => 'ortho']], $list);

        // Modification (contrôleur personnalisé, pas le PUT d'API Platform).
        $client->request('PUT', '/api/favorites/updateNew', ['json' => [
            'favoriteId' => $favorite->getId(),
            'surgeryId' => $nomenclature->getId(),
            'shortcut' => 'Hanche',
        ]]);
        $this->assertResponseIsSuccessful();
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $this->assertSame('Hanche', $em->find(Favorites::class, $favorite->getId())->getShortcut());
    }
}
