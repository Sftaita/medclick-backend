<?php

namespace App\Service;

use App\Advertising\Emplacements;
use App\Entity\Marketing;
use App\Entity\MarketingView;
use App\Repository\MarketingRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Diffusion des publicités (docs/PUBLICITES-PARTENAIRES.md) : publicités affichées par
 * emplacement et enregistrement des impressions et des clics.
 *
 * Sélection d'un placement, un jour donné (Europe/Brussels, dates incluses) :
 * - emplacement désactivé dans l'administration : rien ;
 * - publicités publiées (statut actif) dont la période couvre le jour, ciblant le placement et
 *   ayant un visuel pour lui ;
 * - ordre fixé dans l'administration (glisser-déposer), puis priorité, puis ancienneté ;
 * - une publicité « seule dans le carrousel » (exclusive) est affichée seule ;
 * - sinon la capacité de l'emplacement au plus (3 pour les carrousels, 1 pour le plein écran).
 *   La publication refuse un dépassement : aucun tirage au sort, l'affichage est prévisible.
 *
 * Mesure : une impression = publicité visible à au moins 50 % pendant 1 seconde (contrôlé par le
 * front) ; un même visiteur n'est compté qu'une fois par campagne, emplacement et type
 * d'événement sur DEDUP_WINDOW, y compris de part et d'autre de minuit. Visiteur = empreinte HMAC
 * quotidienne (utilisateur connecté ou identifiant aléatoire du navigateur), effacée après 24 h :
 * aucune donnée nominative, aucune IP.
 */
class SponsorDelivery
{
    /** Capacité des carrousels (Emplacements : capacity). */
    public const MAX_ROTATION = 3;
    public const DEDUP_WINDOW = 'PT30M';
    public const VIEWER_RETENTION = 'P1D';

    public function __construct(
        private readonly MarketingRepository $campaigns,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly EmplacementSettings $settings,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
    ) {
    }

    /** Heure de Belgique (jours de diffusion, dédoublonnage). */
    public function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone(PartnerManager::TIMEZONE));
    }

    public function today(): string
    {
        return $this->now()->format('Y-m-d');
    }

    /**
     * Publicités affichées, par placement en carte. Visiteur non connecté : jamais de publicité
     * réservée aux professionnels.
     *
     * @return array<string, list<Marketing>>
     */
    public function slots(bool $authenticated = false): array
    {
        $live = $this->audience($this->campaigns->findLiveOn($this->today()), $authenticated);
        $slots = [];
        foreach (Marketing::CARD_PLACEMENTS as $placement) {
            $slots[$placement] = $this->selectFor($live, $placement, $this->today());
        }

        return $slots;
    }

    /**
     * Sélection d'un placement avec les réglages publiés de son emplacement (activé, ordre).
     *
     * @param list<Marketing> $campaigns
     * @return list<Marketing>
     */
    public function selectFor(array $campaigns, string $placement, string $day, ?array $order = null, bool $ignoreEnabled = false): array
    {
        $emplacement = Emplacements::forCampaignPlacement($placement);
        if ($emplacement === null) {
            return [];
        }
        $setting = $this->settings->get($emplacement);
        if (!$ignoreEnabled && !$setting->isEnabled()) {
            return [];
        }

        return self::pick($campaigns, $placement, $day, $order ?? $setting->getDisplayOrder(), Emplacements::get($emplacement)['capacity']);
    }

    /**
     * @param list<Marketing> $campaigns
     * @param list<int> $order
     * @return list<Marketing>
     */
    public static function pick(array $campaigns, string $placement, string $day, array $order, int $capacity): array
    {
        $candidates = array_values(array_filter($campaigns, static fn (Marketing $m) => $m->isLiveOn($day) && $m->hasPlacement($placement) && self::hasVisualFor($m, $placement)));
        $candidates = self::ordered($candidates, $order);
        foreach ($candidates as $campaign) {
            if ($campaign->isExclusive()) {
                return [$campaign];
            }
        }

        return array_slice($candidates, 0, $capacity);
    }

    /**
     * Ordre de l'administration, puis priorité décroissante, puis ancienneté.
     *
     * @param list<Marketing> $campaigns
     * @param list<int> $order
     * @return list<Marketing>
     */
    public static function ordered(array $campaigns, array $order): array
    {
        $rank = array_flip($order);
        usort($campaigns, static fn (Marketing $a, Marketing $b) => [$rank[$a->getId()] ?? PHP_INT_MAX, $b->getPriority(), $a->getId() ?? PHP_INT_MAX]
            <=> [$rank[$b->getId()] ?? PHP_INT_MAX, $a->getPriority(), $b->getId() ?? PHP_INT_MAX]);

        return $campaigns;
    }

    /** Plein écran après la connexion : une seule publicité (capacité 1), ou null. */
    public function interstitial(): ?Marketing
    {
        return $this->selectFor($this->campaigns->findLiveOn($this->today()), Marketing::PLACEMENT_LOGIN_INTERSTITIAL, $this->today())[0] ?? null;
    }

    /** Fréquence du plein écran (Emplacements::FREQUENCIES). */
    public function interstitialFrequency(): string
    {
        return $this->settings->get(Emplacements::AFTER_LOGIN)->getFrequency();
    }

    /**
     * @param list<Marketing> $campaigns
     * @return list<Marketing>
     */
    private function audience(array $campaigns, bool $authenticated): array
    {
        return $authenticated ? $campaigns : array_values(array_filter($campaigns, static fn (Marketing $m) => !$m->isProfessionalsOnly()));
    }

    /**
     * Campagne pouvant s'afficher sur l'emplacement aujourd'hui (événement accepté). Visiteur
     * non connecté : jamais une campagne réservée aux professionnels.
     */
    public function isServable(Marketing $campaign, string $placement, bool $authenticated = true): bool
    {
        $emplacement = Emplacements::forCampaignPlacement($placement);

        return in_array($placement, Marketing::PLACEMENTS, true)
            && $emplacement !== null && $this->settings->isEnabled($emplacement)
            && ($authenticated || !$campaign->isProfessionalsOnly())
            && $campaign->isLiveOn($this->today())
            && $campaign->hasPlacement($placement)
            && self::hasVisualFor($campaign, $placement);
    }

    /**
     * Enregistre une impression ou un clic, sauf doublon du même visiteur sur DEDUP_WINDOW.
     *
     * @param string $viewer identifiant brut (« u:<id> » ou « a:<aléatoire> »), jamais stocké tel quel
     * @return bool true si l'événement a été compté
     */
    public function record(Marketing $campaign, string $placement, string $kind, string $viewer): bool
    {
        $now = $this->now();
        $since = $now->sub(new \DateInterval(self::DEDUP_WINDOW));
        // L'empreinte change chaque jour : juste après minuit, la fenêtre couvre aussi la veille.
        $hashes = array_values(array_unique(array_map(
            fn (string $day) => hash_hmac('sha256', $viewer . '|' . $day, $this->secret),
            [$now->format('Y-m-d'), $since->format('Y-m-d')],
        )));
        $hash = $hashes[0];
        $local = \DateTime::createFromFormat('Y-m-d H:i:s', $now->format('Y-m-d H:i:s'));

        $connection = $this->em->getConnection();
        // Empreintes de plus de 24 h : inutiles au dédoublonnage, effacées.
        $connection->executeStatement(
            'UPDATE marketing_view SET viewer_hash = NULL WHERE viewer_hash IS NOT NULL AND viewed_at < ?',
            [(clone $local)->sub(new \DateInterval(self::VIEWER_RETENTION))],
            [Types::DATETIME_MUTABLE],
        );
        $duplicate = $connection->fetchOne(
            'SELECT 1 FROM marketing_view WHERE marketing_id = ? AND placement = ? AND kind = ? AND viewer_hash IN (?) AND viewed_at >= ?',
            [$campaign->getId(), $placement, $kind, $hashes, \DateTime::createFromFormat('Y-m-d H:i:s', $since->format('Y-m-d H:i:s'))],
            [Types::INTEGER, Types::STRING, Types::STRING, ArrayParameterType::STRING, Types::DATETIME_MUTABLE],
        );
        if ($duplicate !== false) {
            return false;
        }

        $event = (new MarketingView())
            ->setMarketing($campaign)
            ->setViewedAt($local)
            ->setKind($kind)
            ->setPlacement($placement)
            ->setViewerHash($hash);
        $this->em->persist($event);
        $this->em->flush();

        return true;
    }

    /** Données publiques d'une campagne (aucune statistique, aucun réglage interne). */
    public function toPublicArray(Marketing $campaign): array
    {
        return [
            'id' => $campaign->getId(),
            'sponsor' => $campaign->getPartner()?->getName() ?? $campaign->getSponsorName(),
            'title' => $campaign->getTitle(),
            'description' => $campaign->getDescription(),
            // Nom de fichier : le front le sert via <API>/partner/logo/<logo>.
            'logo' => $campaign->getLogo() ?? $campaign->getPartner()?->getLogo()?->getFileName(),
            'imageDesktop' => $campaign->getBannerDesktop(),
            'imageMobile' => $campaign->getBannerMobile(),
            'url' => $campaign->getRedirectUrl(),
            // Bouton « Découvrir » : toujours présent quand un lien est configuré (jamais inactif).
            'cta' => $campaign->getRedirectUrl() !== null,
            'exclusive' => $campaign->isExclusive(),
        ];
    }

    /** Données publiques du plein écran (GET /api/marketing/active, aperçu de l'administration). */
    public function toInterstitialArray(Marketing $campaign): array
    {
        return [
            'id' => $campaign->getId(),
            'campaign_name' => $campaign->getCampaignName(),
            'status' => $campaign->getStatus(),
            'start_date' => $campaign->getStartDate()?->format('Y-m-d'),
            'end_date' => $campaign->getEndDate()?->format('Y-m-d'),
            'views' => $campaign->getViews(), // Compteur historique (figé)
            'clicks' => $campaign->getClicks(),
            'duration' => $campaign->getDuration(),
            'formats' => [
                'smartphone' => $campaign->getSmartphoneFormat(),
                'tablet_portrait' => $campaign->getTabletPortraitFormat(),
                'tablet_landscape' => $campaign->getTabletLandscapeFormat(),
                'screen_14_inch' => $campaign->getScreen14InchFormat(),
                'large_screen' => $campaign->getLargeScreenFormat(),
            ],
            'redirect_url' => $campaign->getRedirectUrl(),
            // Fréquence d'affichage par navigateur (every_login, daily, weekly), réglée dans
            // l'administration ; appliquée par le front.
            'frequency' => $this->interstitialFrequency(),
            'sponsor' => $campaign->getPartner()?->getName() ?? $campaign->getSponsorName(),
        ];
    }

    public static function hasVisualFor(Marketing $campaign, string $placement): bool
    {
        if ($placement === Marketing::PLACEMENT_LOGIN_INTERSTITIAL) {
            return array_filter([
                $campaign->getSmartphoneFormat(), $campaign->getTabletPortraitFormat(), $campaign->getTabletLandscapeFormat(),
                $campaign->getScreen14InchFormat(), $campaign->getLargeScreenFormat(),
            ]) !== [];
        }

        return $campaign->getBannerDesktop() !== null || $campaign->getBannerMobile() !== null;
    }
}
