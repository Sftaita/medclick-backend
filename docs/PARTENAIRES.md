# Firmes partenaires par emplacement

Deux systèmes distincts, à ne pas confondre :

- **Publicités** (campagnes, `Marketing`, docs/SPONSORS.md) : carrousels de la connexion et de
  l'Accueil, plein écran après connexion ; rotation, priorité, exclusivité.
- **Partenaires** (ce document) : six emplacements permanents occupés chacun par une firme.

## Modèle

| Table | Rôle |
|---|---|
| `partner` | Fiche d'une firme : nom, logo, site, accroche, texte, engagements, visuel (`cover_image`). Les colonnes de période et de statut appartiennent à l'ancien modèle « un seul partenaire » et ne décident plus de l'affichage (conservées pour le retour arrière). |
| `partner_placement_assignment` | Firme d'un emplacement pour une période (jours inclus, Europe/Brussels) ; `partner_id` null = emplacement volontairement masqué. Statuts : `draft`, `published`, `withdrawn`. |
| `partner_event` | Impressions réellement vues et clics, par firme et emplacement (aucune donnée nominative, empreinte quotidienne effacée après 24 h). |

Emplacements : `login_fallback`, `dashboard_partner`, `sidebar`, `mobile_menu`, `partners_page`
(partagé : plusieurs firmes en même temps), `public_home`.

## Règles

- **Aucune firme privilégiée** : sans affectation publiée en cours, l'emplacement n'affiche rien.
- Emplacement exclusif : une seule affectation publiée à la fois. Publier sur une période déjà
  occupée renvoie **409** avec la liste des affectations en conflit ; avec `replace: true`,
  `PartnerPlacementManager::makeRoom` raccourcit l'affectation existante (fin la veille), la
  retire si elle est entièrement couverte, et crée une suite à partir du lendemain de la nouvelle
  période si elle allait au-delà (retour automatique de la firme précédente).
- Page partagée : plusieurs firmes ; la même firme deux fois sur la même période est refusée (ou
  raccourcie avec `replace`).
- Plusieurs emplacements demandés ensemble : une seule transaction, tout ou rien.
- Date de début passée refusée pour une nouvelle affectation ; une firme sans logo n'est jamais
  diffusée.

## API

Publiques (`security.yaml`) :

- `GET /api/partner/placements` : `{ login_fallback: firme|null, …, partners_page: [firmes] }`.
- `GET /api/partner/{id}` : fiche d'une firme diffusée aujourd'hui, sinon 404.
- `POST /api/partner/events` : `{ partner, placement, type: impression|click, visitor }`, compté
  seulement si la firme est diffusée sur cet emplacement aujourd'hui ; limité en débit
  (`partner_event`).
- `GET /api/partner/active` : ancienne API, firme du « Partenaire du mois » (sinon du premier
  emplacement occupé) pour les clients d'avant les emplacements.

Administration (ROLE_ADMIN) :

- `/api/admin/partner-firms` : `GET`, `POST`, `PUT /{id}`, `DELETE /{id}` (firme jamais
  attribuée seulement). Logo : `POST /api/admin/partner-logos` ; visuel :
  `POST /api/admin/marketing-images`.
- `/api/admin/partner-placements` : `GET` (calendrier), `POST` (`placements`, `partnerId`,
  `startsOn`, `endsOn`, `publish`, `replace`), `PUT /{id}`, `POST /{id}/publish`,
  `POST /{id}/withdraw`, `DELETE /{id}` (brouillon), `GET /stats?from=&to=`.

## Migration `Version20261102090000`

Additive : création des deux tables, `partner.cover_image`, `marketing.professionals_only`, puis
reprise du partenaire publié en cours ou à venir (BOSI) sur les six emplacements aux mêmes dates.
Testée sur une copie de la base de préproduction (MariaDB), montée et descente.
