<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\MarketingImage;
use App\Entity\Partner;
use App\Entity\PartnerPlacementAssignment as Assignment;
use App\Repository\PartnerPlacementAssignmentRepository;
use App\Repository\PartnerRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Diffusion des firmes partenaires sur les six emplacements (docs/PARTENAIRES.md).
 *
 * Aucune firme n'a de privilège : un emplacement affiche la firme de son affectation publiée
 * en cours, ou rien. Remplacer une firme (BOSI ou une autre) se fait par une nouvelle
 * affectation publiée avec « replace » : les affectations qui se chevauchent sont raccourcies,
 * décalées ou retirées, et une affectation qui dépassait la nouvelle période reprend après
 * elle (retour automatique de la firme précédente).
 */
final class PartnerPlacementManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PartnerPlacementAssignmentRepository $assignments,
        private readonly PartnerRepository $partners,
        private readonly PartnerManager $partnerManager,
    ) {
    }

    public function today(): \DateTimeImmutable
    {
        return $this->partnerManager->today();
    }

    /**
     * Firmes affichées ce jour-là : une firme (ou null) par emplacement exclusif, une liste pour
     * la page « Nos partenaires ».
     *
     * @return array<string, mixed>
     */
    public function placementsOn(?\DateTimeImmutable $day = null): array
    {
        $result = [];
        foreach (Assignment::PLACEMENTS as $placement) {
            $result[$placement] = Assignment::isShared($placement) ? [] : null;
        }
        $seen = [];
        // Plus récente d'abord : en cas d'incohérence (données anciennes), elle l'emporte.
        foreach ($this->assignments->findLive($day ?? $this->today()) as $assignment) {
            $placement = $assignment->getPlacement();
            $partner = $assignment->getPartner();
            if (!array_key_exists($placement, $result)) {
                continue;
            }
            if (Assignment::isShared($placement)) {
                if ($partner !== null && !isset($seen[$placement][$partner->getId()])) {
                    $seen[$placement][$partner->getId()] = true;
                    $result[$placement][] = $this->publicPartner($partner);
                }
                continue;
            }
            if (!isset($seen[$placement])) {
                $seen[$placement] = true;
                $result[$placement] = $partner === null ? null : $this->publicPartner($partner);
            }
        }
        // Page « Nos partenaires » : ordre alphabétique, stable d'un jour à l'autre.
        usort($result[Assignment::PARTNERS_PAGE], static fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return $result;
    }

    /**
     * Ancienne API (/api/partner/active, clients d'avant les emplacements) : la firme du
     * tableau de bord, sinon celle du premier emplacement occupé.
     */
    public function legacyActive(): ?array
    {
        $placements = $this->placementsOn();
        foreach ([Assignment::DASHBOARD_PARTNER, Assignment::SIDEBAR, Assignment::LOGIN_FALLBACK, Assignment::PUBLIC_HOME, Assignment::MOBILE_MENU] as $key) {
            if ($placements[$key] !== null) {
                return $placements[$key];
            }
        }

        return $placements[Assignment::PARTNERS_PAGE][0] ?? null;
    }

    /** Fiche publique d'une firme, seulement si elle est diffusée aujourd'hui (sinon null). */
    public function livePartner(int $id): ?array
    {
        $partner = $this->partners->find($id);

        return $partner !== null && $this->assignments->isPartnerLive($partner, $this->today()) ? $this->publicPartner($partner) : null;
    }

    public function isLiveOn(Partner $partner, string $placement): bool
    {
        return in_array($placement, Assignment::PLACEMENTS, true) && $this->assignments->isPartnerLive($partner, $this->today(), $placement);
    }

    /** Données publiques d'une firme (aucun statut ni réglage interne). */
    public function publicPartner(Partner $partner): array
    {
        return [
            'id' => $partner->getId(),
            'name' => $partner->getName(),
            'websiteUrl' => $partner->getWebsiteUrl(),
            'tagline' => $partner->getTagline(),
            'about' => $partner->getAbout(),
            'commitments' => $partner->getCommitments(),
            'logo' => $partner->getLogo()?->getFileName(),
            'coverImage' => $partner->getCoverImage(),
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(Assignment $a): array
    {
        $partner = $a->getPartner();

        return [
            'id' => $a->getId(),
            'placement' => $a->getPlacement(),
            'partnerId' => $partner?->getId(),
            'partnerName' => $partner?->getName(),
            'partnerLogo' => $partner?->getLogo()?->getFileName(),
            'startsOn' => $a->getStartsOn()?->format('Y-m-d'),
            'endsOn' => $a->getEndsOn()?->format('Y-m-d'),
            'status' => $a->getStatus(),
            'state' => $a->stateOn($this->today()->format('Y-m-d')),
            'publishedAt' => $a->getPublishedAt()?->format(\DATE_ATOM),
            'withdrawnAt' => $a->getWithdrawnAt()?->format(\DATE_ATOM),
            'updatedAt' => $a->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function history(): array
    {
        return array_map($this->toArray(...), $this->assignments->findHistory());
    }

    /**
     * Attribue une firme (ou masque, partnerId null) sur un ou plusieurs emplacements, en une
     * seule transaction : tout est enregistré, ou rien.
     * Données : placements (liste), partnerId, startsOn, endsOn, publish (bool), replace (bool).
     *
     * @return list<Assignment>
     *
     * @throws \InvalidArgumentException|PartnerPlacementConflict
     */
    public function assign(mixed $data): array
    {
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Requête invalide.');
        }
        $placements = $data['placements'] ?? (isset($data['placement']) ? [$data['placement']] : null);
        if (!is_array($placements) || $placements === [] || array_diff($placements, Assignment::PLACEMENTS) !== []) {
            throw new \InvalidArgumentException('Choisissez au moins un emplacement partenaire.');
        }
        $placements = array_values(array_unique($placements));
        $publish = ($data['publish'] ?? false) === true;
        $replace = ($data['replace'] ?? false) === true;

        return $this->em->wrapInTransaction(function () use ($placements, $data, $publish, $replace): array {
            $created = [];
            $conflicts = [];
            foreach ($placements as $placement) {
                $assignment = new Assignment();
                $this->apply($assignment, ['placement' => $placement] + $data);
                $this->em->persist($assignment);
                if ($publish) {
                    try {
                        $this->publish($assignment, $replace);
                    } catch (PartnerPlacementConflict $e) {
                        $conflicts = [...$conflicts, ...$e->conflicts];
                    }
                }
                $created[] = $assignment;
            }
            if ($conflicts !== []) {
                throw new PartnerPlacementConflict(self::conflictMessage($conflicts), $conflicts);
            }
            $this->em->flush();

            return $created;
        });
    }

    /**
     * Applique les champs d'une affectation (contrôle de forme ; la publication contrôle les
     * chevauchements).
     *
     * @throws \InvalidArgumentException
     */
    public function apply(Assignment $a, mixed $data): void
    {
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Requête invalide.');
        }
        $placement = $data['placement'] ?? $a->getPlacement();
        if (!is_string($placement) || !in_array($placement, Assignment::PLACEMENTS, true)) {
            throw new \InvalidArgumentException('Emplacement partenaire inconnu.');
        }
        if (!array_key_exists('partnerId', $data)) {
            throw new \InvalidArgumentException('Choisissez une firme (ou « masquer l\'emplacement »).');
        }
        $id = $data['partnerId'];
        if ($id !== null && (!is_int($id) || $id < 1)) {
            throw new \InvalidArgumentException('Identifiant de firme invalide.');
        }
        $partner = $id === null ? null : $this->partners->find($id);
        if ($id !== null && $partner === null) {
            throw new \InvalidArgumentException('Firme introuvable.');
        }
        if ($partner === null && Assignment::isShared($placement)) {
            throw new \InvalidArgumentException('Page « Nos partenaires » : choisissez une firme (pour la retirer, retirez son affectation).');
        }
        if ($partner !== null && $partner->getLogo() === null) {
            throw new \InvalidArgumentException(sprintf('Ajoutez un logo à « %s » avant de la diffuser.', $partner->getName()));
        }

        $start = self::date($data['startsOn'] ?? null, 'Date de début');
        $end = self::date($data['endsOn'] ?? null, 'Date de fin');
        if ($start === null) {
            throw new \InvalidArgumentException('Indiquez la date de début.');
        }
        if ($end !== null && $end->format('Y-m-d') < $start->format('Y-m-d')) {
            throw new \InvalidArgumentException('La date de fin doit suivre la date de début.');
        }
        // Une date passée réécrirait l'historique : seule une affectation existante peut la garder.
        $today = $this->today()->format('Y-m-d');
        $unchanged = $a->getId() !== null && $a->getStartsOn()?->format('Y-m-d') === $start->format('Y-m-d');
        if ($start->format('Y-m-d') < $today && !$unchanged) {
            throw new \InvalidArgumentException('La date de début est passée : choisissez aujourd\'hui ou une date à venir.');
        }

        $a->setPlacement($placement)->setPartner($partner)->setStartsOn($start)->setEndsOn($end)->touch();
    }

    /**
     * Publie (ou republie après modification). Emplacement exclusif déjà attribué sur la
     * période : refus (PartnerPlacementConflict), sauf replace = true.
     *
     * @throws \InvalidArgumentException|PartnerPlacementConflict
     */
    public function publish(Assignment $a, bool $replace = false): void
    {
        $start = $a->getStartsOn();
        if ($start === null) {
            throw new \InvalidArgumentException('Indiquez la date de début.');
        }
        if ($a->getEndsOn() !== null && $a->getEndsOn()->format('Y-m-d') < $this->today()->format('Y-m-d')) {
            throw new \InvalidArgumentException('La date de fin est déjà passée.');
        }
        if (Assignment::isShared($a->getPlacement())) {
            // Page partagée : plusieurs firmes, mais une seule affectation par firme et période.
            foreach ($this->assignments->findOverlapping($a->getPlacement(), $start, $a->getEndsOn(), $a) as $other) {
                if ($other->getPartner() !== $a->getPartner()) {
                    continue;
                }
                if (!$replace) {
                    throw new PartnerPlacementConflict(sprintf('« %s » est déjà sur la page « Nos partenaires » pendant cette période.', $a->getPartner()?->getName()), [$this->conflict($other)]);
                }
                $this->makeRoom($other, $start, $a->getEndsOn());
            }
            $a->publish();

            return;
        }
        $overlapping = $this->assignments->findOverlapping($a->getPlacement(), $start, $a->getEndsOn(), $a);
        if ($overlapping !== [] && !$replace) {
            $conflicts = array_map($this->conflict(...), $overlapping);
            throw new PartnerPlacementConflict(self::conflictMessage($conflicts), $conflicts);
        }
        foreach ($overlapping as $other) {
            $this->makeRoom($other, $start, $a->getEndsOn());
        }
        $a->publish();
    }

    /**
     * Libère [$start, $end] dans une affectation publiée du même emplacement : avant la
     * période, elle s'arrête la veille ; après, elle reprend le lendemain (copie) ; entièrement
     * couverte, elle est retirée.
     */
    private function makeRoom(Assignment $other, \DateTimeImmutable $start, ?\DateTimeImmutable $end): void
    {
        $otherStart = $other->getStartsOn()->format('Y-m-d');
        $otherEnd = $other->getEndsOn()?->format('Y-m-d');
        $continues = $end !== null && ($otherEnd === null || $otherEnd > $end->format('Y-m-d'));

        if ($continues) {
            $after = (new Assignment())
                ->setPlacement($other->getPlacement())
                ->setPartner($other->getPartner())
                ->setStartsOn($end->modify('+1 day'))
                ->setEndsOn($other->getEndsOn())
                ->publish();
            $this->em->persist($after);
        }
        if ($otherStart < $start->format('Y-m-d')) {
            $other->setEndsOn($start->modify('-1 day'))->touch();
        } else {
            $other->withdraw();
        }
    }

    /** @return array<string, mixed> */
    private function conflict(Assignment $other): array
    {
        return [
            'id' => $other->getId(),
            'placement' => $other->getPlacement(),
            'placementLabel' => Assignment::LABELS[$other->getPlacement()],
            'partnerName' => $other->getPartner()?->getName(),
            'startsOn' => $other->getStartsOn()?->format('Y-m-d'),
            'endsOn' => $other->getEndsOn()?->format('Y-m-d'),
        ];
    }

    /** @param list<array<string, mixed>> $conflicts */
    private static function conflictMessage(array $conflicts): string
    {
        $parts = array_map(static fn (array $c) => sprintf(
            '%s : %s %s',
            $c['placementLabel'],
            $c['partnerName'] ?? 'emplacement masqué',
            $c['endsOn'] === null ? sprintf('depuis le %s, sans fin', $c['startsOn']) : sprintf('du %s au %s', $c['startsOn'], $c['endsOn']),
        ), $conflicts);

        return 'Emplacement déjà attribué sur cette période (' . implode(' ; ', $parts) . '). Confirmez le remplacement ou changez les dates.';
    }

    /** Vérifie un visuel de firme (importé par /api/admin/marketing/images) ; null si vide. */
    public static function coverImage(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !preg_match(MarketingImage::PATH_PATTERN, $value)) {
            throw new \InvalidArgumentException('Visuel invalide : importez-le de nouveau.');
        }

        return $value;
    }

    private static function date(mixed $value, string $label): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone(PartnerManager::TIMEZONE)) : false;
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException("$label invalide (AAAA-MM-JJ).");
        }

        return $date;
    }
}
