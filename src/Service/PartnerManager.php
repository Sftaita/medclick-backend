<?php

namespace App\Service;

use App\Entity\Partner;
use App\Repository\PartnerLogoRepository;
use App\Repository\PartnerRepository;

/**
 * Règles du module partenaire, partagées par l'API publique et l'administration : date du
 * jour (Europe/Brussels), validation des champs, publication sans chevauchement, format JSON.
 */
class PartnerManager
{
    public const TIMEZONE = 'Europe/Brussels';

    private const MONTHS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

    public function __construct(
        private readonly PartnerRepository $partners,
        private readonly PartnerLogoRepository $logos,
    ) {
    }

    /** Jour courant en Belgique (les dates de publication sont des jours entiers, inclus). */
    public function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', new \DateTimeZone(self::TIMEZONE));
    }

    public function findLive(): ?Partner
    {
        return $this->partners->findLive($this->today());
    }

    /**
     * Applique les champs envoyés par l'administration. Contrôle de forme seulement : un
     * brouillon peut être incomplet (logo, dates), la publication est contrôlée par publish().
     *
     * @throws \InvalidArgumentException message lisible
     */
    public function apply(Partner $partner, mixed $data): void
    {
        $this->applyProfile($partner, $data);

        $startsOn = self::date($data['startsOn'] ?? null, 'Date de début');
        $endsOn = self::date($data['endsOn'] ?? null, 'Date de fin');
        if ($startsOn !== null && $endsOn !== null && $endsOn->format('Y-m-d') < $startsOn->format('Y-m-d')) {
            throw new \InvalidArgumentException('La date de fin doit suivre la date de début.');
        }
        $partner->setStartsOn($startsOn)->setEndsOn($endsOn);
    }

    /**
     * Fiche de la firme seulement (nom, site, accroche, texte, engagements, logo, visuel) : la
     * période et le statut de l'ancien modèle ne sont pas touchés.
     *
     * @throws \InvalidArgumentException message lisible
     */
    public function applyProfile(Partner $partner, mixed $data): void
    {
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Requête invalide.');
        }
        $text = static function (string $key, int $max, string $label) use ($data): ?string {
            $value = $data[$key] ?? null;
            if ($value === null) {
                return null;
            }
            if (!is_string($value)) {
                throw new \InvalidArgumentException("$label : texte attendu.");
            }
            $value = trim($value);
            if (mb_strlen($value) > $max) {
                throw new \InvalidArgumentException("$label : $max caractères au maximum.");
            }

            return $value === '' ? null : $value;
        };

        $name = $text('name', 120, 'Nom du partenaire');
        if ($name === null) {
            throw new \InvalidArgumentException('Le nom du partenaire est obligatoire.');
        }
        $website = $text('websiteUrl', 500, 'Site web');
        if ($website !== null && !self::isHttpUrl($website)) {
            throw new \InvalidArgumentException('Site web : adresse commençant par https:// attendue.');
        }

        $commitments = $data['commitments'] ?? [];
        if (!is_array($commitments)) {
            throw new \InvalidArgumentException('Engagements : liste attendue.');
        }
        $commitments = array_values(array_filter(array_map(
            static fn ($item) => is_string($item) ? trim($item) : throw new \InvalidArgumentException('Engagements : texte attendu.'),
            $commitments
        ), static fn (string $item) => $item !== ''));
        if (count($commitments) > Partner::MAX_COMMITMENTS) {
            throw new \InvalidArgumentException('4 engagements au maximum.');
        }
        foreach ($commitments as $item) {
            if (mb_strlen($item) > 60) {
                throw new \InvalidArgumentException('Chaque engagement : 60 caractères au maximum.');
            }
        }

        $logo = null;
        if (!empty($data['logo'])) {
            $logo = is_string($data['logo']) ? $this->logos->findByFileName($data['logo']) : null;
            if ($logo === null) {
                throw new \InvalidArgumentException('Logo introuvable : envoyez-le de nouveau.');
            }
        }

        // Visuel : absent de la requête (ancienne administration) = inchangé.
        if (array_key_exists('coverImage', $data)) {
            $partner->setCoverImage(PartnerPlacementManager::coverImage($data['coverImage']));
        }

        $partner->setName($name)
            ->setWebsiteUrl($website)
            ->setTagline($text('tagline', 160, 'Accroche'))
            ->setAbout($text('about', 1000, 'Texte « À propos »'))
            ->setCommitments($commitments)
            ->setLogo($logo)
            ->touch();
    }

    /**
     * Publie (ou republie après modification) : logo et date de début obligatoires, période non
     * terminée, aucun chevauchement avec un autre partenaire publié.
     *
     * @throws \InvalidArgumentException message lisible
     */
    public function publish(Partner $partner): void
    {
        if ($partner->getLogo() === null) {
            throw new \InvalidArgumentException('Ajoutez un logo avant de publier.');
        }
        if ($partner->getStartsOn() === null) {
            throw new \InvalidArgumentException('Indiquez la date de début d\'affichage.');
        }
        // Jours comparés en AAAA-MM-JJ (voir Partner::stateOn).
        if ($partner->getEndsOn() !== null && $partner->getEndsOn()->format('Y-m-d') < $this->today()->format('Y-m-d')) {
            throw new \InvalidArgumentException('La date de fin est déjà passée : choisissez une période à venir.');
        }
        $other = $this->partners->findOverlapping($partner->getStartsOn(), $partner->getEndsOn(), $partner);
        if ($other !== null) {
            throw new \InvalidArgumentException(sprintf(
                'Cette période chevauche celle de « %s » (%s). Un seul partenaire peut être affiché à la fois : modifiez les dates ou retirez « %s ».',
                $other->getName(),
                $this->period($other),
                $other->getName(),
            ));
        }
        $partner->publish();
    }

    /** Format JSON commun (public et administration). */
    public function toArray(Partner $partner, bool $admin = false): array
    {
        $data = [
            'id' => $partner->getId(),
            'name' => $partner->getName(),
            'websiteUrl' => $partner->getWebsiteUrl(),
            'tagline' => $partner->getTagline(),
            'about' => $partner->getAbout(),
            'commitments' => $partner->getCommitments(),
            // Nom de fichier : le front le sert via <API>/partner/logo/<logo>.
            'logo' => $partner->getLogo()?->getFileName(),
            'coverImage' => $partner->getCoverImage(),
            'startsOn' => $partner->getStartsOn()?->format('Y-m-d'),
            'endsOn' => $partner->getEndsOn()?->format('Y-m-d'),
        ];
        if ($admin) {
            $data += [
                'status' => $partner->getStatus(),
                'state' => $partner->stateOn($this->today()),
                'publishedAt' => $partner->getPublishedAt()?->format(\DATE_ATOM),
                'withdrawnAt' => $partner->getWithdrawnAt()?->format(\DATE_ATOM),
                'updatedAt' => $partner->getUpdatedAt()->format(\DATE_ATOM),
            ];
        }

        return $data;
    }

    /** « du 1 sept. 2026 au 31 août 2027 » / « à partir du 1 sept. 2026 ». */
    public function period(Partner $partner): string
    {
        $start = $partner->getStartsOn();
        $end = $partner->getEndsOn();
        if ($start === null) {
            return 'sans date';
        }

        return $end === null ? 'à partir du ' . self::frenchDate($start) : 'du ' . self::frenchDate($start) . ' au ' . self::frenchDate($end);
    }

    private static function frenchDate(\DateTimeImmutable $date): string
    {
        return $date->format('j') . ' ' . self::MONTHS[(int) $date->format('n') - 1] . ' ' . $date->format('Y');
    }

    private static function date(mixed $value, string $label): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone(self::TIMEZONE)) : false;
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException("$label invalide (AAAA-MM-JJ).");
        }

        return $date;
    }

    private static function isHttpUrl(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
