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
Précision (nomenclature, étape 3) : à la modification d'une intervention, la copie n'est refaite
que si un **autre** acte est choisi (`SurgeryNomenclatureSelection`) ; renvoyer l'acte lié ou
n'en envoyer aucun conserve strictement le code INAMI historique, l'intitulé, la spécialité
(y compris « favorites ») et le lien.
Précision (étape 4) : un code obsolète est désactivé, jamais supprimé (ADR-210).

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

## ADR-210 — Codes INAMI actifs et inactifs (nomenclature, étape 4)
**Statut : Proposée (01/11/2026)** — complète ADR-007.

Contexte : des codes INAMI sont abrogés ; ils doivent disparaître des nouvelles saisies sans
toucher aux carnets existants (≈ 223 900 interventions et 1 646 favoris liés à 1 775 codes).

Décision :
- `nomenclature.active` (TRUE par défaut), `inami_repealed_at` (date officielle d'abrogation,
  saisie par l'administrateur, facultative) et `deactivated_at` (date et heure techniques fixées par
  le serveur) : deux dates distinctes. Migration additive `Version20261103090000`.
- Un code est **désactivé, jamais supprimé ni renuméroté** ; ses interventions et favoris liés ne
  sont pas modifiés.
- Seule la liste de choix `GET /api/nomenclature/{speciality}` filtre sur `active`. **Aucun filtre
  Doctrine global** : interventions, favoris, statistiques, exports et administration lisent tous
  les codes.
- Code actif exigé pour : toute création d'intervention (`/api/surgeries/addNewSurgery`,
  `/api/surgeries/add`, `POST /api/surgeries`), tout changement d'acte (`PUT`/`PATCH
  /api/surgeries/{id}`, `PUT /api/surgeries/update/{id}`), tout nouveau favori ou favori redirigé
  (`/api/favorites/addNew`, `/api/favorites/updateNew`). Un code inactif déjà lié et inchangé reste
  accepté (modification d'une intervention historique, renommage d'un favori).
- Refus : contrôleurs custom 400 `{message|error, reason: "nomenclature_inactive"}` ; API Platform
  422 avec une violation sur `name`.
- Favoris : `active` (listes `getMyList` et `getById`) est le statut de la nomenclature **liée**,
  jamais déduit du code copié dans le favori ; la PWA les affiche grisés, « Code INAMI inactif »,
  non sélectionnables pour une intervention.
- Administration : `GET /api/admin/nomenclature` (toutes spécialités), `GET …/{speciality}` (statut et
  dates inclus), `GET …/{id}/usage` (interventions et favoris liés), `PUT …/{id}/status`
  `{active, inamiRepealedAt?}`, tracé sur le canal Monolog `nomenclature` (fichier
  `var/log/nomenclature-prod-AAAA-MM.log` en production, toujours écrit). La désactivation conserve la date technique de la
  première désactivation ; la réactivation l'efface et garde la date officielle sauf si elle est
  envoyée.

- **Validité historique** (décisions des 02/11/2026) : la validité d'un code dépend de la DATE
  RÉELLE de l'intervention, et une date officielle d'abrogation **vérifiée** (source citée,
  attestation de l'administrateur : `inami_repeal_source`, `inami_repeal_verified_at`, migration
  `Version20261104090000`) **prime toujours sur le statut administratif `active`** :
  - abrogation vérifiée : code utilisable seulement pour une intervention strictement antérieure,
    actif ou non ; refusé le jour de l'abrogation et après, même si `active = true` ; une
    réactivation ne la contourne jamais ;
  - sans abrogation vérifiée : code actif utilisable ; code inactif jamais (aucune période supposée).
  Modifier la date ou la source efface la vérification. Règle unique (`Nomenclature::isUsableOn()`
  et `isKnownRepealedOn()`) appliquée par le serveur sur toutes les routes de création et de
  changement d'acte, avec la date de l'intervention, et par les requêtes de sélection.
- Acte inchangé : code historique préservé ; une nouvelle date dans la période abrogée (vérifiée)
  est refusée (422 sur `date`, ancienne route 400 `reason: nomenclature_repealed_on_date`), jamais de
  remplacement silencieux. Date inchangée : toujours modifiable. Abrogation non vérifiée : aucune
  restriction de date.
- Liste de choix `GET /api/nomenclature/{speciality}?date=AAAA-MM-JJ` : codes utilisables à cette
  date, avec `active` et `inamiRepealedAt` (abrogation vérifiée, code actif ou non) ; sans date
  (favoris, anciens fronts) : codes actifs non abrogés aujourd'hui.
- Favoris : `historicalUntil` (abrogation vérifiée, code actif ou non) ; un favori reste visible et
  signalé (« inactif » ou « abrogé ») et n'est sélectionnable que pour une date conforme ; un favori
  (sans date) ne peut être créé ou redirigé que vers un code actif et non abrogé aujourd'hui.

Conséquences : aucune désactivation réelle avant validation de la liste officielle ; questions
fonctionnelles restantes dans `docs/RECETTE-NOMENCLATURE-ETAPE4.md`.

## ADR-211 — Actes partagés entre spécialités (rachis : orthopédie et neurochirurgie)
**Statut : Proposée (03/11/2026)** — complète ADR-007 et ADR-210.

Contexte : l'article 14 n) de la nomenclature INAMI (« prestations relevant de la spécialité de
chirurgie orthopédique (DP) et neurochirurgie (DA) », 130 lignes MedClick, 260 codes officiels) est
classé en `ortho` dans MedClick : un assistant en neurochirurgie ne les trouve pas.

Décision :
- **Un code INAMI = une seule ligne de nomenclature.** La spécialité principale
  (`nomenclature.speciality`), le type et la région ne changent pas. Deux notions **séparées**, deux
  tables (une ligne par code et par spécialité, contrainte unique, migrations additives
  `Version20261105090000` et `Version20261106090000`) :
  - **autorisation de saisie** (`nomenclature_shared_speciality`) : le code est proposé et accepté dans
    cette spécialité pour une nouvelle intervention ou un favori ;
  - **classement historique** (`nomenclature_historical_speciality`) : une intervention déjà enregistrée
    sous cette spécialité figure à son récapitulatif ; aucun droit de saisie.
- Alimentation par plan vérifié uniquement : `app:nomenclature:share-speciality <plan>` (dry-run par
  défaut, `--apply` en une transaction, idempotent, `--revert` ; canal Monolog `nomenclature`), listes
  `share`, `withdraw` et `classify`.
  Plan `data/inami/plan-partage-rachis-14n-20261101.json` produit par
  `tools/inami/make_plan_shared.py`, d'après NomenSoft (version en vigueur au 01/11/2026), jamais par
  recherche dans les libellés :
  - **130 lignes classées** : codes hospitalier ET ambulant du sous-article `14n` ;
  - **56 lignes autorisées en neurochirurgie** (décision du 03/11/2026) : paire officielle réciproque,
    catégories et valeur exactes ;
  - **74 lignes NON accessibles en neurochirurgie**, provisoirement, tant que leur référence n'est pas
    corrigée : les 72 lignes portant un code hospitalier d'un autre acte (les 69 dites inversées, 6872,
    6950, 6968), plus 6871 et 6957. Elles restent en orthopédie, inchangées.
- Listes de choix `GET /api/nomenclature/{speciality}` (avec ou sans `?date=`) : codes de la
  spécialité et codes autorisés pour elle, chacun une fois ; règles de validité ADR-210 inchangées. Le
  classement historique n'ouvre aucune sélection.
- Spécialité de l'intervention : celle **choisie** (`speciality` envoyé avec l'acte), vérifiée par
  le serveur (`SurgeryNomenclatureSelection::specialityFor()`) ; absente, vide ou « favorites »
  (anciens fronts) : spécialité principale, comme avant. Spécialité non autorisée pour ce code :
  400 `reason: speciality_not_allowed` (contrôleurs custom) ou 422 sur `speciality` (API Platform).
  Le code, la nomenclature liée et l'intitulé restent ceux de l'unique ligne.
  Routes : `/api/surgeries/addNewSurgery`, `/api/surgeries/add`, `POST /api/surgeries`, changement
  d'acte (`PUT`/`PATCH /api/surgeries/{id}`, `PUT /api/surgeries/update/{id}`), favoris
  (`/api/favorites/addNew`, `/api/favorites/updateNew`).
- Favoris : `POST`, `PUT` et `PATCH` bruts d'API Platform sont **fermés** (405) : ils acceptaient un
  code et une spécialité quelconques, et ni la PWA ni l'ancien front ne les utilisent. Lectures et
  `DELETE` inchangés.
- **Historique** : acte inchangé ⇒ spécialité enregistrée conservée, même si une autre spécialité est
  envoyée ; aucune intervention existante n'est modifiée. Favori renommé avec le même acte : la
  spécialité ne change que vers une spécialité autorisée explicitement envoyée (valeur non autorisée
  ignorée).
- Statistiques, listes et export Excel utilisent la spécialité **enregistrée** dans chaque
  intervention. Export : une intervention enregistrée sous une spécialité autorisée OU classée pour son
  code est listée avec les interventions de cette spécialité (la région anatomique, propre à
  l'orthopédie, n'est pas appliquée) ; les interventions orthopédiques restent dans leur région.
- **Changement attendu dans les exports (décision du 03/11/2026)** : les interventions historiques
  déjà enregistrées en `neuro` sur un code 14 n) étaient absentes du récapitulatif. Elles y
  apparaissent, par le classement historique, que leur code soit autorisé à la saisie ou non : en
  production, 43 interventions, 21 lignes supplémentaires (utilisateurs des années 225 : 1, 357 : 6,
  437 : 7, 616 : 7), sans modification de ces interventions. Le récapitulatif regroupant toutes les
  années d'un utilisateur, le changement concerne chaque export de ces quatre utilisateurs. Carnet et
  autres feuilles inchangés. Les interventions `neuro` sur un code de région rachis hors 14 n) restent
  traitées comme avant.

Conséquences : retirer une autorisation ou un classement ne modifie aucune intervention. Hors
périmètre : doublon 226542, inversions orthopédiques, anciens libellés.
Restriction provisoire : les 72 lignes au code hospitalier erroné restent saisissables en orthopédie
comme aujourd'hui, mais ne sont pas accessibles en neurochirurgie. Leur correction est la priorité
suivant la 3.7.0 ; chaque ligne corrigée pourra alors être autorisée par plan.

## ADR-212 — Correction des références de l'article 14 n) sans toucher à l'historique
**Statut : Proposée (03/11/2026)** — complète ADR-007, ADR-210 et ADR-211.

Contexte : 74 lignes 14 n) portent des codes qui ne forment pas la paire officielle. Le code hospitalier
y désigne en général un autre acte (décalage systématique, pas une inversion des colonnes).

Décision :
- **Une intervention et un favori gardent le code copié à l'enregistrement.** Corriger la nomenclature ne
  réécrit ni `Surgeries.code`, `name`, `speciality`, le lien, les dates, rôles et superviseurs, ni le favori.
  Carnet, récapitulatif et statistiques lisent la copie de l'intervention ; la nomenclature n'y fournit que
  le type, la région et les spécialités, qui ne changent pas.
- `app:nomenclature:correct-references <plan>` (dry-run par défaut, `--apply` en une transaction,
  idempotent, `--revert`, journal avant/après sur le canal `nomenclature`). Seules les colonnes
  `code_hospitalisation` et `code_ambulant` changent. Tout le plan est refusé si une ligne n'est ni dans
  l'état « avant » ni « après », si sa spécialité, sa valeur ou son libellé diffère, ou si un code se
  retrouverait sur plusieurs lignes sans être listé dans `knownDuplicates`.
- Plan `data/inami/plan-correction-references-14n-20261101.json`, produit par
  `tools/inami/make_plan_correction.py` : acte officiel unique par libellé et valeur exacts, paire
  réciproque, chaque code dans sa colonne. **72 lignes corrigées** : les 69 lignes décalées, 6872, 6968, et
  6871 (dépendance : 6872 prend 224431, jusque-là en colonne hospitalisation de 6871).
- **2 lignes désactivées administrativement** (décision du 03/11/2026), doublons d'un acte porté par une autre
  ligne : 6950 (même acte que 6951) et 6957 (même acte que 6956). Ce ne sont **pas des abrogations officielles** :
  `active` passe à false, les champs `inami_repeal*` restent vides, la justification est dans
  `deactivation_reason` (migration additive `Version20261107090000`), effacée à la réactivation.
  `app:nomenclature:deactivate-duplicates <plan>` : dry-run par défaut, idempotent, `--revert` exact, refus si la
  ligne porte des données d'abrogation, si elle est partagée, ou si la ligne conservée n'a pas le même acte actif
  et ses codes corrigés. Aucune suppression, fusion ni recodage. Sans abrogation vérifiée, ces lignes ne sont
  proposées à aucune date ; leurs interventions restent consultables, modifiables sans recodage et exportées.
  Leurs codes restent en base : 225632 et 226494 sont sur deux lignes (`knownDuplicates`), en plus de 226542
  (6957 et 6961), mais une seule ligne active porte chacun d'eux.
- Partage : `data/inami/plan-partage-rachis-14n-20261101-2-corriges.json` ajoute les 72 lignes corrigées
  (128 lignes autorisées au total) ; le classement historique de la 3.7.0 n'est pas modifié.
- Favoris : le code copié n'est ni affiché par la PWA ni utilisé pour une saisie (`surgeryId`) ; une saisie
  depuis un favori prend donc le code corrigé. 84 favoris gardent un ancien code copié, renvoyé tel quel
  par l'API ; aucune réécriture silencieuse.
- `app:update-favorites` ne relie plus que les favoris sans nomenclature.

Conséquences :
- Comptages attendus : 1 797 codes, 1 347 actifs, 450 inactifs (448 abrogations officielles et 2 désactivations
  administratives).
- Le plan de partage de la 3.7.0 est refusé après correction (ses codes ne concordent plus). Retour arrière, dans
  cet ordre : `--revert` du plan de désactivation, du plan de partage 2, puis du plan de correction.
- Restent à traiter séparément (`docs/NOMENCLATURE-REFERENCES-14N.md`) : actes officiels sans ligne
  (225621/225610 et 225901/225890), affichage du code dans le récapitulatif.

## ADR-213 — Récapitulatif Excel : une ligne par code réellement enregistré
**Statut : Proposée (03/11/2026)** — complète ADR-007, ADR-211 et ADR-212.

Contexte : la liste récapitulative regroupait les interventions par lien de nomenclature et affichait le code et le
libellé de la première. Plusieurs codes ou actes enregistrés pouvaient donc être additionnés sous un seul.

Décision :
- Dans un acte, une ligne par `Surgeries.code`, `Surgeries.speciality` et `Surgeries.name` : l'intervention affiche
  ce qui a été enregistré. Le libellé fait partie de la clé, car un même code peut porter des actes différents dans
  l'historique. Espaces, casse et apostrophes typographiques ne distinguent pas deux lignes.
- **Les sections ne changent pas** : type et région restent ceux de la nomenclature, et un acte reste dans la section
  de sa première intervention. Faire dépendre la section de chaque intervention aurait fait sortir du récapitulatif
  les interventions enregistrées sous une valeur sans section (`favorites`, `ortho` en chirurgie générale), en aurait
  fait entrer d'autres et en aurait déplacé : les sous-totaux auraient changé.
- Les lignes d'un acte restent voisines, dans l'ordre historique des actes ; un utilisateur sans ligne à séparer
  obtient le même fichier.

Conséquences :
- Sous-totaux et totaux inchangés ; aucune donnée modifiée, aucune migration. Les autres feuilles ne changent pas.
- Deux lignes peuvent sembler identiques si seule la spécialité enregistrée les distingue (donnée non affichée).
- Les écarts H1, H10 et H11 (`docs/AUDIT-TOTAUX-EXCEL.md`) restent à décider séparément.
- Références `cas-limites` et `audit-sans-nomenclature` remplacées : seules les lignes de leur récapitulatif changent.
