<?php

namespace App\Controller;

use App\Entity\ErrorLog;
use App\Service\ErrorRecorder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Réception des erreurs rencontrées dans les fronts (plantage JavaScript, requête API en échec).
 *
 * Accessible sans connexion (une erreur peut survenir avant le login) ; limité en débit par
 * App\Security\PublicEndpointRateLimiter. Si un JWT est envoyé, l'utilisateur est rattaché.
 *
 * Corps attendu (JSON) :
 *   { "source": "pwa"|"admin", "message": "...", "stack": "...", "url": "https://...",
 *     "context": { "status": 500, "method": "GET", "endpoint": "/api/years", "appVersion": "3.1" } }
 */
class ClientErrorController
{
    private const SOURCES = [ErrorLog::SOURCE_PWA, ErrorLog::SOURCE_ADMIN];
    private const MAX_BODY = 32768;

    #[Route('/api/client-errors', name: 'client_errors', methods: ['POST'])]
    public function report(Request $request, ErrorRecorder $recorder): Response
    {
        if (strlen($request->getContent()) > self::MAX_BODY) {
            return new JsonResponse(['message' => 'Rapport trop volumineux'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)
            || !in_array($data['source'] ?? null, self::SOURCES, true)
            || !is_string($data['message'] ?? null)
            || trim($data['message']) === ''
        ) {
            return new JsonResponse(['message' => 'Rapport d\'erreur invalide'], Response::HTTP_BAD_REQUEST);
        }

        $context = is_array($data['context'] ?? null) ? $data['context'] : null;
        if ($context !== null) {
            // Uniquement des valeurs scalaires courtes : pas de données métier ni de corps de requête.
            $context = array_map(
                fn ($value) => is_scalar($value) ? mb_substr((string) $value, 0, 300) : null,
                array_slice($context, 0, 20, true)
            );
        }

        $recorder->recordClientError([
            'source' => $data['source'],
            'message' => $data['message'],
            'stack' => is_string($data['stack'] ?? null) ? $data['stack'] : null,
            'url' => is_string($data['url'] ?? null) ? $data['url'] : null,
            'context' => $context,
        ], $request);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
