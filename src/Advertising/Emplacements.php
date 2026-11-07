<?php

declare(strict_types=1);

namespace App\Advertising;

use App\Entity\Marketing;
use App\Entity\PartnerPlacementAssignment as Assignment;

/**
 * Les huit emplacements réels de l'application (docs/PUBLICITES-PARTENAIRES.md), tels que
 * l'administration les présente : un écran, une forme d'affichage, un type de contenu.
 *
 * - kind « ads » : publicités (Marketing), diffusées sur les placements techniques listés dans
 *   « supports » (ordinateur / mobile) ; « capacity » publicités au plus en même temps.
 * - kind « partner » : attribution d'une entreprise (PartnerPlacementAssignment) ; « shared »
 *   = plusieurs entreprises en même temps (page « Nos partenaires »), sinon une seule.
 *
 * Seule règle entre deux emplacements : l'encart partenaire de la page d'identification
 * (placement technique login_fallback, géré dans l'emplacement « login ») n'est affiché que
 * lorsqu'aucune publicité de cette page n'est en ligne. Ils occupent le même espace à l'écran.
 */
final class Emplacements
{
    public const LOGIN = 'login';
    public const AFTER_LOGIN = 'after_login';
    public const DASHBOARD_CAROUSEL = 'dashboard_carousel';
    public const DASHBOARD_PARTNER = Assignment::DASHBOARD_PARTNER;
    public const SIDEBAR = Assignment::SIDEBAR;
    public const MOBILE_MENU = Assignment::MOBILE_MENU;
    public const PUBLIC_HOME = Assignment::PUBLIC_HOME;
    public const PARTNERS_PAGE = Assignment::PARTNERS_PAGE;
    /** Recherche publique des codes INAMI (docs/RECHERCHE-INAMI.md). */
    public const INAMI_SEARCH = Marketing::PLACEMENT_INAMI_SEARCH;

    public const SCREENS = [
        'connexion' => 'Connexion',
        'tableau_de_bord' => 'Tableau de bord',
        'navigation' => 'Navigation',
        'pages_publiques' => 'Pages publiques',
    ];

    /** Fréquences du plein écran après connexion (par navigateur et par publicité). */
    public const FREQUENCIES = [
        'every_login' => 'À chaque connexion',
        'daily' => 'Une fois par jour',
        'weekly' => 'Une fois par semaine',
    ];
    public const DEFAULT_FREQUENCY = 'daily';

    private const ALL = [
        self::LOGIN => [
            'screen' => 'connexion',
            'label' => "Page d'identification — Publicité",
            'description' => "Publicité affichée sur la page où l'utilisateur introduit son e-mail et son mot de passe : sur la photo (ordinateur) ou sous le formulaire (mobile). Page publique : visible sans compte.",
            'kind' => 'ads',
            'capacity' => 3,
            'supports' => ['desktop' => Marketing::PLACEMENT_LOGIN, 'mobile' => Marketing::PLACEMENT_LOGIN],
            'sameCampaignForSupports' => true,
            'public' => true,
            'partnerFallback' => Assignment::LOGIN_FALLBACK,
        ],
        self::AFTER_LOGIN => [
            'screen' => 'connexion',
            'label' => 'Après connexion — Publicité plein écran',
            'description' => "Publicité grand format affichée juste après une connexion réussie, avec un bouton pour fermer et continuer vers l'application. Sans publicité en ligne, l'utilisateur arrive directement sur le tableau de bord.",
            'kind' => 'ads',
            'capacity' => 1,
            'supports' => ['desktop' => Marketing::PLACEMENT_LOGIN_INTERSTITIAL, 'mobile' => Marketing::PLACEMENT_LOGIN_INTERSTITIAL],
            'sameCampaignForSupports' => true,
            'public' => false,
        ],
        self::DASHBOARD_CAROUSEL => [
            'screen' => 'tableau_de_bord',
            'label' => 'Tableau de bord — Carrousel publicitaire',
            'description' => "Carrousel d'images du tableau de bord : colonne de droite sur ordinateur et tablette, en haut de l'écran sur téléphone.",
            'kind' => 'ads',
            'capacity' => 3,
            'supports' => ['desktop' => Marketing::PLACEMENT_DASHBOARD_DESKTOP, 'mobile' => Marketing::PLACEMENT_DASHBOARD_MOBILE],
            'sameCampaignForSupports' => false,
            'public' => false,
        ],
        self::DASHBOARD_PARTNER => [
            'screen' => 'tableau_de_bord',
            'label' => 'Tableau de bord — Partenaire du mois',
            'description' => "Encart « Partenaire du mois » du tableau de bord (logo et accroche), lien vers la présentation de l'entreprise. Affiché en même temps que le carrousel.",
            'kind' => 'partner',
            'shared' => false,
            'public' => false,
        ],
        self::SIDEBAR => [
            'screen' => 'navigation',
            'label' => 'Navigation ordinateur — Barre latérale',
            'description' => "« Avec le soutien de » et le logo de l'entreprise, dans la barre latérale de toutes les pages connectées (ordinateur et tablette).",
            'kind' => 'partner',
            'shared' => false,
            'public' => false,
        ],
        self::MOBILE_MENU => [
            'screen' => 'navigation',
            'label' => 'Navigation mobile — Menu',
            'description' => "Mention « Partenaire » et logo de l'entreprise dans le menu des téléphones.",
            'kind' => 'partner',
            'shared' => false,
            'public' => false,
        ],
        self::PUBLIC_HOME => [
            'screen' => 'pages_publiques',
            'label' => 'Accueil public — Sponsor',
            'description' => "Encart « Application sponsorisée par » de la page d'accueil des visiteurs non connectés.",
            'kind' => 'partner',
            'shared' => false,
            'public' => true,
        ],
        self::PARTNERS_PAGE => [
            'screen' => 'pages_publiques',
            'label' => 'Page « Nos partenaires »',
            'description' => "Présentation des entreprises sélectionnées, avec logos et liens. Accessible depuis la barre latérale, le menu mobile et l'accueil public.",
            'kind' => 'partner',
            'shared' => true,
            'public' => true,
        ],
        self::INAMI_SEARCH => [
            'screen' => 'pages_publiques',
            'label' => 'Recherche INAMI — Bannière',
            'description' => "Bannière en haut de la recherche publique des codes INAMI (/codes-inami), sur ordinateur (1200 × 150) et sur mobile (640 × 200), affichée entière. Page publique : la déclaration « professionnel de la santé » n'est pas une vérification, aucune publicité réservée aux professionnels n'y est diffusée.",
            'kind' => 'ads',
            'capacity' => 3,
            'supports' => ['desktop' => Marketing::PLACEMENT_INAMI_SEARCH, 'mobile' => Marketing::PLACEMENT_INAMI_SEARCH],
            'sameCampaignForSupports' => true,
            'public' => true,
        ],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    public static function exists(string $key): bool
    {
        return isset(self::ALL[$key]);
    }

    /** @return array<string, mixed> */
    public static function get(string $key): array
    {
        if (!isset(self::ALL[$key])) {
            throw new \InvalidArgumentException('Emplacement inconnu.');
        }

        return ['key' => $key] + self::ALL[$key];
    }

    public static function isAds(string $key): bool
    {
        return (self::ALL[$key]['kind'] ?? null) === 'ads';
    }

    /**
     * Placements techniques des publicités d'un emplacement (sans doublon).
     *
     * @return list<string>
     */
    public static function campaignPlacements(string $key): array
    {
        return array_values(array_unique(self::ALL[$key]['supports'] ?? []));
    }

    /** Emplacement d'un placement technique de publicité (login, dashboard_desktop…). */
    public static function forCampaignPlacement(string $placement): ?string
    {
        foreach (self::ALL as $key => $definition) {
            if (in_array($placement, $definition['supports'] ?? [], true)) {
                return $key;
            }
        }

        return null;
    }

    /** Emplacement d'un placement technique partenaire (login_fallback → login). */
    public static function forPartnerPlacement(string $placement): ?string
    {
        if ($placement === Assignment::LOGIN_FALLBACK) {
            return self::LOGIN;
        }

        return isset(self::ALL[$placement]) && self::ALL[$placement]['kind'] === 'partner' ? $placement : null;
    }

    /** Placement technique des attributions d'entreprise d'un emplacement, ou null. */
    public static function partnerPlacement(string $key): ?string
    {
        $definition = self::ALL[$key] ?? [];

        return match (true) {
            ($definition['kind'] ?? null) === 'partner' => $key,
            isset($definition['partnerFallback']) => $definition['partnerFallback'],
            default => null,
        };
    }

    /**
     * Libellés des statistiques, un par placement technique : un carrousel n'est jamais
     * additionné à un encart partenaire.
     */
    public const STAT_LABELS = [
        Marketing::PLACEMENT_LOGIN => "Page d'identification — Publicité",
        Assignment::LOGIN_FALLBACK => "Page d'identification — Encart partenaire",
        Marketing::PLACEMENT_LOGIN_INTERSTITIAL => 'Après connexion — Publicité plein écran',
        Marketing::PLACEMENT_DASHBOARD_DESKTOP => 'Tableau de bord — Carrousel (ordinateur)',
        Marketing::PLACEMENT_DASHBOARD_MOBILE => 'Tableau de bord — Carrousel (mobile)',
        Assignment::DASHBOARD_PARTNER => 'Tableau de bord — Partenaire du mois',
        Assignment::SIDEBAR => 'Navigation ordinateur — Barre latérale',
        Assignment::MOBILE_MENU => 'Navigation mobile — Menu',
        Assignment::PUBLIC_HOME => 'Accueil public — Sponsor',
        Assignment::PARTNERS_PAGE => 'Page « Nos partenaires »',
        Marketing::PLACEMENT_INAMI_SEARCH => 'Recherche INAMI — Bannière',
    ];
}
