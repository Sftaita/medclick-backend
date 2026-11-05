<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\InamiSearchStat;
use App\Service\InamiSearchStats;
use App\Service\PublicNomenclatureSearch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Recherche publique des codes INAMI (/codes-inami du front), sans compte ni JWT
 * (security.yaml), limitée en débit par adresse IP (PublicEndpointRateLimiter).
 *
 * Lecture seule de la nomenclature actuelle, champs publics seulement (nom, codes, N/K,
 * spécialité) : aucun accès aux utilisateurs, interventions, favoris ni identifiants internes.
 * Cache HTTP court (CACHE_SECONDS) : une modification de la nomenclature est visible en une minute
 * au plus. Les anciennes routes protégées (/api/nomenclature/{speciality}) restent protégées.
 */
final class PublicInamiController extends AbstractController
{
    public const CACHE_SECONDS = 60;
    private const SPECIALITY = '/^[a-z_]{2,32}$/';
    private const CODE_PARAMS = ['speciality', 'q', 'page', 'sort', 'order'];

    #[Route('/api/public/inami/specialities', name: 'public_inami_specialities', methods: ['GET'])]
    public function specialities(Request $request, PublicNomenclatureSearch $search): JsonResponse
    {
        if ($request->query->count() > 0) {
            return self::invalid('Aucun paramètre attendu.');
        }

        return $this->cached($request, ['specialities' => $search->specialities()]);
    }

    /**
     * ?speciality=<id>|all [&q=texte ou code] [&page=1] [&sort=relevance|name|nk] [&order=asc|desc]
     * → { items: [{ name, codeAmbulant, codeHospitalisation, n, speciality }], total, page, pageSize, pages }
     */
    #[Route('/api/public/inami/codes', name: 'public_inami_codes', methods: ['GET'])]
    public function codes(Request $request, PublicNomenclatureSearch $search): JsonResponse
    {
        $query = $request->query;
        $unknown = array_diff(array_keys($query->all()), self::CODE_PARAMS);
        if ($unknown !== []) {
            return self::invalid('Paramètre inconnu.');
        }
        foreach (self::CODE_PARAMS as $name) {
            if ($query->has($name) && !is_string($query->all()[$name])) {
                return self::invalid('Paramètre invalide.');
            }
        }

        $speciality = (string) $query->get('speciality', '');
        if ($speciality !== PublicNomenclatureSearch::ALL && !$this->offered($speciality, $search)) {
            return self::invalid('Spécialité inconnue.');
        }
        $text = (string) $query->get('q', '');
        if (mb_strlen($text) > PublicNomenclatureSearch::MAX_QUERY_LENGTH || preg_match('/[\x00-\x1F\x7F]/u', $text) !== 0 || !mb_check_encoding($text, 'UTF-8')) {
            return self::invalid(sprintf('Recherche : %d caractères au maximum.', PublicNomenclatureSearch::MAX_QUERY_LENGTH));
        }
        $page = filter_var($query->get('page', '1'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => PublicNomenclatureSearch::MAX_PAGE]]);
        if ($page === false) {
            return self::invalid('Page invalide.');
        }
        $sort = (string) $query->get('sort', 'relevance');
        $order = (string) $query->get('order', 'asc');
        if (!in_array($sort, PublicNomenclatureSearch::SORTS, true) || !in_array($order, PublicNomenclatureSearch::ORDERS, true)) {
            return self::invalid('Tri invalide.');
        }

        return $this->cached($request, $search->search($speciality, $text, $sort, $order, $page));
    }

    /**
     * Statistiques agrégées par jour : { type: "visit" } ou { type: "speciality", speciality }.
     * Ni visiteur, ni adresse IP, ni terme de recherche ne sont enregistrés.
     */
    #[Route('/api/public/inami/events', name: 'public_inami_event', methods: ['POST'])]
    public function event(Request $request, InamiSearchStats $stats, PublicNomenclatureSearch $search): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || array_diff(array_keys($data), ['type', 'speciality']) !== []) {
            return self::invalid('Requête invalide.');
        }
        $type = $data['type'] ?? null;
        if ($type === InamiSearchStat::KIND_VISIT && !isset($data['speciality'])) {
            $stats->record(InamiSearchStat::KIND_VISIT);
        } elseif ($type === InamiSearchStat::KIND_SPECIALITY && is_string($data['speciality'] ?? null)
            && ($data['speciality'] === PublicNomenclatureSearch::ALL || $this->offered($data['speciality'], $search))) {
            $stats->record(InamiSearchStat::KIND_SPECIALITY, $data['speciality']);
        } else {
            return self::invalid('Événement inconnu.');
        }

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }

    private function offered(string $speciality, PublicNomenclatureSearch $search): bool
    {
        return preg_match(self::SPECIALITY, $speciality) === 1
            && in_array($speciality, array_column($search->specialities(), 'id'), true);
    }

    /** Réponse publique en cache court, revalidée par ETag (304 si inchangée). */
    private function cached(Request $request, array $data): JsonResponse
    {
        $response = new JsonResponse($data);
        $response->setPublic();
        $response->setMaxAge(self::CACHE_SECONDS);
        $response->setEtag(hash('xxh128', (string) $response->getContent()));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->isNotModified($request);

        return $response;
    }

    private static function invalid(string $message): JsonResponse
    {
        return new JsonResponse(['message' => $message], JsonResponse::HTTP_BAD_REQUEST);
    }
}
