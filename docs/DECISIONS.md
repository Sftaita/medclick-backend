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
**Statut : Acceptée** — pour l'export Excel : **remplacée par ADR-202 et ADR-203** (28/10/2026) ;
le principe reste en vigueur pour les autres routes (`/surgeries/addNewSurgery`, `/favorites/addNew`).
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
**Statut : Acceptée** — complétée par ADR-202 (générateur unique, format figé par des références).
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
**Statut : Acceptée** — 30/09/2026 : Symfony 5.4 LTS, puis le même jour **Symfony 8.1**,
API Platform 4.4, Doctrine ORM 3 / DBAL 4, lexik 3, PHPUnit 12, **PHP 8.4** (à la demande du
porteur du projet, plutôt que 7.4 LTS). Conséquence : 8.1 n'est pas LTS, prévoir les montées
mineures jusqu'à la LTS 8.4 (novembre 2027). Les subscribers `kernel.view` sont devenus des
state processors (`src/State/`) ; le format JSON du front est figé par `FrontCompatibilityTest`.
Raison : Symfony 5.1 et API Platform 2.5 ne reçoivent plus de correctifs de sécurité.
Approche : 5.1 → 5.4 (déprécations) → 6.4 LTS ; API Platform 2.5 → 2.7 → 3.x ;
annotations → attributs PHP 8 (Rector).

## ADR-104 — Couche service et suppression des endpoints obsolètes
**Statut : Proposée** — réalisée en partie pour l'export Excel (28/10/2026) : générateur en
service (`src/Export/CarnetDeStage/`), ancien générateur supprimé (ADR-202).
Décision : extraire la logique métier des contrôleurs vers `src/Service/` (ex.
`SurgeryFactory`, `LogbookExporter`), versionner l'API (`/api/v2/...`) et retirer les
anciennes routes après une date annoncée au front.

## ADR-105 — Migrations Doctrine comme unique source du schéma
**Statut : Proposée** — depuis le 30/09/2026, toute nouvelle table ou colonne est livrée par
migration ; la baseline reste à faire (le schéma historique n'est pas couvert, et un index ajouté
hors Doctrine existe en production sur `nomenclature.name`, voir docs/PLAN-MEP-EXPORTS.md).
Décision : baseline de migration à partir de la prod, plus aucun `schema:update` ;
toute modification d'entité est livrée avec sa migration.

## ADR-106 — Conformité RGPD pour des données de santé/professionnelles
**Statut : Proposée**
Décision : registre des traitements, export et suppression de compte en libre-service,
durée de conservation de `ConnectionHistory`, journal d'audit des accès admin, hébergement
UE, sauvegardes chiffrées.

---

## Décisions de MedClick 3.2.0 (en production depuis le 28/10/2026)

Contexte commun : l'export synchrone du carnet (`/api/excel2`) dépassait les 30 s du PHP web sur
les gros carnets (réponses 500). Détail technique : `docs/EXPORTS.md` ; mise en production et
journal : `docs/PLAN-MEP-EXPORTS.md`.

## ADR-201 — Export Excel asynchrone : Symfony Messenger, transport Doctrine, worker dédié
**Statut : Acceptée (28/10/2026)**
Décision : `POST /api/exports` crée une demande (`export_job`) et un message dans la base
existante (`messenger_messages`) ; un conteneur `worker` (même image, 1 CPU / 768 Mo, une
génération à la fois) produit le fichier ; le front interroge l'état (1 s puis 2 s) et télécharge.
Progression réelle (pourcentage seulement pour les étapes dont le total est connu), historique
permanent sans donnée médicale, fichiers supprimés après 2 h, pas de double génération.
Alternatives écartées : export synchrone optimisé seul (reste exposé à la limite de 30 s),
Redis/RabbitMQ (service supplémentaire sur un VPS partagé), SSE (l'interrogation suffit).
Conséquences : un conteneur de plus ; migrations `Version20261027090000` à `Version20261029090000`.

## ADR-202 — Générateur unique du carnet de stage, format figé par des fichiers de référence
**Statut : Acceptée (28/10/2026)** — remplace ADR-006 pour l'Excel, complète ADR-008.
Décision : un seul générateur, `src/Export/CarnetDeStage/` (algorithme du format historique,
lignes insérées par section : de 82 s à environ 7 s sur le carnet de test le plus lourd).
L'ancien export « Excel (Old) » (autre mise en page) est supprimé le 27/10/2026 ; la copie de
l'ancien générateur gardée comme oracle de test est supprimée le 28/10/2026, après le gel de ses
sorties : 30 références dans `tests/Fixtures/excel-reference/` (données fictives), comparées
cellule par cellule et pour la mise en forme par `ExcelReferenceTest`.
Règle : une référence ne se régénère pas ; toute différence est une régression, sauf changement
de format décidé explicitement (nouvelles références, nouvelle validation).

## ADR-203 — API Excel canonique `/api/exports`, alias synchrones temporaires
**Statut : Acceptée (28/10/2026)**
Décision : `/api/exports` est la seule API du nouveau front (un bouton « Excel » par année).
`GET /api/excel2/{year}` et `GET /api/excel/{year}` restent des alias synchrones du même
générateur, contrat inchangé (200 + `.xlsx`, 401, 403, 404), historisés, pour l'ancien front CRA
encore servi et les onglets ouverts avant la mise à jour.
Retrait : quand les journaux Traefik ne montrent plus d'appel pendant une durée convenue, et
après vérification de l'ancien front (dossier `src/js` du dépôt front, non déployé par la chaîne
du VPS, qui affiche encore « Excel (New) / Excel (Old) » et appelle ces alias).

## ADR-204 — Écarts historiques des totaux (H1 à H15) volontairement reportés
**Statut : Acceptée (28/10/2026)**
Contexte : l'audit indépendant des totaux (`docs/AUDIT-TOTAUX-EXCEL.md`) a relevé 15 écarts du
format historique (ex. H1 : interventions de chirurgie générale rangées sous une autre spécialité).
Décision du responsable : la 3.2.0 reproduit le fichier à l'identique ; les écarts sont analysés
et corrigés **après** sa mise en production, chacun par une décision explicite.
Conséquences : `TotalsAuditTest` fige l'état audité (chaque écart connu doit garder exactement son
effet) ; corriger un écart = changer le fichier officiel, donc modifier ce test, régénérer les
références concernées (ADR-202) et faire valider le nouveau format.

## ADR-205 — Surveillance des exports : worker, gardien sur l'hôte, surveillance croisée
**Statut : Acceptée (28/10/2026)**
Décision : la surveillance (exports bloqués, jamais pris en charge, expirations, alertes à
renvoyer) tourne chaque minute dans le worker **et** dans un gardien indépendant (cron de l'hôte,
`app:exports:monitor --watchdog` dans le conteneur de l'API), une seule à la fois (bail en base).
Le gardien signale un worker sans signe de vie depuis 3 min ; le worker signale un gardien déjà vu
qui ne passe plus depuis 10 min. Un incident = un e-mail (anti-doublon, renvois à 5, 15, 60 et
240 min), clos au retour du service. Aucune détection ne dépend d'une consultation utilisateur.
Limite connue : si le worker et le gardien s'arrêtent ensemble (VPS ou Docker arrêté), l'API l'est
aussi : relève d'une surveillance externe du site (non mise en place).

## ADR-206 — Alertes techniques par e-mail
**Statut : Acceptée (28/10/2026)**
Décision : `ERROR_ALERT_EMAIL` (erreurs 500 et erreurs des fronts, une alerte par empreinte
nouvelle ou rouverte) et `EXPORT_ALERT_EMAIL` (exports, worker, gardien ; vide ou absente : repli
sur `ERROR_ALERT_EMAIL`), dans le `.env` du serveur uniquement : **aucune adresse dans le dépôt**.
Préproduction : toutes les alertes sont capturées par Mailpit. Contenu : identifiants de
diagnostic, jamais de donnée médicale, de corps de requête ni de pièce jointe.

## ADR-207 — Règles de déploiement sur le VPS
**Statut : Acceptée (28/10/2026)**
Décision :
- déploiement à SHA explicites par `deploy.sh` ; étiquettes d'images enregistrées dans `.env`, le
  Compose refuse une étiquette absente (aucune commande manuelle ne relance une image ancienne) ;
- une migration en attente arrête le déploiement : examen humain, exécution explicite, relance ;
- toujours la préproduction d'abord ; la production seulement avec l'autorisation explicite du
  responsable, après sauvegarde chiffrée hors VPS, dump local et copies de la configuration et
  de la crontab ;
- fusion dans `master`/`main` sans squash ni rebase, l'arbre fusionné devant être identique à
  celui validé en préproduction ;
- dépôt backend public : contrôle avant chaque push (secrets, données réelles, fichiers Excel,
  sauvegardes, détail d'une faille non déployée).

## ADR-208 — Correctifs de sécurité des dépendances isolés
**Statut : Acceptée (28/10/2026)**
Décision : une mise à jour de sécurité est un commit dédié (dépendance seule), avec un test de
non-régression ciblé si nécessaire ; `composer audit` et `npm audit --omit=dev` sont vérifiés avant
chaque mise en production. 3.2.0 : symfony/expression-language 8.1.9, dompurify 3.4.17.
Les alertes restantes de `npm audit` ne concernent que les outils de développement (non livrés) :
à traiter séparément.

## ADR-209 — Version applicative unique
**Statut : Acceptée (28/10/2026)**
Décision : la version affichée (pied de page) est celle du `package.json` du front ; chaque version
a une entrée en tête de `frontend/docs/VERSIONS.md` (dépôt front) ; un test vérifie leur concordance.
