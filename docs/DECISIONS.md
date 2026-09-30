# Registre des décisions d'architecture (ADR)

Format court : contexte → décision → conséquences. Statuts : **Acceptée** (en place dans le
code), **Proposée** (issue de l'audit, à valider), **Remplacée**.
Ajouter une entrée à chaque choix structurant ; ne pas réécrire une décision acceptée,
en créer une nouvelle qui la remplace.

---

## ADR-001 — Backend API séparé du frontend
**Statut : Acceptée (reconstituée)**
Contexte : application web/mobile (SPA sur medclick.be).
Décision : Symfony expose uniquement une API JSON ; le front vit dans un autre dépôt.
Conséquences : CORS à configurer (`nelmio_cors`), versionnement d'API nécessaire car
plusieurs versions du front peuvent être en circulation.

## ADR-002 — API Platform pour le CRUD, contrôleurs custom pour le métier
**Statut : Acceptée (reconstituée)**
Décision : les entités simples sont exposées via `@ApiResource` ; les opérations qui
dérivent des données (nomenclature → intervention, export Excel, admin) passent par des
contrôleurs Symfony.
Conséquences : deux modèles de sécurité à maintenir ; la doc OpenAPI ne couvre pas les
contrôleurs custom.

## ADR-003 — Authentification JWT stateless (LexikJWT)
**Statut : Acceptée (reconstituée)**
Décision : login par email/mot de passe, JWT signé RSA, informations de profil et
d'acceptation des CGU embarquées dans le payload.
Conséquences : pas de révocation (pas de refresh token) ; un changement de rôle ou de CGU
n'est visible qu'au prochain login.

## ADR-004 — Isolation des données par `CurrentUserExtension`
**Statut : Acceptée (reconstituée)**
Décision : les lectures API Platform sont filtrées sur l'utilisateur connecté via une
extension Doctrine.
Conséquences : protège aussi la résolution des IRI en écriture, mais pas les contrôleurs
custom qui chargent les entités directement (voir ADR-101).

## ADR-005 — Activation de compte par email
**Statut : Acceptée**
Décision : `User.token` non null ⇒ compte inactif ; `UserChecker` bloque le login.

## ADR-006 — Coexistence des anciennes et nouvelles versions d'endpoint
**Statut : Acceptée**
Contexte : ne pas casser les utilisateurs d'anciennes versions du front.
Décision : une nouvelle route est créée à côté de l'ancienne (`/surgeries/addNewSurgery`,
`/excel2`, `/favorites/addNew`).
Conséquences : duplication (deux générateurs Excel de ~1 300 et ~1 500 lignes). Voir ADR-104.

## ADR-007 — Nomenclature INAMI comme référentiel, données copiées dans l'intervention
**Statut : Acceptée**
Décision : `Surgeries` et `Favorites` référencent `Nomenclature` **et** copient
nom/code/spécialité (historique figé si la nomenclature change).
Conséquences : commandes de rattrapage `app:update-surgeries` / `app:update-favorites`.

## ADR-008 — Carnet de stage généré depuis un gabarit Excel
**Statut : Acceptée**
Décision : PhpSpreadsheet remplit `public/ExcelTemplate.xlsx`, format attendu par les
maîtres de stage / la commission.

## ADR-009 — Statistiques dénormalisées recalculées à la demande
**Statut : Acceptée**
Décision : entité `Statistics` (1-1 avec `User`) mise à jour par un admin via
`/api/statistics/update/{userId}`.

---

## Décisions proposées (voir `docs/AUDIT.md`)

## ADR-101 — Autorisation par Voters et `security` sur chaque opération
**Statut : Acceptée (30/09/2026)** — `OwnershipVoter` (attribut `OWNER`) en place, appliqué
aux entités API Platform et aux contrôleurs d'interventions et de favoris.
Décision : chaque `@ApiResource` déclare `security`/`security_post_denormalize`
(`object.getYear().getUser() == user`) ; chaque contrôleur custom appelle
`denyAccessUnlessGranted('OWNER', $resource)` via un Voter unique `OwnershipVoter`.
Raison : aujourd'hui l'écriture sur l'année d'un autre utilisateur est possible.

## ADR-102 — Groupes de dénormalisation explicites (DTO d'inscription)
**Statut : Acceptée (30/09/2026)** — groupe `user_write` ; le mot de passe est aussi hashé en
PUT/PATCH s'il change. Le passage à un `plainPassword` non mappé reste à faire.
Décision : `User` n'accepte en écriture que `email`, `password` (plainPassword),
`firstname`, `lastname`, `speciality`. `roles`, `token`, `resetToken`, `validatedAt`
ne sont jamais inscriptibles via l'API.

## ADR-103 — Montée de version : Symfony 6.4 LTS / 7.x, API Platform 3.x, PHP 8.2+
**Statut : Proposée**
Raison : Symfony 5.1 et API Platform 2.5 ne reçoivent plus de correctifs de sécurité.
Approche : 5.1 → 5.4 (déprécations) → 6.4 LTS ; API Platform 2.5 → 2.7 → 3.x ;
annotations → attributs PHP 8 (Rector).

## ADR-104 — Couche service et suppression des endpoints obsolètes
**Statut : Proposée**
Décision : extraire la logique métier des contrôleurs vers `src/Service/` (ex.
`SurgeryFactory`, `LogbookExporter`), versionner l'API (`/api/v2/...`) et retirer les
anciennes routes après une date annoncée au front.

## ADR-105 — Migrations Doctrine comme unique source du schéma
**Statut : Proposée**
Décision : baseline de migration à partir de la prod, plus aucun `schema:update` ;
toute modification d'entité est livrée avec sa migration.

## ADR-106 — Conformité RGPD pour des données de santé/professionnelles
**Statut : Proposée**
Décision : registre des traitements, export et suppression de compte en libre-service,
durée de conservation de `ConnectionHistory`, journal d'audit des accès admin, hébergement
UE, sauvegardes chiffrées.
