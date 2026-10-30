<?php

namespace App\Controller\AdminControllers;

use App\Entity\Marketing;
use App\Entity\MarketingImage;
use App\Repository\MarketingImageRepository;
use App\Repository\MarketingRepository;
use App\Repository\PartnerLogoRepository;
use App\Service\SponsorStats;
use App\Service\MarketingImageStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route('/api/admin', name: 'marketing')]
class MarketingController extends AbstractController
{
    private $entityManager;

    public function __construct(
        EntityManagerInterface $entityManager,
        private readonly MarketingImageRepository $images,
        private readonly MarketingImageStore $imageStore,
        private readonly PartnerLogoRepository $logos,
        private readonly SponsorStats $stats,
    ) {
        $this->entityManager = $entityManager;
    }

   #[Route('/marketing', name: 'index', methods: ['GET'])]
    public function index(MarketingRepository $marketingRepository): Response
    {
        $totals = $this->stats->totalsByCampaign();
        $campaigns = array_map(fn (Marketing $m) => $this->serialize($m, $totals[$m->getId()] ?? []), $marketingRepository->findAll());

        return new JsonResponse($campaigns);
    }


    #[Route('/marketing/{id}', name: 'get_campaign', methods: ['GET'])]
    public function getCampaignById(Marketing $marketing): Response
    {
        $totals = $this->stats->totalsByCampaign()[$marketing->getId()] ?? [];

        return new JsonResponse($this->serialize($marketing, $totals));
    }

    /**
     * Format de l'administration. Clés historiques conservées (formats regroupés comme dans la
     * liste, et à plat comme dans le détail) ; views / clicks = compteurs historiques, impressions
     * et tracked_clicks = mesure réelle (docs/SPONSORS.md).
     */
    private function serialize(Marketing $marketing, array $totals): array
    {
        $metrics = SponsorStats::metrics($totals);

        return [
            'id' => $marketing->getId(),
            'campaign_name' => $marketing->getCampaignName(),
            'status' => $marketing->getStatus(),
            'start_date' => $marketing->getStartDate() ? $marketing->getStartDate()->format('Y-m-d') : null,
            'end_date' => $marketing->getEndDate() ? $marketing->getEndDate()->format('Y-m-d') : null,
            'views' => $marketing->getViews(),
            'clicks' => $marketing->getClicks(),
            'impressions' => $metrics['impressions'],
            'tracked_clicks' => $metrics['clicks'],
            'ctr' => $metrics['ctr'],
            'duration' => $marketing->getDuration(),
            'redirect_url' => $marketing->getRedirectUrl(),
            'sponsor_name' => $marketing->getSponsorName(),
            'offer' => $marketing->getOffer(),
            'title' => $marketing->getTitle(),
            'description' => $marketing->getDescription(),
            'logo' => $marketing->getLogo(),
            'banner_desktop' => $marketing->getBannerDesktop(),
            'banner_mobile' => $marketing->getBannerMobile(),
            'show_cta' => $marketing->hasCta(),
            'placements' => $marketing->getPlacements(),
            'priority' => $marketing->getPriority(),
            'exclusive' => $marketing->isExclusive(),
            'professionals_only' => $marketing->isProfessionalsOnly(),
            'formats' => [
                'smartphone' => $marketing->getSmartphoneFormat(),
                'tablet_portrait' => $marketing->getTabletPortraitFormat(),
                'tablet_landscape' => $marketing->getTabletLandscapeFormat(),
                'screen_14_inch' => $marketing->getScreen14InchFormat(),
                'large_screen' => $marketing->getLargeScreenFormat(),
            ],
            'smartphone_format' => $marketing->getSmartphoneFormat(),
            'tablet_portrait_format' => $marketing->getTabletPortraitFormat(),
            'tablet_landscape_format' => $marketing->getTabletLandscapeFormat(),
            'screen_14_inch_format' => $marketing->getScreen14InchFormat(),
            'large_screen_format' => $marketing->getLargeScreenFormat(),
        ];
    }


   #[Route('/marketing', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);
        if ($error = $this->validationError($data)) {
            return $error;
        }

        $marketing = new Marketing();
        $this->hydrate($marketing, $data);

        $this->entityManager->persist($marketing);
        $this->entityManager->flush();
        $this->imageStore->purgeOrphans();

        return $this->json(['status' => 'Marketing campaign created'], Response::HTTP_CREATED);
    }


    #[Route('/marketing/{id}', name: 'edit', methods: ['PUT'])]
    public function edit(Request $request, Marketing $marketing): Response
    {
        $data = json_decode($request->getContent(), true);
        if ($error = $this->validationError($data, $marketing)) {
            return $error;
        }

        // Visuels remplacés ou retirés : supprimés après l'enregistrement s'ils ne servent plus.
        $previous = $this->imageStore->pathsOf($marketing);
        $this->hydrate($marketing, $data);
        $this->entityManager->flush();
        $this->imageStore->release($previous);

        return $this->json(['status' => 'Marketing campaign updated']);
    }

    /**
     * Champs de la campagne (données déjà validées). Une date de fin vide ou absente retire la
     * date de fin (campagne sans échéance) : auparavant, elle ne pouvait plus être effacée.
     */
    private function hydrate(Marketing $marketing, array $data): void
    {
        $marketing->setCampaignName(trim($data['campaign_name']));
        $marketing->setStatus($data['status']);
        $marketing->setStartDate(new \DateTime($data['start_date']));
        $marketing->setEndDate(empty($data['end_date']) ? null : new \DateTime($data['end_date']));
        $marketing->setDuration((int) ($data['duration'] ?? Marketing::DEFAULT_DURATION));
        $marketing->setRedirectUrl(empty($data['redirect_url']) ? null : $data['redirect_url']);
        $marketing->setSmartphoneFormat(empty($data['smartphone_format']) ? null : $data['smartphone_format']);
        $marketing->setTabletPortraitFormat(empty($data['tablet_portrait_format']) ? null : $data['tablet_portrait_format']);
        $marketing->setTabletLandscapeFormat(empty($data['tablet_landscape_format']) ? null : $data['tablet_landscape_format']);
        $marketing->setScreen14InchFormat(empty($data['screen_14_inch_format']) ? null : $data['screen_14_inch_format']);
        $marketing->setLargeScreenFormat(empty($data['large_screen_format']) ? null : $data['large_screen_format']);
        // Champs des espaces sponsors : absents (ancienne administration) = valeurs par défaut,
        // campagne plein écran à la connexion comme avant.
        $text = static fn (string $key) => is_string($data[$key] ?? null) && trim($data[$key]) !== '' ? trim($data[$key]) : null;
        $marketing->setSponsorName($text('sponsor_name'));
        $marketing->setOffer($text('offer'));
        $marketing->setTitle($text('title'));
        $marketing->setDescription($text('description'));
        $marketing->setLogo($text('logo'));
        $marketing->setBannerDesktop($text('banner_desktop'));
        $marketing->setBannerMobile($text('banner_mobile'));
        $marketing->setShowCta((bool) ($data['show_cta'] ?? false));
        $marketing->setPlacements($data['placements'] ?? [Marketing::PLACEMENT_LOGIN_INTERSTITIAL]);
        $marketing->setPriority((int) ($data['priority'] ?? Marketing::DEFAULT_PRIORITY));
        $marketing->setExclusive((bool) ($data['exclusive'] ?? false));
        $marketing->setProfessionalsOnly((bool) ($data['professionals_only'] ?? false));
    }

    private const STATUSES = ['active', 'inactive'];

    private const FORMATS = [
        'smartphone_format' => 'Visuel smartphone',
        'tablet_portrait_format' => 'Visuel tablette portrait',
        'tablet_landscape_format' => 'Visuel tablette paysage',
        'screen_14_inch_format' => 'Visuel ordinateur',
        'large_screen_format' => 'Image principale',
    ];

    private const BANNERS = [
        'banner_desktop' => 'Bannière ordinateur',
        'banner_mobile' => 'Bannière mobile',
    ];

    private const TEXTS = [
        'sponsor_name' => ['Annonceur', 120],
        'title' => ['Titre', 120],
        'description' => ['Description', 280],
    ];

    /**
     * Contrôle des champs : 400 avec un message lisible au lieu d'une erreur 500 (champ manquant,
     * date invalide, texte trop long pour la colonne en mode SQL strict).
     *
     * Visuel accepté : visuel importé (/api/marketing/image/<sha256>.webp, existant), URL http(s)
     * d'une image hébergée (anciennes campagnes, ancienne interface), ou valeur déjà enregistrée
     * renvoyée inchangée (une ancienne campagne reste modifiable sans perte de ses visuels, même
     * « Test Arthrex » et ses images collées tronquées). Une nouvelle image collée en data:… est
     * refusée : elle dépasse 500 caractères et serait tronquée, donc illisible.
     */
    private function validationError(mixed $data, ?Marketing $current = null): ?JsonResponse
    {
        $fail = static fn (string $message) => new JsonResponse(['message' => $message], Response::HTTP_BAD_REQUEST);

        if (!is_array($data)) {
            return $fail('Requête invalide.');
        }
        $name = $data['campaign_name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            return $fail('Le nom de la campagne est obligatoire.');
        }
        if (mb_strlen(trim($name)) > 400) {
            return $fail('Le nom de la campagne ne doit pas dépasser 400 caractères.');
        }
        if (!in_array($data['status'] ?? null, self::STATUSES, true)) {
            return $fail('Le statut doit être « active » ou « inactive ».');
        }
        $start = self::date($data['start_date'] ?? null);
        if (!$start) {
            return $fail('La date de début est obligatoire (AAAA-MM-JJ).');
        }
        if (!empty($data['end_date'])) {
            $end = self::date($data['end_date']);
            if (!$end) {
                return $fail('La date de fin est invalide (AAAA-MM-JJ).');
            }
            if ($end < $start) {
                return $fail('La date de fin doit suivre la date de début.');
            }
        }
        // Durée d'affichage à la connexion : 3, 5, 10, 15 ou 30 s proposées, ou valeur personnalisée.
        $duration = $data['duration'] ?? Marketing::DEFAULT_DURATION;
        if (filter_var($duration, FILTER_VALIDATE_INT, ['options' => ['min_range' => Marketing::MIN_DURATION, 'max_range' => Marketing::MAX_DURATION]]) === false) {
            return $fail(sprintf("La durée d'affichage doit être un nombre entier de %d à %d secondes.", Marketing::MIN_DURATION, Marketing::MAX_DURATION));
        }
        if (!empty($data['redirect_url']) && !self::isHttpUrl($data['redirect_url'])) {
            return $fail("L'URL de redirection doit commencer par https:// ou http:// (500 caractères maximum).");
        }
        foreach (self::TEXTS as $key => [$label, $max]) {
            if (isset($data[$key]) && (!is_string($data[$key]) || mb_strlen(trim($data[$key])) > $max)) {
                return $fail(sprintf('%s : %d caractères au maximum.', $label, $max));
            }
        }
        if (!empty($data['offer']) && !in_array($data['offer'], Marketing::OFFERS, true)) {
            return $fail('Offre inconnue.');
        }
        $placements = $data['placements'] ?? [Marketing::PLACEMENT_LOGIN_INTERSTITIAL];
        if (!is_array($placements) || $placements === [] || array_diff($placements, Marketing::PLACEMENTS) !== []) {
            return $fail("Choisissez au moins un emplacement d'affichage.");
        }
        $priority = $data['priority'] ?? Marketing::DEFAULT_PRIORITY;
        if (filter_var($priority, FILTER_VALIDATE_INT, ['options' => ['min_range' => Marketing::MIN_PRIORITY, 'max_range' => Marketing::MAX_PRIORITY]]) === false) {
            return $fail(sprintf('La priorité doit être un nombre entier de %d à %d.', Marketing::MIN_PRIORITY, Marketing::MAX_PRIORITY));
        }
        foreach (['show_cta', 'exclusive', 'professionals_only'] as $key) {
            if (isset($data[$key]) && !is_bool($data[$key])) {
                return $fail('Valeur oui / non attendue.');
            }
        }
        // Page de connexion : vue par des visiteurs non connectés.
        if (!empty($data['professionals_only']) && in_array(Marketing::PLACEMENT_LOGIN, $placements, true)) {
            return $fail("Publicité réservée aux professionnels : la page de connexion est publique. Choisissez l'Accueil ou le plein écran (affiché après la connexion).");
        }
        if (!empty($data['show_cta']) && empty($data['redirect_url'])) {
            return $fail("Indiquez le site de l'annonceur pour afficher le bouton « Découvrir le partenaire ».");
        }
        if (!empty($data['logo']) && (!is_string($data['logo']) || $this->logos->findByFileName($data['logo']) === null)) {
            return $fail('Logo introuvable : envoyez-le de nouveau.');
        }

        $stored = $current !== null ? MarketingImageStore::imageValues($current) : [];
        $hasVisual = false;
        $hasBanner = false;
        foreach (self::FORMATS + self::BANNERS as $key => $label) {
            if (empty($data[$key])) {
                continue;
            }
            $value = $data[$key];
            if (isset(self::BANNERS[$key])) {
                $hasBanner = true;
            } else {
                $hasVisual = true;
            }
            if (isset($stored[$key]) && $value === $stored[$key]) {
                continue;
            }
            if (is_string($value) && str_starts_with($value, MarketingImage::PATH_PREFIX)) {
                if ($this->images->findByPath($value) === null) {
                    return $fail(sprintf('%s : visuel introuvable, importez-le de nouveau.', $label));
                }
                continue;
            }
            if (!self::isHttpUrl($value)) {
                return $fail(sprintf("%s : importez une image PNG, JPEG ou WebP (ou indiquez l'URL https:// d'une image hébergée ; pas d'image collée).", $label));
            }
        }
        if (in_array(Marketing::PLACEMENT_LOGIN_INTERSTITIAL, $placements, true) && !$hasVisual) {
            return $fail('Importez au moins un visuel (image principale).');
        }
        if (array_intersect(Marketing::CARD_PLACEMENTS, $placements) !== [] && !$hasBanner) {
            return $fail('Importez au moins une bannière (ordinateur ou mobile).');
        }
        if (!empty($data['exclusive']) && ($data['status'] ?? null) === 'active') {
            return $this->exclusivityConflict($placements, $start, empty($data['end_date']) ? null : self::date($data['end_date']), $current);
        }

        return null;
    }

    /**
     * Deux campagnes exclusives actives ne peuvent pas partager un emplacement sur des périodes
     * qui se chevauchent (une seule serait affichée) : refus avec le nom de l'autre campagne.
     *
     * @param list<string> $placements
     */
    private function exclusivityConflict(array $placements, \DateTimeImmutable $start, ?\DateTimeImmutable $end, ?Marketing $current): ?JsonResponse
    {
        $others = $this->entityManager->getRepository(Marketing::class)->findBy(['status' => 'active', 'exclusive' => true]);
        foreach ($others as $other) {
            if ($current !== null && $other->getId() === $current->getId()) {
                continue;
            }
            $shared = array_intersect($placements, $other->getPlacements());
            $otherEnd = $other->getEndDate()?->format('Y-m-d');
            $overlaps = ($end === null || $other->getStartDate()->format('Y-m-d') <= $end->format('Y-m-d'))
                && ($otherEnd === null || $otherEnd >= $start->format('Y-m-d'));
            if ($shared !== [] && $overlaps) {
                return new JsonResponse(['message' => sprintf(
                    'Exclusivité impossible : « %s » est déjà exclusive sur %s pendant cette période. Modifiez les dates ou les emplacements.',
                    $other->getCampaignName(),
                    implode(', ', array_map(static fn (string $p) => SponsorStats::PLACEMENT_LABELS[$p], $shared)),
                )], Response::HTTP_BAD_REQUEST);
            }
        }

        return null;
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    private static function isHttpUrl(mixed $value): bool
    {
        return is_string($value)
            && strlen($value) <= 500
            && filter_var($value, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }


    #[Route('/marketing/{id}', name: 'delete_marketing_campaign', methods: ['DELETE'])]
    public function delete(Marketing $marketing, EntityManagerInterface $entityManager): JsonResponse
    {
        // Historique des affichages (marketing_view, clé étrangère sans cascade) : supprimé avec la
        // campagne, sinon une campagne déjà affichée ne pouvait pas être supprimée (erreur 500).
        $entityManager->createQuery('DELETE FROM App\Entity\MarketingView v WHERE v.marketing = :marketing')
            ->setParameter('marketing', $marketing)
            ->execute();
        $images = $this->imageStore->pathsOf($marketing);
        $entityManager->remove($marketing);
        $entityManager->flush();
        // Visuels importés de la campagne : supprimés avec elle s'ils ne servent à aucune autre.
        $this->imageStore->release($images);

        return new JsonResponse(['status' => 'Marketing campaign deleted']);
    }

    #[Route('/marketing/{id}/status', name: 'update_status', methods: ['PUT'])]
    public function updateStatus(Request $request, Marketing $marketing): Response
    {
        $data = json_decode($request->getContent(), true);

        // On s'assure que le champ 'status' est présent dans la requête
        if (!isset($data['status'])) {
            return new JsonResponse(['error' => 'Status field is required'], Response::HTTP_BAD_REQUEST);
        }
        if (!in_array($data['status'], self::STATUSES, true)) {
            return new JsonResponse(['message' => 'Le statut doit être « active » ou « inactive ».'], Response::HTTP_BAD_REQUEST);
        }

        // Activation d'une campagne exclusive : mêmes contrôles que l'enregistrement complet.
        if ($data['status'] === 'active' && $marketing->isExclusive() && $marketing->getStartDate() !== null) {
            $conflict = $this->exclusivityConflict(
                $marketing->getPlacements(),
                \DateTimeImmutable::createFromInterface($marketing->getStartDate()),
                $marketing->getEndDate() !== null ? \DateTimeImmutable::createFromInterface($marketing->getEndDate()) : null,
                $marketing,
            );
            if ($conflict !== null) {
                return $conflict;
            }
        }

        // Met à jour le statut de la campagne
        $marketing->setStatus($data['status']);
        $this->entityManager->flush();

        return new JsonResponse(['status' => 'Marketing campaign status updated']);
    }
}
