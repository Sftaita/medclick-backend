<?php

namespace App\Tests\Fixtures;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Routes qui échouent volontairement, chargées UNIQUEMENT en environnement de test
 * (config/routes/test/fixtures.yaml) pour vérifier la capture des erreurs.
 */
class FailingController
{
    #[Route('/_test/crash', name: 'test_crash')]
    #[Route('/api/_test/crash', name: 'test_api_crash')]
    public function crash(): Response
    {
        throw new \RuntimeException('Boom de test');
    }

    #[Route('/_test/http-500', name: 'test_http_500')]
    public function http500(): Response
    {
        return new Response('Erreur renvoyée sans exception', 503);
    }

    #[Route('/_test/not-found', name: 'test_not_found')]
    public function notFound(): Response
    {
        throw new NotFoundHttpException('Introuvable');
    }
}
