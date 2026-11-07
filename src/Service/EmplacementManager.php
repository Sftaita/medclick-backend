<?php

declare(strict_types=1);

namespace App\Service;

use App\Advertising\Emplacements;
use App\Advertising\Timeline;
use App\Entity\Marketing;
use App\Entity\MarketingImage;
use App\Entity\Partner;
use App\Entity\PartnerPlacementAssignment as Assignment;
use App\Repository\MarketingImageRepository;
use App\Repository\MarketingRepository;
use App\Repository\PartnerPlacementAssignmentRepository;
use App\Repository\PartnerRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Administration par emplacement (docs/PUBLICITES-PARTENAIRES.md) : contenus, brouillons,
 * aperçu « Actuellement en ligne » / « Mes modifications », calendrier, conflits et publication.
 *
 * Contenus : publicités (Marketing) pour les emplacements « ads », attributions d'entreprise
 * (PartnerPlacementAssignment) pour les emplacements « partner » et l'encart partenaire de la
 * page d'identification.
 *
 * Rien de ce qui est enregistré ici ne change l'affichage public avant la publication :
 * - un nouveau contenu est un brouillon (publicité au statut « draft », attribution « draft ») ;
 * - la modification d'un contenu publié est rangée dans pendingChanges ;
 * - le nouvel ordre est rangé dans AdPlacementSetting::pendingOrder.
 * Seules exceptions, immédiates par nature : « Désactiver » (arrêt de diffusion) et les réglages
 * de l'emplacement (activé, fréquence).
 */
final class EmplacementManager
{
    public const SUPPORTS = ['desktop', 'mobile'];
    public const CALENDAR_MONTHS = 12;
    public const MIN_DURATION = 3;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MarketingRepository $campaigns,
        private readonly PartnerRepository $partners,
        private readonly PartnerPlacementAssignmentRepository $assignments,
        private readonly PartnerPlacementManager $placements,
        private readonly SponsorDelivery $delivery,
        private readonly EmplacementSettings $settings,
        private readonly MarketingImageRepository $images,
        private readonly MarketingImageStore $imageStore,
    ) {
    }

    public function today(): string
    {
        return $this->delivery->today();
    }

    // ------------------------------------------------------------------ Lecture

    /** @return list<array<string, mixed>> */
    public function overview(): array
    {
        return array_map($this->summary(...), Emplacements::keys());
    }

    /** @return array<string, mixed> */
    public function summary(string $key): array
    {
        $definition = Emplacements::get($key);
        $setting = $this->settings->get($key);
        $today = $this->today();
        [$live, $next] = $this->liveAndNext($key, $today);
        $pending = $this->pendingCount($key);

        return [
            'key' => $key,
            'label' => $definition['label'],
            'description' => $definition['description'],
            'screen' => $definition['screen'],
            'screenLabel' => Emplacements::SCREENS[$definition['screen']],
            'kind' => $definition['kind'],
            'capacity' => $definition['capacity'] ?? (($definition['shared'] ?? false) ? null : 1),
            'shared' => $definition['shared'] ?? false,
            'public' => $definition['public'],
            'supports' => $definition['kind'] === 'ads' ? $this->supportsOf($key) : [],
            'hasPartnerFallback' => isset($definition['partnerFallback']),
            'enabled' => $setting->isEnabled(),
            'frequency' => $key === Emplacements::AFTER_LOGIN ? $setting->getFrequency() : null,
            'live' => $live,
            'next' => $next,
            'pendingCount' => $pending,
            'conflictCount' => count($this->conflicts($key)),
        ];
    }

    /**
     * Fiche complète d'un emplacement ; $date : jour de l'aperçu (aujourd'hui par défaut).
     *
     * @return array<string, mixed>
     */
    public function detail(string $key, ?string $date = null): array
    {
        $today = $this->today();
        $date ??= $today;
        $setting = $this->settings->get($key);

        return $this->summary($key) + [
            'today' => $today,
            'frequencies' => $key === Emplacements::AFTER_LOGIN ? Emplacements::FREQUENCIES : null,
            'recommended' => self::recommended($key),
            'contents' => $this->contents($key),
            'orderChanged' => $setting->getPendingOrder() !== null,
            'preview' => [
                'date' => $date,
                'live' => $this->preview($key, $date, false),
                'draft' => $this->preview($key, $date, true),
            ],
            'calendar' => $this->calendar($key),
            'conflicts' => $this->conflicts($key),
        ];
    }

    /**
     * Dimensions recommandées, identiques aux rendus réels (features/ads/adFormats.js et
     * AdOverlay du front).
     */
    public static function recommended(string $key): ?array
    {
        return match ($key) {
            Emplacements::LOGIN, Emplacements::DASHBOARD_CAROUSEL => [
                'desktop' => ['ratio' => '16:9', 'width' => 1600, 'height' => 900, 'label' => 'Ordinateur et tablette'],
                'mobile' => ['ratio' => '2:1', 'width' => 1200, 'height' => 600, 'label' => 'Téléphone'],
            ],
            Emplacements::AFTER_LOGIN => [
                'desktop' => ['ratio' => '16:9', 'width' => 1920, 'height' => 1080, 'label' => 'Ordinateur et tablette en paysage'],
                'mobile' => ['ratio' => '9:16', 'width' => 1080, 'height' => 1920, 'label' => 'Téléphone et tablette en portrait'],
            ],
            // Affichées entières (contain) ; import avec ?format=banner (480 × 120 px au minimum : les
            // anciennes bannières 1200 × 150 restent acceptées, affichées à leurs proportions).
            Emplacements::INAMI_SEARCH => [
                'desktop' => ['ratio' => '4:1', 'width' => 1200, 'height' => 300, 'label' => 'Ordinateur et tablette'],
                'mobile' => ['ratio' => '16:5', 'width' => 640, 'height' => 200, 'label' => 'Téléphone'],
            ],
            default => null,
        };
    }

    // ------------------------------------------------------------------ Contenus

    /** @return list<array<string, mixed>> */
    private function contents(string $key): array
    {
        $rows = [];
        if (Emplacements::isAds($key)) {
            $stats = $this->adStats(array_map(static fn (Marketing $m) => $m->getId(), $this->campaignsOf($key)), Emplacements::campaignPlacements($key));
            foreach ($this->orderedCampaigns($key, true) as $campaign) {
                $rows[] = $this->adArray($key, $campaign, $stats[$campaign->getId()] ?? []);
            }
        }
        $placement = Emplacements::partnerPlacement($key);
        if ($placement !== null) {
            foreach ($this->assignmentsOf($placement) as $assignment) {
                $rows[] = $this->assignmentArray($key, $assignment);
            }
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function adArray(string $key, Marketing $campaign, array $stats): array
    {
        $effective = $this->effective($campaign);
        $fields = $this->fieldsOf($effective, $key);
        $partner = $effective->getPartner();
        $others = array_values(array_filter(
            array_unique(array_filter(array_map(Emplacements::forCampaignPlacement(...), $effective->getPlacements()))),
            static fn (string $other) => $other !== $key,
        ));

        return [
            'type' => 'ad',
            'id' => $campaign->getId(),
            'state' => self::campaignState($campaign, $this->today()),
            'draftState' => self::campaignState($effective, $this->today()),
            'hasPending' => $campaign->getPendingChanges() !== null,
            'company' => $partner === null ? null : $this->companyArray($partner),
            'sponsorName' => $effective->getSponsorName(),
            'published' => $campaign->getPendingChanges() === null || $campaign->isDraft() ? null : $this->fieldsOf($campaign, $key),
            'alsoOn' => array_map(static fn (string $other) => Emplacements::get($other)['label'], $others),
            'stats' => SponsorStats::metrics($stats),
        ] + $fields;
    }

    /** @return array<string, mixed> */
    private function assignmentArray(string $key, Assignment $assignment): array
    {
        $effective = $this->effectiveAssignment($assignment);
        $partner = $effective->getPartner();
        $published = $assignment->getPendingChanges() === null ? null : [
            'companyId' => $assignment->getPartner()?->getId(),
            'companyName' => $assignment->getPartner()?->getName(),
            'startsOn' => $assignment->getStartsOn()?->format('Y-m-d'),
            'endsOn' => $assignment->getEndsOn()?->format('Y-m-d'),
        ];

        return [
            'type' => 'partner',
            'id' => $assignment->getId(),
            'placement' => $assignment->getPlacement(),
            'role' => $key === Emplacements::LOGIN ? 'fallback' : 'main',
            'state' => self::assignmentState($assignment, $this->today()),
            'draftState' => self::assignmentState($effective, $this->today()),
            'hasPending' => $assignment->getPendingChanges() !== null,
            'company' => $partner === null ? null : $this->companyArray($partner),
            'companyId' => $partner?->getId(),
            'hidden' => $partner === null,
            'startsOn' => $effective->getStartsOn()?->format('Y-m-d'),
            'endsOn' => $effective->getEndsOn()?->format('Y-m-d'),
            'published' => $published,
            'publishedAt' => $assignment->getPublishedAt()?->format(\DATE_ATOM),
            'withdrawnAt' => $assignment->getWithdrawnAt()?->format(\DATE_ATOM),
            'stats' => $this->assignmentStats($assignment),
        ];
    }

    /** @return array<string, mixed> */
    public function companyArray(Partner $partner): array
    {
        return [
            'id' => $partner->getId(),
            'name' => $partner->getName(),
            'logo' => $partner->getLogo()?->getFileName(),
            'websiteUrl' => $partner->getWebsiteUrl(),
        ];
    }

    /**
     * Champs d'une publicité tels que l'administration les édite pour cet emplacement.
     *
     * @return array<string, mixed>
     */
    public function fieldsOf(Marketing $campaign, string $key): array
    {
        $interstitial = $key === Emplacements::AFTER_LOGIN;
        $supports = [];
        foreach (Emplacements::get($key)['supports'] as $support => $placement) {
            if ($campaign->hasPlacement($placement)) {
                $supports[] = $support;
            }
        }

        return [
            'companyId' => $campaign->getPartner()?->getId(),
            'name' => $campaign->getCampaignName(),
            'startsOn' => $campaign->getStartDate()?->format('Y-m-d'),
            'endsOn' => $campaign->getEndDate()?->format('Y-m-d'),
            'url' => $campaign->getRedirectUrl(),
            'imageDesktop' => $interstitial
                ? ($campaign->getLargeScreenFormat() ?? $campaign->getScreen14InchFormat() ?? $campaign->getTabletLandscapeFormat())
                : $campaign->getBannerDesktop(),
            'imageMobile' => $interstitial
                ? ($campaign->getSmartphoneFormat() ?? $campaign->getTabletPortraitFormat())
                : $campaign->getBannerMobile(),
            'supports' => $supports,
            'title' => $campaign->getTitle(),
            'description' => $campaign->getDescription(),
            'exclusive' => $campaign->isExclusive(),
            'professionalsOnly' => $campaign->isProfessionalsOnly(),
            'duration' => $campaign->getDuration(),
        ];
    }

    // ------------------------------------------------------------------ Écriture : publicités

    /**
     * Nouvelle publicité, en brouillon (rien n'est affiché avant la publication).
     *
     * @throws \InvalidArgumentException
     */
    public function createAd(string $key, mixed $data): Marketing
    {
        $this->assertAds($key);
        $fields = $this->validateAd($key, $data, null);
        $campaign = (new Marketing())->setStatus(Marketing::STATUS_DRAFT)->setPlacements([]);
        $this->applyAd($campaign, $key, $fields);
        $this->em->persist($campaign);
        $this->em->flush();
        $this->imageStore->purgeOrphans();

        return $campaign;
    }

    /**
     * Modification : appliquée directement à un brouillon, mise en attente pour une publicité
     * publiée (aucun effet public avant la publication).
     *
     * @throws \InvalidArgumentException
     */
    public function updateAd(string $key, Marketing $campaign, mixed $data): void
    {
        $this->assertAds($key);
        $this->assertBelongs($key, $campaign);
        $fields = $this->validateAd($key, $data, $campaign);
        if ($campaign->isDraft()) {
            $previous = $this->imageStore->pathsOf($campaign);
            $this->applyAd($campaign, $key, $fields);
            $this->em->flush();
            $this->imageStore->release($previous);

            return;
        }
        $pending = $campaign->getPendingChanges() ?? [];
        $previous = self::pendingPaths($pending);
        $pending['fields'][$key] = $fields;
        $campaign->setPendingChanges($pending);
        $this->em->flush();
        $this->imageStore->release($previous);
    }

    /** Réactivation d'une publicité désactivée : en attente de publication. */
    public function reactivateAd(string $key, Marketing $campaign): void
    {
        $this->assertBelongs($key, $campaign);
        if ($campaign->getStatus() !== Marketing::STATUS_INACTIVE) {
            throw new \InvalidArgumentException('Seule une publicité désactivée peut être réactivée.');
        }
        $pending = $campaign->getPendingChanges() ?? [];
        $pending['status'] = Marketing::STATUS_ACTIVE;
        $campaign->setPendingChanges($pending);
        $this->em->flush();
    }

    /** Arrêt immédiat de la diffusion (historique et statistiques conservés). */
    public function deactivateAd(string $key, Marketing $campaign): void
    {
        $this->assertBelongs($key, $campaign);
        if ($campaign->isDraft()) {
            throw new \InvalidArgumentException("Un brouillon n'est pas diffusé : supprimez-le.");
        }
        $pending = $campaign->getPendingChanges() ?? [];
        unset($pending['status']);
        $campaign->setStatus(Marketing::STATUS_INACTIVE)->setPendingChanges($pending);
        $this->em->flush();
    }

    /** Suppression : brouillon seulement (une publicité publiée se désactive). */
    public function deleteAd(string $key, Marketing $campaign): void
    {
        $this->assertBelongs($key, $campaign);
        if (!$campaign->isDraft()) {
            throw new \InvalidArgumentException("Cette publicité a déjà été publiée : son historique est conservé. Désactivez-la plutôt.");
        }
        $paths = $this->imageStore->pathsOf($campaign);
        $this->em->remove($campaign);
        $this->em->flush();
        $this->imageStore->release($paths);
    }

    /** Annule les modifications non publiées d'une publicité. */
    public function discardAd(string $key, Marketing $campaign): void
    {
        $this->assertBelongs($key, $campaign);
        $previous = self::pendingPaths($campaign->getPendingChanges() ?? []);
        $campaign->setPendingChanges(null);
        $this->em->flush();
        $this->imageStore->release($previous);
    }

    /**
     * Nouvel ordre d'affichage (identifiants des publicités), en attente de publication.
     *
     * @throws \InvalidArgumentException
     */
    public function reorder(string $key, mixed $ids): void
    {
        $this->assertAds($key);
        $known = array_map(static fn (Marketing $m) => $m->getId(), $this->campaignsOf($key));
        if (!is_array($ids) || $ids === [] || array_filter($ids, static fn ($id) => !is_int($id)) !== [] || array_diff($ids, $known) !== []) {
            throw new \InvalidArgumentException('Ordre invalide : rechargez la page.');
        }
        $setting = $this->settings->forUpdate($key);
        $ids = array_values(array_unique($ids));
        $current = array_map(static fn (Marketing $m) => $m->getId(), $this->orderedCampaigns($key, false));
        $setting->setPendingOrder($ids === array_slice($current, 0, count($ids)) ? null : $ids);
        $this->em->flush();
    }

    // ------------------------------------------------------------------ Écriture : entreprises

    /**
     * Nouvelle attribution d'entreprise (brouillon). Page d'identification : encart partenaire.
     *
     * @throws \InvalidArgumentException
     */
    public function createAssignment(string $key, mixed $data): Assignment
    {
        $placement = $this->partnerPlacementOf($key);
        $fields = $this->validateAssignment($key, $data);
        $assignment = new Assignment();
        $this->placements->apply($assignment, ['placement' => $placement] + $fields);
        $this->em->persist($assignment);
        $this->em->flush();

        return $assignment;
    }

    /** @throws \InvalidArgumentException */
    public function updateAssignment(string $key, Assignment $assignment, mixed $data): void
    {
        $this->assertAssignment($key, $assignment);
        if ($assignment->getStatus() === Assignment::WITHDRAWN) {
            throw new \InvalidArgumentException('Attribution retirée : créez-en une nouvelle.');
        }
        $fields = $this->validateAssignment($key, $data);
        if ($assignment->getStatus() === Assignment::DRAFT) {
            $this->placements->apply($assignment, $fields);
        } else {
            // Contrôle de forme sur une copie : l'attribution publiée reste inchangée.
            $this->placements->apply(clone $assignment, $fields);
            $assignment->setPendingChanges($fields);
        }
        $this->em->flush();
    }

    /** Retrait immédiat (en ligne ou programmée) ; brouillon : suppression. */
    public function deactivateAssignment(string $key, Assignment $assignment): void
    {
        $this->assertAssignment($key, $assignment);
        if ($assignment->getStatus() !== Assignment::PUBLISHED) {
            throw new \InvalidArgumentException('Seule une attribution publiée peut être retirée.');
        }
        $assignment->withdraw()->setPendingChanges(null);
        $this->em->flush();
    }

    public function deleteAssignment(string $key, Assignment $assignment): void
    {
        $this->assertAssignment($key, $assignment);
        if ($assignment->getStatus() !== Assignment::DRAFT) {
            throw new \InvalidArgumentException("Cette attribution a déjà été publiée : son historique est conservé. Retirez-la plutôt.");
        }
        $this->em->remove($assignment);
        $this->em->flush();
    }

    public function discardAssignment(string $key, Assignment $assignment): void
    {
        $this->assertAssignment($key, $assignment);
        $assignment->setPendingChanges(null);
        $this->em->flush();
    }

    // ------------------------------------------------------------------ Réglages

    /** Activation et fréquence : immédiates (aucun brouillon). @throws \InvalidArgumentException */
    public function updateSettings(string $key, mixed $data): void
    {
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Requête invalide.');
        }
        $setting = $this->settings->forUpdate($key);
        if (array_key_exists('enabled', $data)) {
            if (!is_bool($data['enabled'])) {
                throw new \InvalidArgumentException('Valeur oui / non attendue.');
            }
            $setting->setEnabled($data['enabled']);
        }
        if (array_key_exists('frequency', $data)) {
            if ($key !== Emplacements::AFTER_LOGIN || !isset(Emplacements::FREQUENCIES[$data['frequency']])) {
                throw new \InvalidArgumentException("Fréquence d'affichage inconnue.");
            }
            $setting->setFrequency($data['frequency']);
        }
        $this->em->flush();
    }

    // ------------------------------------------------------------------ Publication

    /**
     * Publie toutes les modifications de l'emplacement : brouillons, modifications en attente,
     * ordre. Tout ou rien : un conflit (capacité dépassée, exclusivité, entreprise déjà
     * attribuée) refuse l'ensemble, sauf replace = true pour les attributions d'entreprise
     * (les attributions en place sont alors raccourcies ou décalées, jamais supprimées).
     *
     * @return int nombre de contenus publiés
     * @throws PartnerPlacementConflict|\InvalidArgumentException
     */
    public function publish(string $key, bool $replace = false): int
    {
        $released = [];
        $count = $this->em->wrapInTransaction(function () use ($key, $replace, &$released): int {
            $count = 0;
            if (Emplacements::isAds($key)) {
                $changed = [];
                foreach ($this->campaignsOf($key) as $campaign) {
                    $pending = $campaign->getPendingChanges();
                    if (!$campaign->isDraft() && $pending === null) {
                        continue;
                    }
                    $before = $this->imageStore->pathsOf($campaign);
                    foreach ($pending['fields'] ?? [] as $emplacement => $fields) {
                        $this->applyAd($campaign, (string) $emplacement, $fields);
                    }
                    if ($campaign->isDraft() || ($pending['status'] ?? null) === Marketing::STATUS_ACTIVE) {
                        $campaign->setStatus(Marketing::STATUS_ACTIVE);
                    }
                    $campaign->setPendingChanges(null);
                    $released = [...$released, ...array_diff($before, $this->imageStore->pathsOf($campaign))];
                    $changed[] = $campaign->getId();
                }
                $setting = $this->settings->forUpdate($key);
                $orderChanged = $setting->getPendingOrder() !== null;
                if ($orderChanged) {
                    $setting->setDisplayOrder($setting->getPendingOrder())->setPendingOrder(null);
                }
                $count += count($changed) + ($orderChanged ? 1 : 0);
                // Conflits introduits par ces modifications (un nouvel ordre concerne tout l'emplacement).
                $concerned = $orderChanged ? $setting->getDisplayOrder() : $changed;
                $conflicts = array_values(array_filter(
                    $this->adConflicts($key, $this->campaignsOf($key), $setting->getDisplayOrder()),
                    static fn (array $c) => array_intersect($c['ids'], [...$changed, ...$concerned]) !== [],
                ));
                if ($conflicts !== []) {
                    throw new PartnerPlacementConflict(self::conflictMessage($conflicts), $conflicts);
                }
            }
            $placement = Emplacements::partnerPlacement($key);
            if ($placement !== null) {
                $count += $this->publishAssignments($placement, $replace);
            }
            $this->em->flush();

            return $count;
        });
        $this->imageStore->release(array_values(array_unique($released)));

        return $count;
    }

    private function publishAssignments(string $placement, bool $replace): int
    {
        $todo = array_values(array_filter(
            $this->assignmentsOf($placement),
            static fn (Assignment $a) => $a->getStatus() === Assignment::DRAFT || ($a->getStatus() === Assignment::PUBLISHED && $a->getPendingChanges() !== null),
        ));
        usort($todo, static fn (Assignment $a, Assignment $b) => [$a->getStartsOn()?->format('Y-m-d'), $a->getId()] <=> [$b->getStartsOn()?->format('Y-m-d'), $b->getId()]);
        $conflicts = [];
        foreach ($todo as $assignment) {
            if ($assignment->getPendingChanges() !== null) {
                $this->placements->apply($assignment, $assignment->getPendingChanges());
                $assignment->setPendingChanges(null);
            }
            try {
                $this->placements->publish($assignment, $replace);
            } catch (PartnerPlacementConflict $e) {
                $name = $assignment->getPartner()?->getName() ?? 'Emplacement masqué';
                $conflicts = [...$conflicts, ...array_map(static fn (array $c) => $c + [
                    'ids' => [$c['id']],
                    'names' => [$c['partnerName'] ?? 'Emplacement masqué'],
                    'from' => $c['startsOn'],
                    'to' => $c['endsOn'],
                    'support' => null,
                    'reason' => 'exclusive',
                    'message' => sprintf('« %s » est déjà en ligne ou programmée sur cette période, à la place de « %s ».', $c['partnerName'] ?? 'Emplacement masqué', $name),
                ], $e->conflicts)];
            }
            // Visible des contrôles suivants (deux brouillons qui se chevauchent).
            $this->em->flush();
        }
        if ($conflicts !== []) {
            throw new PartnerPlacementConflict('Emplacement déjà attribué sur cette période. Modifiez les dates, ou confirmez le remplacement : les attributions en place seront raccourcies ou décalées.', $conflicts);
        }

        return count($todo);
    }

    /** Annule toutes les modifications non publiées de l'emplacement (les brouillons restent). */
    public function discardAll(string $key): void
    {
        $released = [];
        if (Emplacements::isAds($key)) {
            foreach ($this->campaignsOf($key) as $campaign) {
                if (!$campaign->isDraft() && $campaign->getPendingChanges() !== null) {
                    $released = [...$released, ...self::pendingPaths($campaign->getPendingChanges())];
                    $campaign->setPendingChanges(null);
                }
            }
            $this->settings->forUpdate($key)->setPendingOrder(null);
        }
        $placement = Emplacements::partnerPlacement($key);
        if ($placement !== null) {
            foreach ($this->assignmentsOf($placement) as $assignment) {
                $assignment->setPendingChanges(null);
            }
        }
        $this->em->flush();
        $this->imageStore->release($released);
    }

    // ------------------------------------------------------------------ Aperçu, calendrier, conflits

    /**
     * Ce que l'emplacement affiche le jour donné : état publié, ou avec les modifications.
     * Même sélection que la diffusion publique (SponsorDelivery, PartnerPlacementManager).
     *
     * @return array<string, mixed>
     */
    public function preview(string $key, string $date, bool $draft): array
    {
        $result = ['enabled' => $this->settings->get($key)->isEnabled()];
        if (Emplacements::isAds($key)) {
            $campaigns = $this->modeCampaigns($key, $draft);
            $order = $this->orderFor($key, $draft);
            $capacity = Emplacements::get($key)['capacity'];
            if ($key === Emplacements::AFTER_LOGIN) {
                $picked = SponsorDelivery::pick($campaigns, Marketing::PLACEMENT_LOGIN_INTERSTITIAL, $date, $order, $capacity)[0] ?? null;
                $result['campaign'] = $picked === null ? null : $this->delivery->toInterstitialArray($picked);
            } else {
                foreach (Emplacements::get($key)['supports'] as $support => $placement) {
                    $result[$support] = array_map($this->delivery->toPublicArray(...), SponsorDelivery::pick($campaigns, $placement, $date, $order, $capacity));
                }
            }
        }
        $placement = Emplacements::partnerPlacement($key);
        if ($placement !== null) {
            $partners = $this->partnersOn($placement, $date, $draft);
            if (Assignment::isShared($placement)) {
                $result['partners'] = $partners;
            } else {
                $result['partner'] = $partners[0] ?? null;
            }
        }

        return $result;
    }

    /**
     * Calendrier de diffusion (« Mes modifications ») sur CALENDAR_MONTHS mois : contenus,
     * périodes libres et conflits.
     *
     * @return array<string, mixed>
     */
    public function calendar(string $key): array
    {
        $today = $this->today();
        $until = (new \DateTimeImmutable($today))->modify('+' . self::CALENDAR_MONTHS . ' months')->format('Y-m-d');
        $free = [];
        if (Emplacements::isAds($key)) {
            $capacity = Emplacements::get($key)['capacity'];
            $campaigns = $this->modeCampaigns($key, true);
            foreach ($this->supportPlacements($key) as $support => $placement) {
                $items = $this->items(array_filter($campaigns, static fn (Marketing $m) => $m->getStatus() === Marketing::STATUS_ACTIVE && $m->hasPlacement($placement) && SponsorDelivery::hasVisualFor($m, $placement)));
                $exclusive = array_map(static fn (Marketing $m) => $m->getId(), array_filter($campaigns, static fn (Marketing $m) => $m->isExclusive()));
                foreach (Timeline::segments($items, $today, $until) as $segment) {
                    $places = array_intersect($segment['ids'], $exclusive) !== [] ? 0 : max(0, $capacity - count($segment['ids']));
                    if ($places > 0 && $segment['from'] <= $until) {
                        $free[] = ['from' => $segment['from'], 'to' => $segment['to'], 'places' => $places, 'support' => count($this->supportPlacements($key)) > 1 ? $support : null];
                    }
                }
            }
        } else {
            $placement = Emplacements::partnerPlacement($key);
            if (!Assignment::isShared($placement)) {
                $items = $this->items(array_filter($this->modeAssignments($placement, true), static fn (Assignment $a) => true));
                foreach (Timeline::segments($items, $today, $until) as $segment) {
                    if ($segment['ids'] === [] && $segment['from'] <= $until) {
                        $free[] = ['from' => $segment['from'], 'to' => $segment['to'], 'places' => 1, 'support' => null];
                    }
                }
            }
        }

        return ['from' => $today, 'to' => $until, 'free' => $free];
    }

    /**
     * Conflits de programmation avec les modifications (à corriger avant de publier).
     *
     * @return list<array<string, mixed>>
     */
    public function conflicts(string $key): array
    {
        $conflicts = [];
        if (Emplacements::isAds($key)) {
            $conflicts = $this->adConflicts($key, $this->modeCampaigns($key, true), $this->orderFor($key, true));
        }
        $placement = Emplacements::partnerPlacement($key);
        if ($placement !== null) {
            $conflicts = [...$conflicts, ...$this->assignmentConflicts($placement)];
        }

        return $conflicts;
    }

    /**
     * Capacité dépassée ou publicité « seule » accompagnée, sur au moins un jour à venir.
     *
     * @param list<Marketing> $campaigns
     * @return list<array<string, mixed>>
     */
    private function adConflicts(string $key, array $campaigns, array $order): array
    {
        $capacity = Emplacements::get($key)['capacity'];
        $byId = [];
        foreach ($campaigns as $campaign) {
            $byId[$campaign->getId()] = $campaign;
        }
        $conflicts = [];
        foreach ($this->supportPlacements($key) as $support => $placement) {
            $active = array_filter($campaigns, static fn (Marketing $m) => $m->getStatus() === Marketing::STATUS_ACTIVE && $m->hasPlacement($placement) && SponsorDelivery::hasVisualFor($m, $placement));
            foreach (Timeline::segments($this->items($active), $this->today()) as $segment) {
                $ids = $segment['ids'];
                $exclusive = array_filter($ids, static fn (int $id) => $byId[$id]->isExclusive());
                $reason = match (true) {
                    count($ids) > 1 && $exclusive !== [] => 'exclusive',
                    count($ids) > $capacity => 'capacity',
                    default => null,
                };
                if ($reason === null) {
                    continue;
                }
                $names = array_map(static fn (int $id) => $byId[$id]->getCampaignName(), $ids);
                $conflicts[] = [
                    'from' => $segment['from'],
                    'to' => $segment['to'],
                    'support' => count($this->supportPlacements($key)) > 1 ? $support : null,
                    'reason' => $reason,
                    'ids' => $ids,
                    'names' => $names,
                    'message' => $reason === 'exclusive'
                        ? sprintf('« %s » doit être seule dans le carrousel, mais %d publicités sont programmées en même temps.', $byId[array_values($exclusive)[0]]->getCampaignName(), count($ids))
                        : sprintf('%d publicités programmées en même temps pour %d place%s.', count($ids), $capacity, $capacity > 1 ? 's' : ''),
                ];
            }
        }

        return $conflicts;
    }

    /** @return list<array<string, mixed>> */
    private function assignmentConflicts(string $placement): array
    {
        $assignments = array_values(array_filter($this->modeAssignments($placement, true), static fn (Assignment $a) => $a->getStartsOn() !== null));
        $byId = [];
        foreach ($assignments as $assignment) {
            $byId[$assignment->getId()] = $assignment;
        }
        $conflicts = [];
        $shared = Assignment::isShared($placement);
        foreach (Timeline::segments($this->items($assignments), $this->today()) as $segment) {
            $ids = $segment['ids'];
            if ($shared) {
                $byPartner = [];
                foreach ($ids as $id) {
                    $byPartner[$byId[$id]->getPartner()?->getId() ?? 0][] = $id;
                }
                $ids = array_merge(...array_values(array_filter($byPartner, static fn (array $group) => count($group) > 1)) ?: [[]]);
            }
            if (count($ids) < 2) {
                continue;
            }
            $names = array_map(static fn (int $id) => $byId[$id]->getPartner()?->getName() ?? 'Emplacement masqué', $ids);
            $conflicts[] = [
                'from' => $segment['from'],
                'to' => $segment['to'],
                'support' => null,
                'reason' => 'exclusive',
                'ids' => $ids,
                'names' => $names,
                'message' => $shared
                    ? sprintf('« %s » est présentée deux fois sur la même période.', $names[0])
                    : sprintf('Une seule entreprise à la fois : %s sont programmées en même temps.', implode(' et ', array_unique($names))),
            ];
        }

        return $conflicts;
    }

    /** @param list<array<string, mixed>> $conflicts */
    private static function conflictMessage(array $conflicts): string
    {
        $first = $conflicts[0];

        return sprintf(
            'Publication impossible : %s (%s). Modifiez les dates, désactivez une publicité ou changez l\'ordre.',
            rtrim($first['message'], '.'),
            $first['to'] === null ? 'à partir du ' . self::frenchDate($first['from']) : 'du ' . self::frenchDate($first['from']) . ' au ' . self::frenchDate($first['to']),
        );
    }

    // ------------------------------------------------------------------ Outils

    /**
     * Publicités de l'emplacement (tous statuts).
     *
     * @return list<Marketing>
     */
    public function campaignsOf(string $key): array
    {
        $placements = Emplacements::campaignPlacements($key);
        $result = [];
        foreach ($this->campaigns->findBy([], ['id' => 'ASC']) as $campaign) {
            $targets = $campaign->getPlacements();
            foreach ($campaign->getPendingChanges()['fields'][$key]['supports'] ?? [] as $support) {
                $targets[] = Emplacements::get($key)['supports'][$support] ?? '';
            }
            if (array_intersect($placements, $targets) !== []) {
                $result[] = $campaign;
            }
        }

        return $result;
    }

    /**
     * Ordre d'affichage : en cours et à venir dans l'ordre de l'emplacement, puis terminées et
     * désactivées (les plus récentes d'abord).
     *
     * @return list<Marketing>
     */
    private function orderedCampaigns(string $key, bool $draft): array
    {
        $today = $this->today();
        $campaigns = $this->campaignsOf($key);
        $effective = $draft ? array_map($this->effective(...), $campaigns) : $campaigns;
        $originals = [];
        foreach ($campaigns as $i => $campaign) {
            $originals[spl_object_id($effective[$i])] = $campaign;
        }
        $current = array_values(array_filter($effective, static fn (Marketing $m) => !in_array(self::campaignState($m, $today), ['ended', 'inactive'], true)));
        $past = array_values(array_filter($effective, static fn (Marketing $m) => in_array(self::campaignState($m, $today), ['ended', 'inactive'], true)));
        usort($past, static fn (Marketing $a, Marketing $b) => [$b->getEndDate()?->format('Y-m-d') ?? '9999', $b->getId()] <=> [$a->getEndDate()?->format('Y-m-d') ?? '9999', $a->getId()]);

        return array_map(static fn (Marketing $m) => $originals[spl_object_id($m)], [...SponsorDelivery::ordered($current, $this->orderFor($key, $draft)), ...$past]);
    }

    /** @return list<int> */
    private function orderFor(string $key, bool $draft): array
    {
        $setting = $this->settings->get($key);

        return $draft ? ($setting->getPendingOrder() ?? $setting->getDisplayOrder()) : $setting->getDisplayOrder();
    }

    /**
     * Publicités vues dans un mode : publiées (statut actif, état publié), ou avec les
     * modifications (copies non enregistrées, brouillons compris comme s'ils étaient publiés).
     *
     * @return list<Marketing>
     */
    private function modeCampaigns(string $key, bool $draft): array
    {
        $campaigns = $this->campaignsOf($key);
        if (!$draft) {
            return array_values(array_filter($campaigns, static fn (Marketing $m) => $m->getStatus() === Marketing::STATUS_ACTIVE));
        }

        return array_values(array_filter(array_map(function (Marketing $m): Marketing {
            $copy = $this->effective($m);
            if ($copy->isDraft()) {
                $copy = $copy === $m ? clone $m : $copy;
                $copy->setStatus(Marketing::STATUS_ACTIVE);
            }

            return $copy;
        }, $campaigns), static fn (Marketing $m) => $m->getStatus() === Marketing::STATUS_ACTIVE));
    }

    /**
     * Publicité avec ses modifications en attente (copie jamais enregistrée), ou elle-même.
     */
    public function effective(Marketing $campaign): Marketing
    {
        $pending = $campaign->getPendingChanges();
        if ($pending === null) {
            return $campaign;
        }
        $copy = clone $campaign;
        foreach ($pending['fields'] ?? [] as $emplacement => $fields) {
            if (Emplacements::exists((string) $emplacement)) {
                $this->applyAd($copy, (string) $emplacement, $fields);
            }
        }
        if (($pending['status'] ?? null) === Marketing::STATUS_ACTIVE) {
            $copy->setStatus(Marketing::STATUS_ACTIVE);
        }

        return $copy;
    }

    private function effectiveAssignment(Assignment $assignment): Assignment
    {
        $pending = $assignment->getPendingChanges();
        if ($pending === null) {
            return $assignment;
        }
        $copy = clone $assignment;
        $copy->setPartner(isset($pending['partnerId']) ? $this->partners->find($pending['partnerId']) : null)
            ->setStartsOn(isset($pending['startsOn']) ? new \DateTimeImmutable($pending['startsOn']) : null)
            ->setEndsOn(isset($pending['endsOn']) ? new \DateTimeImmutable($pending['endsOn']) : null);

        return $copy;
    }

    /** @return list<Assignment> attributions de l'emplacement (tous statuts), plus récentes d'abord */
    private function assignmentsOf(string $placement): array
    {
        return array_values(array_filter($this->assignments->findHistory(), static fn (Assignment $a) => $a->getPlacement() === $placement));
    }

    /** @return list<Assignment> publiées (ou, en mode modifications, publiées modifiées et brouillons) */
    private function modeAssignments(string $placement, bool $draft): array
    {
        $result = [];
        foreach ($this->assignmentsOf($placement) as $assignment) {
            if ($assignment->getStatus() === Assignment::PUBLISHED) {
                $result[] = $draft ? $this->effectiveAssignment($assignment) : $assignment;
            } elseif ($draft && $assignment->getStatus() === Assignment::DRAFT && $assignment->getStartsOn() !== null) {
                $result[] = $assignment;
            }
        }

        return $result;
    }

    /** @return list<array<string, mixed>> entreprises affichées ce jour-là (une, ou plusieurs si partagé) */
    private function partnersOn(string $placement, string $date, bool $draft): array
    {
        $live = array_values(array_filter(
            $this->modeAssignments($placement, $draft),
            static fn (Assignment $a) => $a->getStartsOn()->format('Y-m-d') <= $date && ($a->getEndsOn() === null || $a->getEndsOn()->format('Y-m-d') >= $date),
        ));
        usort($live, static fn (Assignment $a, Assignment $b) => [$b->getStartsOn()->format('Y-m-d'), $b->getId() ?? PHP_INT_MAX] <=> [$a->getStartsOn()->format('Y-m-d'), $a->getId() ?? PHP_INT_MAX]);
        if (!Assignment::isShared($placement)) {
            $first = $live[0] ?? null;

            return $first?->getPartner() === null ? [] : [$this->placements->publicPartner($first->getPartner())];
        }
        $partners = [];
        foreach ($live as $assignment) {
            if ($assignment->getPartner() !== null) {
                $partners[$assignment->getPartner()->getId()] = $this->placements->publicPartner($assignment->getPartner());
            }
        }
        $partners = array_values($partners);
        usort($partners, static fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return $partners;
    }

    /**
     * @param iterable<Marketing|Assignment> $contents
     * @return list<array{id: int, start: string, end: ?string}>
     */
    private function items(iterable $contents): array
    {
        $items = [];
        foreach ($contents as $content) {
            $start = $content instanceof Marketing ? $content->getStartDate() : $content->getStartsOn();
            $end = $content instanceof Marketing ? $content->getEndDate() : $content->getEndsOn();
            if ($start !== null && $content->getId() !== null) {
                $items[] = ['id' => $content->getId(), 'start' => $start->format('Y-m-d'), 'end' => $end?->format('Y-m-d')];
            }
        }

        return $items;
    }

    /** @return array<string, string> support => placement technique, sans doublon */
    private function supportPlacements(string $key): array
    {
        return array_unique(Emplacements::get($key)['supports']);
    }

    /** @return list<string> */
    private function supportsOf(string $key): array
    {
        return array_keys(Emplacements::get($key)['supports']);
    }

    /** @return array{0: list<array<string, mixed>>, 1: ?array<string, mixed>} */
    private function liveAndNext(string $key, string $today): array
    {
        $live = [];
        $upcoming = [];
        if (Emplacements::isAds($key)) {
            $published = $this->modeCampaigns($key, false);
            $seen = [];
            foreach ($this->supportPlacements($key) as $placement) {
                foreach (SponsorDelivery::pick($published, $placement, $today, $this->orderFor($key, false), Emplacements::get($key)['capacity']) as $campaign) {
                    if (!isset($seen[$campaign->getId()])) {
                        $seen[$campaign->getId()] = true;
                        $live[] = ['type' => 'ad', 'name' => $campaign->getCampaignName(), 'company' => $campaign->getPartner()?->getName() ?? $campaign->getSponsorName(), 'startsOn' => $campaign->getStartDate()?->format('Y-m-d'), 'endsOn' => $campaign->getEndDate()?->format('Y-m-d')];
                    }
                }
            }
            foreach ($published as $campaign) {
                if ($campaign->getStartDate()?->format('Y-m-d') > $today) {
                    $upcoming[] = ['type' => 'ad', 'name' => $campaign->getCampaignName(), 'company' => $campaign->getPartner()?->getName() ?? $campaign->getSponsorName(), 'startsOn' => $campaign->getStartDate()->format('Y-m-d'), 'endsOn' => $campaign->getEndDate()?->format('Y-m-d')];
                }
            }
        }
        $placement = Emplacements::partnerPlacement($key);
        if ($placement !== null) {
            $row = static fn (Assignment $a) => [
                'type' => 'partner',
                'role' => $key === Emplacements::LOGIN ? 'fallback' : 'main',
                'name' => $a->getPartner()?->getName() ?? 'Emplacement masqué',
                'company' => $a->getPartner()?->getName(),
                'logo' => $a->getPartner()?->getLogo()?->getFileName(),
                'startsOn' => $a->getStartsOn()?->format('Y-m-d'),
                'endsOn' => $a->getEndsOn()?->format('Y-m-d'),
            ];
            $current = [];
            foreach ($this->modeAssignments($placement, false) as $assignment) {
                $state = self::assignmentState($assignment, $today);
                if ($state === 'live') {
                    $current[] = $assignment;
                } elseif ($state === 'scheduled') {
                    $upcoming[] = $row($assignment);
                }
            }
            // Emplacement exclusif : l'attribution la plus récente l'emporte (données anciennes).
            usort($current, static fn (Assignment $a, Assignment $b) => [$b->getStartsOn()->format('Y-m-d'), $b->getId()] <=> [$a->getStartsOn()->format('Y-m-d'), $a->getId()]);
            if (!Assignment::isShared($placement)) {
                $current = array_slice($current, 0, 1);
            }
            foreach ($current as $assignment) {
                $live[] = $row($assignment);
            }
        }
        usort($upcoming, static fn (array $a, array $b) => $a['startsOn'] <=> $b['startsOn']);

        return [$live, $upcoming[0] ?? null];
    }

    private function pendingCount(string $key): int
    {
        $count = 0;
        if (Emplacements::isAds($key)) {
            foreach ($this->campaignsOf($key) as $campaign) {
                if ($campaign->isDraft() || $campaign->getPendingChanges() !== null) {
                    $count++;
                }
            }
            if ($this->settings->get($key)->getPendingOrder() !== null) {
                $count++;
            }
        }
        $placement = Emplacements::partnerPlacement($key);
        if ($placement !== null) {
            foreach ($this->assignmentsOf($placement) as $assignment) {
                if ($assignment->getStatus() === Assignment::DRAFT || ($assignment->getStatus() === Assignment::PUBLISHED && $assignment->getPendingChanges() !== null)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /** brouillon, programmée, en ligne, terminée ou désactivée. */
    public static function campaignState(Marketing $campaign, string $today): string
    {
        $start = $campaign->getStartDate()?->format('Y-m-d');
        $end = $campaign->getEndDate()?->format('Y-m-d');

        return match (true) {
            $campaign->isDraft() => 'draft',
            $campaign->getStatus() !== Marketing::STATUS_ACTIVE => 'inactive',
            $end !== null && $end < $today => 'ended',
            $start !== null && $start > $today => 'scheduled',
            default => 'live',
        };
    }

    public static function assignmentState(Assignment $assignment, string $today): string
    {
        $state = $assignment->stateOn($today);

        return $state === 'withdrawn' ? 'inactive' : $state;
    }

    /**
     * Impressions et clics de toute la diffusion, par publicité, sur les placements donnés.
     *
     * @param list<int> $ids
     * @param list<string> $placements
     * @return array<int, array{impressions?: int, clicks?: int}>
     */
    private function adStats(array $ids, array $placements): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT marketing_id, kind, COUNT(*) AS n FROM marketing_view WHERE marketing_id IN (?) AND placement IN (?) AND kind IN (?) GROUP BY marketing_id, kind',
            [$ids, $placements, ['impression', 'click']],
            [ArrayParameterType::INTEGER, ArrayParameterType::STRING, ArrayParameterType::STRING],
        );
        $stats = [];
        foreach ($rows as $row) {
            $stats[(int) $row['marketing_id']][$row['kind'] === 'click' ? 'clicks' : 'impressions'] = (int) $row['n'];
        }

        return $stats;
    }

    /** Mesures de l'entreprise sur cet emplacement pendant la période de l'attribution. */
    private function assignmentStats(Assignment $assignment): array
    {
        if ($assignment->getPartner() === null || $assignment->getStartsOn() === null || $assignment->getStatus() === Assignment::DRAFT) {
            return SponsorStats::metrics([]);
        }
        $end = $assignment->getWithdrawnAt()?->format('Y-m-d') ?? $assignment->getEndsOn()?->format('Y-m-d');
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT kind, COUNT(*) AS n FROM partner_event WHERE partner_id = ? AND placement = ? AND viewed_at >= ? AND viewed_at < ? GROUP BY kind',
            [$assignment->getPartner()->getId(), $assignment->getPlacement(), $assignment->getStartsOn()->format('Y-m-d 00:00:00'), $end === null ? '9999-12-31 00:00:00' : Timeline::next($end) . ' 00:00:00'],
        );
        $totals = [];
        foreach ($rows as $row) {
            $totals[$row['kind'] === 'click' ? 'clicks' : 'impressions'] = (int) $row['n'];
        }

        return SponsorStats::metrics($totals);
    }

    /**
     * Contrôle et normalise les champs d'une publicité.
     *
     * @return array<string, mixed>
     * @throws \InvalidArgumentException
     */
    private function validateAd(string $key, mixed $data, ?Marketing $current): array
    {
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Requête invalide.');
        }
        $text = static function (string $field, int $max, string $label) use ($data): ?string {
            $value = $data[$field] ?? null;
            if ($value === null || $value === '') {
                return null;
            }
            if (!is_string($value) || mb_strlen(trim($value)) > $max) {
                throw new \InvalidArgumentException(sprintf('%s : %d caractères au maximum.', $label, $max));
            }

            return trim($value) === '' ? null : trim($value);
        };

        $companyId = $data['companyId'] ?? null;
        $company = is_int($companyId) ? $this->partners->find($companyId) : null;
        if ($company === null) {
            throw new \InvalidArgumentException("Choisissez l'entreprise qui diffuse la publicité.");
        }
        $start = self::date($data['startsOn'] ?? null);
        if ($start === null) {
            throw new \InvalidArgumentException('Indiquez la date de début (AAAA-MM-JJ).');
        }
        $end = null;
        if (($data['endsOn'] ?? null) !== null && $data['endsOn'] !== '') {
            $end = self::date($data['endsOn']);
            if ($end === null) {
                throw new \InvalidArgumentException('Date de fin invalide (AAAA-MM-JJ).');
            }
            if ($end < $start) {
                throw new \InvalidArgumentException('La date de fin doit suivre la date de début.');
            }
        }
        $unchangedStart = $current !== null && $this->fieldsOf($this->effective($current), $key)['startsOn'] === $start;
        if ($start < $this->today() && !$unchangedStart) {
            throw new \InvalidArgumentException("La date de début est passée : choisissez aujourd'hui ou une date à venir.");
        }
        $url = $text('url', 500, 'Lien');
        if ($url !== null && !self::isHttpUrl($url)) {
            throw new \InvalidArgumentException('Lien : adresse commençant par https:// attendue.');
        }

        $supports = self::SUPPORTS;
        if ($key === Emplacements::DASHBOARD_CAROUSEL) {
            $supports = $data['supports'] ?? self::SUPPORTS;
            if (!is_array($supports) || $supports === [] || array_diff($supports, self::SUPPORTS) !== []) {
                throw new \InvalidArgumentException('Choisissez au moins un support : ordinateur ou mobile.');
            }
            $supports = array_values(array_intersect(self::SUPPORTS, $supports));
        }
        $stored = $current === null ? [] : array_filter([...array_values(MarketingImageStore::imageValues($current)), ...self::pendingPaths($current->getPendingChanges() ?? [])]);
        $labels = ['desktop' => 'Visuel ordinateur', 'mobile' => 'Visuel mobile'];
        $images = [];
        foreach (self::SUPPORTS as $support) {
            $field = 'image' . ucfirst($support);
            $value = $data[$field] ?? null;
            $value = is_string($value) && trim($value) !== '' ? trim($value) : null;
            if ($value === null) {
                if (in_array($support, $supports, true)) {
                    throw new \InvalidArgumentException(sprintf('%s : importez une image (ou réutilisez celle de l\'autre support).', $labels[$support]));
                }
                $images[$field] = null;
                continue;
            }
            if (!in_array($value, $stored, true)) {
                if (str_starts_with($value, MarketingImage::PATH_PREFIX)) {
                    if ($this->images->findByPath($value) === null) {
                        throw new \InvalidArgumentException(sprintf('%s : visuel introuvable, importez-le de nouveau.', $labels[$support]));
                    }
                } elseif (!self::isHttpUrl($value)) {
                    throw new \InvalidArgumentException(sprintf('%s : importez une image PNG, JPEG ou WebP.', $labels[$support]));
                }
            }
            $images[$field] = $value;
        }

        $professionalsOnly = ($data['professionalsOnly'] ?? false) === true;
        if ($professionalsOnly && Emplacements::get($key)['public']) {
            throw new \InvalidArgumentException($key === Emplacements::INAMI_SEARCH
                ? "La recherche INAMI est une page publique (la déclaration « professionnel de la santé » n'est pas une vérification) : une publicité réservée aux professionnels ne peut pas y être diffusée."
                : "La page d'identification est publique : une publicité réservée aux professionnels ne peut pas y être diffusée.");
        }
        // Formats propres (4:1, 16:5) : une publicité de la recherche INAMI n'est diffusée nulle part ailleurs.
        $others = array_diff($current?->getPlacements() ?? [], Emplacements::campaignPlacements($key));
        if ($current !== null && ($key === Emplacements::INAMI_SEARCH ? $others !== [] : in_array(Marketing::PLACEMENT_INAMI_SEARCH, $others, true))) {
            throw new \InvalidArgumentException('Recherche INAMI : formats propres (1200 × 300 et 640 × 200 px). Créez une publicité distincte pour les autres emplacements.');
        }
        $duration = $data['duration'] ?? Marketing::DEFAULT_DURATION;
        if ($key === Emplacements::AFTER_LOGIN && filter_var($duration, FILTER_VALIDATE_INT, ['options' => ['min_range' => self::MIN_DURATION, 'max_range' => Marketing::MAX_DURATION]]) === false) {
            throw new \InvalidArgumentException(sprintf("Fermeture automatique : de %d à %d secondes.", self::MIN_DURATION, Marketing::MAX_DURATION));
        }
        $name = $text('name', 400, 'Nom de la publicité') ?? sprintf('%s — %s', $company->getName(), Emplacements::get($key)['label']);

        return [
            'companyId' => $company->getId(),
            'name' => $name,
            'startsOn' => $start,
            'endsOn' => $end,
            'url' => $url,
            'supports' => $supports,
            'title' => $text('title', 120, 'Titre'),
            'description' => $text('description', 280, 'Texte'),
            'exclusive' => Emplacements::get($key)['capacity'] > 1 && ($data['exclusive'] ?? false) === true,
            'professionalsOnly' => $professionalsOnly,
            'duration' => $key === Emplacements::AFTER_LOGIN ? (int) $duration : ($current?->getDuration() ?? Marketing::DEFAULT_DURATION),
        ] + $images;
    }

    /** Applique des champs validés à une publicité (ou à une copie d'aperçu). */
    private function applyAd(Marketing $campaign, string $key, array $fields): void
    {
        $company = isset($fields['companyId']) ? $this->partners->find($fields['companyId']) : null;
        $campaign->setPartner($company)
            ->setSponsorName($company?->getName() ?? $campaign->getSponsorName())
            ->setCampaignName((string) $fields['name'])
            ->setStartDate(new \DateTime($fields['startsOn']))
            ->setEndDate($fields['endsOn'] === null ? null : new \DateTime($fields['endsOn']))
            ->setRedirectUrl($fields['url'])
            ->setShowCta($fields['url'] !== null)
            ->setTitle($fields['title'])
            ->setDescription($fields['description'])
            ->setExclusive((bool) $fields['exclusive'])
            ->setProfessionalsOnly((bool) $fields['professionalsOnly'])
            ->setDuration((int) $fields['duration']);
        if ($key === Emplacements::AFTER_LOGIN) {
            // Deux visuels : ordinateur (16:9) et mobile (9:16), répartis sur les paliers d'écran.
            $campaign->setLargeScreenFormat($fields['imageDesktop'])
                ->setScreen14InchFormat($fields['imageDesktop'])
                ->setTabletLandscapeFormat($fields['imageDesktop'])
                ->setSmartphoneFormat($fields['imageMobile'])
                ->setTabletPortraitFormat($fields['imageMobile']);
        } else {
            $campaign->setBannerDesktop($fields['imageDesktop'])->setBannerMobile($fields['imageMobile']);
        }
        $placements = array_values(array_diff($campaign->getPlacements(), Emplacements::campaignPlacements($key)));
        foreach ($fields['supports'] as $support) {
            $placements[] = Emplacements::get($key)['supports'][$support];
        }
        $campaign->setPlacements(array_values(array_unique($placements)));
    }

    /**
     * @return array{partnerId: int, startsOn: string, endsOn: ?string}
     * @throws \InvalidArgumentException
     */
    private function validateAssignment(string $key, mixed $data): array
    {
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Requête invalide.');
        }
        $companyId = $data['companyId'] ?? null;
        if (!is_int($companyId) || $this->partners->find($companyId) === null) {
            throw new \InvalidArgumentException('Choisissez une entreprise.');
        }

        return [
            'partnerId' => $companyId,
            'startsOn' => is_string($data['startsOn'] ?? null) ? $data['startsOn'] : null,
            'endsOn' => is_string($data['endsOn'] ?? null) && $data['endsOn'] !== '' ? $data['endsOn'] : null,
        ];
    }

    private function partnerPlacementOf(string $key): string
    {
        $placement = Emplacements::partnerPlacement($key);
        if ($placement === null) {
            throw new \InvalidArgumentException("Cet emplacement n'accueille que des publicités.");
        }

        return $placement;
    }

    private function assertAds(string $key): void
    {
        if (!Emplacements::isAds($key)) {
            throw new \InvalidArgumentException("Cet emplacement présente une entreprise : attribuez-la plutôt qu'une publicité.");
        }
    }

    private function assertBelongs(string $key, Marketing $campaign): void
    {
        if (!in_array($campaign, $this->campaignsOf($key), true)) {
            throw new \InvalidArgumentException("Cette publicité n'est pas diffusée sur cet emplacement.");
        }
    }

    private function assertAssignment(string $key, Assignment $assignment): void
    {
        if ($assignment->getPlacement() !== $this->partnerPlacementOf($key)) {
            throw new \InvalidArgumentException("Cette attribution ne concerne pas cet emplacement.");
        }
    }

    /** @return list<string> visuels importés cités dans des modifications en attente */
    private static function pendingPaths(array $pending): array
    {
        $paths = [];
        array_walk_recursive($pending, static function ($value) use (&$paths): void {
            if (is_string($value) && preg_match(MarketingImage::PATH_PATTERN, $value)) {
                $paths[] = $value;
            }
        });

        return $paths;
    }

    /** 2026-11-12 → 12/11/2026 (messages de l'administration). */
    public static function frenchDate(string $day): string
    {
        return (new \DateTimeImmutable($day))->format('d/m/Y');
    }

    private static function date(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    private static function isHttpUrl(string $value): bool
    {
        return strlen($value) <= 500
            && filter_var($value, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
