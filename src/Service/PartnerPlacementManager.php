<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Partner;
use App\Entity\PartnerPlacementAssignment;
use App\Repository\PartnerPlacementAssignmentRepository;
use App\Repository\PartnerRepository;

/**
 * Gère les six points de visibilité historiquement liés à un seul Partner.
 * Absence d'affectation => fallback vers l'ancien partenaire global, sans interruption.
 * Affectation null => masquage explicite ; affectation Partner => firme de l'emplacement.
 */
final class PartnerPlacementManager
{
    public const KEYS = [
        'login_fallback', 'dashboard_partner', 'sidebar',
        'mobile_menu', 'partners_page', 'public_home',
    ];

    public function __construct(
        private readonly PartnerPlacementAssignmentRepository $assignments,
        private readonly PartnerRepository $partners,
        private readonly PartnerManager $legacy,
    ) {
    }

    /** @return array<string, ?array> */
    public function activeMap(): array
    {
        $legacy = $this->legacy->findLive();
        $default = $legacy === null ? null : $this->legacy->toArray($legacy);
        $result = array_fill_keys(self::KEYS, $default);

        foreach ($this->assignments->findLive($this->legacy->today()) as $assignment) {
            $key = $assignment->getPlacement();
            if (!array_key_exists($key, $result)) {
                continue;
            }
            // La requête trie par date et id décroissants ; première affectation prioritaire.
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $partner = $assignment->getPartner();
            $result[$key] = $partner === null ? null : $this->legacy->toArray($partner);
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function history(): array
    {
        return array_map(fn (PartnerPlacementAssignment $a) => $this->toArray($a), $this->assignments->history());
    }

    /** @return array<string, mixed> */
    public function toArray(PartnerPlacementAssignment $a): array
    {
        $today = $this->legacy->today()->format('Y-m-d');
        $starts = $a->getStartsOn()?->format('Y-m-d');
        $ends = $a->getEndsOn()?->format('Y-m-d');
        $state = match (true) {
            $a->getStatus() === PartnerPlacementAssignment::DRAFT => 'draft',
            $a->getStatus() === PartnerPlacementAssignment::WITHDRAWN => 'withdrawn',
            $starts !== null && $starts > $today => 'scheduled',
            $ends !== null && $ends < $today => 'ended',
            default => 'live',
        };

        return [
            'id' => $a->getId(),
            'placement' => $a->getPlacement(),
            'partnerId' => $a->getPartner()?->getId(),
            'partnerName' => $a->getPartner()?->getName(),
            'startsOn' => $starts,
            'endsOn' => $ends,
            'status' => $a->getStatus(),
            'state' => $state,
            'publishedAt' => $a->getPublishedAt()?->format(\DATE_ATOM),
            'withdrawnAt' => $a->getWithdrawnAt()?->format(\DATE_ATOM),
        ];
    }

    /** @throws \InvalidArgumentException */
    public function apply(PartnerPlacementAssignment $a, mixed $data): void
    {
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Requête invalide.');
        }
        $key = $data['placement'] ?? null;
        if (!is_string($key) || !in_array($key, self::KEYS, true)) {
            throw new \InvalidArgumentException('Emplacement partenaire inconnu.');
        }
        // partnerId est obligatoire même lorsqu'on veut masquer la carte (null).
        if (!array_key_exists('partnerId', $data)) {
            throw new \InvalidArgumentException('Indiquez partnerId (ou null pour masquer).');
        }
        $id = $data['partnerId'];
        if ($id !== null && (!is_int($id) || $id < 1)) {
            throw new \InvalidArgumentException('Identifiant du partenaire invalide.');
        }
        $partner = $id === null ? null : $this->partners->find($id);
        if ($id !== null && $partner === null) {
            throw new \InvalidArgumentException('Partenaire introuvable.');
        }
        if ($partner !== null && $partner->getLogo() === null) {
            throw new \InvalidArgumentException('Ajoutez un logo au partenaire avant de le diffuser.');
        }
        if ($partner !== null && $partner->getStatus() === Partner::STATUS_WITHDRAWN) {
            throw new \InvalidArgumentException('Partenaire retiré : créez un nouveau profil pour le diffuser.');
        }

        $start = self::parseDate($data['startsOn'] ?? null, 'Date de début');
        $end = self::parseDate($data['endsOn'] ?? null, 'Date de fin');
        if ($start !== null && $end !== null && $end < $start) {
            throw new \InvalidArgumentException('La date de fin doit suivre la date de début.');
        }

        $a->setPlacement($key)->setPartner($partner)->setStartsOn($start)->setEndsOn($end)->touch();
    }

    public function publish(PartnerPlacementAssignment $a): void
    {
        $start = $a->getStartsOn();
        if ($start === null) {
            throw new \InvalidArgumentException('Indiquez une date de début avant publication.');
        }
        $today = $this->legacy->today()->format('Y-m-d');
        if ($a->getEndsOn() !== null && $a->getEndsOn()->format('Y-m-d') < $today) {
            throw new \InvalidArgumentException('La date de fin est déjà passée.');
        }
        if ($other = $this->assignments->overlapping($a->getPlacement(), $start, $a->getEndsOn(), $a)) {
            throw new \InvalidArgumentException(sprintf(
                'Cet emplacement est déjà attribué sur cette période (affectation n° %d).',
                $other->getId()
            ));
        }
        $a->publish();
    }

    private static function parseDate(mixed $value, string $label): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException($label . ' invalide.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone(PartnerManager::TIMEZONE));
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException($label . ' invalide (AAAA-MM-JJ).');
        }

        return $date;
    }
}
