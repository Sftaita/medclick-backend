<?php

namespace App\Service;

use App\Entity\Marketing;
use App\Entity\MarketingView;
use App\Repository\MarketingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Diffusion des campagnes sponsors (docs/SPONSORS.md) : campagnes affichées par emplacement et
 * enregistrement des impressions et des clics.
 *
 * Sélection d'un emplacement :
 * - campagnes actives ce jour (Europe/Brussels, dates incluses), ciblant l'emplacement et ayant un
 *   visuel pour lui ;
 * - une campagne exclusive l'emporte : elle est seule (la plus prioritaire s'il y en a plusieurs) ;
 * - sinon MAX_ROTATION campagnes au plus. Au-delà, tirage pondéré par la priorité : à priorité égale
 *   chacune a la même chance (rotation équitable), une priorité double est tirée deux fois plus
 *   souvent. Ordre d'affichage : priorité décroissante.
 *
 * Mesure : une impression = publicité visible à au moins 50 % pendant 1 seconde (contrôlé par le
 * front) ; un même visiteur n'est compté qu'une fois par campagne, emplacement et type
 * d'événement sur DEDUP_WINDOW. Visiteur = empreinte HMAC quotidienne (utilisateur connecté ou
 * identifiant aléatoire du navigateur), effacée après 24 h : aucune donnée nominative, aucune IP.
 */
class SponsorDelivery
{
    public const MAX_ROTATION = 3;
    public const DEDUP_WINDOW = 'PT30M';
    public const VIEWER_RETENTION = 'P1D';

    /** @var \Closure(): float */
    private \Closure $random;

    public function __construct(
        private readonly MarketingRepository $campaigns,
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        ?\Closure $random = null,
    ) {
        $this->random = $random ?? static fn (): float => random_int(1, PHP_INT_MAX) / PHP_INT_MAX;
    }

    public function today(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(PartnerManager::TIMEZONE)))->format('Y-m-d');
    }

    /** @return array<string, list<Marketing>> campagnes affichées, par emplacement de carte */
    public function slots(): array
    {
        $live = $this->campaigns->findLiveOn($this->today());
        $slots = [];
        foreach (Marketing::CARD_PLACEMENTS as $placement) {
            $slots[$placement] = $this->select($live, $placement);
        }

        return $slots;
    }

    /**
     * @param list<Marketing> $live
     * @return list<Marketing>
     */
    public function select(array $live, string $placement): array
    {
        $candidates = array_values(array_filter($live, fn (Marketing $m) => $m->hasPlacement($placement) && self::hasVisualFor($m, $placement)));
        $exclusive = array_values(array_filter($candidates, fn (Marketing $m) => $m->isExclusive()));
        if ($exclusive !== []) {
            return [self::byPriority($exclusive)[0]];
        }
        if (count($candidates) > self::MAX_ROTATION) {
            // Tirage pondéré sans remise (Efraimidis-Spirakis) : clé = u^(1/poids), on garde les plus grandes.
            $keyed = array_map(fn (Marketing $m) => [$m, ($this->random)() ** (1 / max(1, $m->getPriority()))], $candidates);
            usort($keyed, static fn (array $a, array $b) => $b[1] <=> $a[1]);
            $candidates = array_map(static fn (array $pair) => $pair[0], array_slice($keyed, 0, self::MAX_ROTATION));
        }

        return self::byPriority($candidates);
    }

    /**
     * Campagne pouvant s'afficher sur l'emplacement aujourd'hui (événement accepté).
     */
    public function isServable(Marketing $campaign, string $placement): bool
    {
        return in_array($placement, Marketing::PLACEMENTS, true)
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
        $now = new \DateTimeImmutable('now', new \DateTimeZone(PartnerManager::TIMEZONE));
        $hash = hash_hmac('sha256', $viewer . '|' . $now->format('Y-m-d'), $this->secret);
        $local = \DateTime::createFromFormat('Y-m-d H:i:s', $now->format('Y-m-d H:i:s'));

        $connection = $this->em->getConnection();
        // Empreintes de plus de 24 h : inutiles au dédoublonnage, effacées.
        $connection->executeStatement(
            'UPDATE marketing_view SET viewer_hash = NULL WHERE viewer_hash IS NOT NULL AND viewed_at < ?',
            [(clone $local)->sub(new \DateInterval(self::VIEWER_RETENTION))],
            [Types::DATETIME_MUTABLE],
        );
        $duplicate = $connection->fetchOne(
            'SELECT 1 FROM marketing_view WHERE marketing_id = ? AND placement = ? AND kind = ? AND viewer_hash = ? AND viewed_at >= ?',
            [$campaign->getId(), $placement, $kind, $hash, (clone $local)->sub(new \DateInterval(self::DEDUP_WINDOW))],
            [Types::INTEGER, Types::STRING, Types::STRING, Types::STRING, Types::DATETIME_MUTABLE],
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
            'sponsor' => $campaign->getSponsorName(),
            'title' => $campaign->getTitle(),
            'description' => $campaign->getDescription(),
            // Nom de fichier : le front le sert via <API>/partner/logo/<logo>.
            'logo' => $campaign->getLogo(),
            'imageDesktop' => $campaign->getBannerDesktop(),
            'imageMobile' => $campaign->getBannerMobile(),
            'url' => $campaign->getRedirectUrl(),
            'cta' => $campaign->hasCta() && $campaign->getRedirectUrl() !== null,
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

    /**
     * @param list<Marketing> $campaigns
     * @return list<Marketing>
     */
    private static function byPriority(array $campaigns): array
    {
        usort($campaigns, static fn (Marketing $a, Marketing $b) => [$b->getPriority(), $a->getId()] <=> [$a->getPriority(), $b->getId()]);

        return $campaigns;
    }
}
