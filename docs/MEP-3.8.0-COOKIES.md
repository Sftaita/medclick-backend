# MedClick 3.8.0 — Cookies, stockage et conditions d'utilisation

**Statut : texte final proposé pour validation juridique, non validé.** Rien dans ce document ne
vaut avis juridique. Aucun élément n'est considéré comme exempté de consentement du seul fait qu'il
ne contient pas de donnée nominative : la qualification de chaque élément est une question posée
au § 9, pas une conclusion. Les textes des § 5 à § 7 ne doivent être publiés qu'après validation.

Cadre de référence à vérifier : loi du 13 juin 2005 relative aux communications électroniques,
art. 10/2 (stocker ou lire une information dans le terminal : consentement préalable, sauf
transmission d'une communication ou stockage strictement nécessaire à un service expressément
demandé par l'utilisateur) ; RGPD (information, art. 13 ; minimisation, art. 5) ; recommandations
de l'Autorité de protection des données (APD) et lignes directrices 2/2023 de l'EDPB sur le
champ technique de l'art. 5(3) de la directive « vie privée et communications électroniques ».

## 1. Version concernée

Release 3.8.0, PWA `release/3.8.0` = `465503d` : `d9ff983` (recherche INAMI, refonte publicitaire)
+ `fix/inami-cookies-consentement` (`8b1884d`) + `fix/publicite-sans-identifiant-persistant`
(`25f20ae`, `166c246`). Page `/cookies` en version 1.2. Aucun changement du backend.

## 2. Inventaire (relevé exhaustif du code de `465503d`, 08/11/2026)

Recherche de `document.cookie`, `localStorage`, `sessionStorage`, IndexedDB et du service worker
dans `frontend/src`. Aucun service tiers (publicité, mesure, police, carte) n'est chargé.

| Nom | Type | Durée | Finalité | Déposé sans action de l'utilisateur ? | Production actuelle | 3.8.0 |
|---|---|---|---|---|---|---|
| `mc_inami_pro` | cookie de session | fermeture du navigateur | ne pas redemander la déclaration « professionnel de la santé » | non (après « Oui ») | — | oui |
| `mc_inami_specialite` | cookie, `Secure`, `SameSite=Lax` | 12 mois | retrouver la spécialité | non (case « Mémoriser », décochée par défaut) | — | oui |
| `authToken` | stockage local | déconnexion | session (jeton) | non (connexion) | oui | oui |
| `acceptedTerms` | stockage local | déconnexion | acceptation des conditions | non (connexion) | oui | oui |
| `sessionExpired` | stockage de session | onglet | message « session expirée » | oui | oui | oui |
| `medclick:versionRecovery` | stockage de session | onglet | un seul rechargement après une mise à jour | oui (erreur de chargement) | oui | oui |
| `mc-interstitial-seen` | stockage local | chaque date effacée à la première ouverture après 7 jours | fréquence de la publicité plein écran (§ 4) | oui (compte connecté, fréquence quotidienne ou hebdomadaire) | oui, toujours écrit | **minimisé** |
| `showColumns`, `showColumnsUsers` | stockage local | jusqu'à suppression des données du site | colonnes affichées (administration seulement) | non (choix de l'administrateur) | oui | oui |
| cache de l'application | Cache Storage (service worker) | remplacé à chaque mise à jour | fichiers de l'application ; aucune réponse d'API | oui | oui | oui |
| `mc-ad-visitor` | stockage local | **sans limite** | dédoublonnage de la mesure publicitaire (§ 3) | **oui, visiteur non connecté compris** | **oui** | **supprimé, effacé au démarrage** |
| `campaign` | stockage local | sans limite | ancienne publicité plein écran en cache | oui | effacé au démarrage | effacé au démarrage |

Côté serveur : aucune adresse IP ni terme de recherche n'est enregistré pour la publicité ou la
recherche INAMI ; statistiques INAMI = compteurs quotidiens (`inami_search_stat` : jour, type,
spécialité, nombre).

## 3. Mesure d'audience publicitaire (`mc-ad-visitor` supprimé)

**Finalité.** Ne pas compter deux fois une impression (publicité vue à 50 % pendant 1 s) ou un clic
du même visiteur : page de connexion, tableau de bord, recherche INAMI, encarts d'entreprise. Ni
ciblage, ni profilage, ni facturation individuelle.

**Avant (production actuelle).** UUID aléatoire écrit dans le stockage local sans date d'expiration,
réutilisé à chaque visite, y compris pour un visiteur non connecté de la page de connexion.

**3.8.0.** UUID aléatoire gardé dans la mémoire de la page (variable JavaScript), jamais écrit dans
le navigateur, perdu au rechargement ou à la fermeture ; l'ancienne clé `mc-ad-visitor` est
effacée à la première ouverture de la 3.8.0. Envoi : `POST /api/marketing/events` et
`/api/partner/events` (`{ campaign | partner, placement, type, visitor }`).

**Serveur (inchangé).** Compte connecté : identifiant reçu ignoré, remplacé par `u:<id du compte>` ;
sinon `a:<identifiant>`. Jamais stocké tel quel : empreinte HMAC-SHA256 (secret serveur + date du
jour), effacée après 24 h ; doublon = même empreinte, campagne, emplacement et type sur 30 minutes.
Pour un compte connecté, l'empreinte porte sur l'identifiant du compte : donnée pseudonyme pendant
24 h. Statistiques = comptages agrégés par jour.

**Effet sur les statistiques.** Un rechargement ou un nouvel onglet dans les 30 minutes compte un
nouvel affichage (visiteur non connecté). Écart attendu faible ; aucune migration.

**Point juridique.** L'envoi de l'événement ne stocke rien dans le terminal ; il reste une
transmission de données liée à la mesure d'audience (et, pour un compte connecté, un traitement
pseudonyme de 24 h). Sa base juridique et l'éventuelle nécessité d'un consentement sont à confirmer
(§ 9, point 4).

## 4. Fréquence de la publicité plein écran (`mc-interstitial-seen`)

**Finalité.** Ne pas remontrer trop tôt la publicité plein écran affichée après la connexion
(compte connecté), selon la fréquence réglée dans l'administration.

**3.8.0.** Rien n'est écrit pour « à chaque connexion » ; pour « une fois par jour » ou « une fois
par semaine », `{ "<numéro de campagne>": <date> }` ; chaque entrée est effacée à la première
ouverture de MedClick après 7 jours, la clé disparaît quand elle est vide. La valeur n'est jamais
envoyée au serveur et ne contient aucun identifiant de personne.

**Point juridique.** Ce stockage n'est pas nécessaire au carnet de stage ; il limite la répétition
de la publicité. Absence d'identifiant ≠ exemption : à qualifier (§ 9, point 5). Alternative si un
consentement est requis : fréquence tenue par le serveur par compte (table + migration, hors 3.8.0)
ou fréquence « à chaque connexion » pour toutes les campagnes (aucun stockage).

## 5. Texte de la page `/cookies` (version 1.2, intégré à `465503d`)

> **Cookies de la recherche INAMI**
>
> La recherche des codes INAMI dépose au plus deux cookies fonctionnels, uniquement si vous
> l'utilisez. Ils ne contiennent aucune donnée personnelle, ne servent à aucun pistage et ne sont
> transmis à aucun tiers. Le second n'est déposé que si vous le demandez.
>
> | Nom | Contenu | Durée | Rôle |
> |---|---|---|---|
> | `mc_inami_pro` | « 1 » | Jusqu'à la fermeture du navigateur | Ne pas redemander si vous êtes professionnel de la santé pendant la même session. |
> | `mc_inami_specialite` | Identifiant de la spécialité choisie (par exemple « ortho ») | 12 mois, seulement si vous cochez « Mémoriser » | Retrouver votre spécialité à la prochaine visite. Sans « Mémoriser », la spécialité n'est gardée que le temps de la visite, sans cookie. |
>
> **Publicité et statistiques** — Les publicités de MedClick, dont la bannière de cette page, sont
> mesurées (affichages et clics) sans cookie ni stockage dans votre navigateur : un identifiant
> aléatoire est gardé en mémoire jusqu'au rechargement ou à la fermeture de la page, puis oublié. Il
> permet seulement de ne pas compter deux fois un même affichage ou clic. Les visites et les
> spécialités consultées sont comptées par jour, sans aucune donnée personnelle et sans enregistrer
> vos recherches. Aucun service de publicité ou de mesure tiers n'est utilisé.
>
> **Supprimer ces cookies** — Décochez « Mémoriser » à côté de la spécialité : le cookie
> `mc_inami_specialite` est aussitôt effacé. Le cookie `mc_inami_pro` disparaît à la fermeture du
> navigateur. Vous pouvez aussi supprimer les cookies et données de medclick.be à tout moment
> depuis les réglages de votre navigateur ; la recherche vous redemandera alors votre spécialité
> et si vous êtes professionnel de la santé.
>
> **Ailleurs dans MedClick** — L'application enregistre aussi les éléments suivants dans votre
> navigateur (pas des cookies). Ils sont effacés avec les données du site.
>
> | Nom | Emplacement | Durée | Rôle |
> |---|---|---|---|
> | `authToken` | Stockage local | Jusqu'à la déconnexion | Garder votre session ouverte (compte connecté). |
> | `acceptedTerms` | Stockage local | Jusqu'à la déconnexion | Retenir que vous avez accepté les conditions d'utilisation (compte connecté). |
> | `sessionExpired` | Stockage de session | Jusqu'à la fermeture de l'onglet | Vous indiquer que votre session a expiré. |
> | `medclick:versionRecovery` | Stockage de session | Jusqu'à la fermeture de l'onglet | Recharger une seule fois l'application après une mise à jour. |
> | `mc-interstitial-seen` | Stockage local | Chaque date est effacée à la première ouverture de MedClick après 7 jours | Compte connecté : numéro et date de la publicité plein écran vue après la connexion, seulement si elle est limitée à une fois par jour ou par semaine, pour ne pas la remontrer trop tôt. Rien n'est envoyé au serveur. |
> | `showColumns, showColumnsUsers` | Stockage local | Jusqu'à la suppression des données du site | Administration uniquement : colonnes affichées dans les tableaux de la nomenclature et des utilisateurs. |
> | Cache de l'application | Cache du navigateur (service worker) | Remplacé à chaque mise à jour | Fichiers de l'application (pages, styles, images) pour un chargement rapide. Aucune donnée de compte ni réponse du serveur n'y est conservée. |
>
> **Anciens identifiants** — Les versions précédentes de MedClick enregistraient dans le stockage
> local, sans limite de durée, un identifiant publicitaire aléatoire (`mc-ad-visitor`) et la
> dernière publicité plein écran (`campaign`). Ils sont effacés automatiquement à la première
> ouverture de cette version et ne sont plus créés.
>
> Version 1.2 du 8 novembre 2026.

Si le juriste demande un retrait du consentement pour la spécialité autre que la case (§ 9,
point 3), la page l'indique déjà : décocher « Mémoriser » efface le cookie immédiatement.
Les mentions « aucune donnée personnelle » et « fonctionnels » sont des qualifications à confirmer
(§ 9, point 9).

## 6. Texte proposé pour les conditions d'utilisation, § 3

Remplace le premier alinéa du « 3. Cookies et sécurité » publié le 17/12/2024 (« L'Application et
son site web utilisent des cookies afin d'améliorer l'expérience de navigation… ») ; l'alinéa sur
la sécurité est conservé. Publication depuis l'administration (nouvelle ligne de
`terms_conditions`), après validation et après la mise en production de la 3.8.0.

> **3. Cookies, stockage dans le navigateur et sécurité**
>
> MED-CLICK n'utilise aucun service de publicité ou de mesure d'audience tiers. Les informations
> enregistrées dans le navigateur de l'Utilisateur sont les suivantes.
>
> *a) Éléments nécessaires au fonctionnement.* Pour l'application : le jeton de session et
> l'acceptation des présentes conditions, effacés à la déconnexion ; des informations techniques
> limitées à l'onglet ouvert (session expirée, rechargement après une mise à jour) ; les fichiers
> de l'application, conservés par le navigateur pour un chargement rapide et remplacés à chaque
> mise à jour ; pour les administrateurs, les colonnes affichées dans les tableaux.
>
> *b) Confirmation professionnelle.* La recherche publique des codes INAMI est réservée aux
> professionnels de la santé. Lorsque l'Utilisateur déclare en être un, un cookie de session
> (`mc_inami_pro`) le retient jusqu'à la fermeture du navigateur.
>
> *c) Mémorisation de la spécialité (facultative).* Uniquement si l'Utilisateur coche « Mémoriser »,
> un cookie (`mc_inami_specialite`) retient la spécialité choisie pendant 12 mois. Sans cette case,
> la spécialité n'est gardée que le temps de la visite, sans cookie. L'Utilisateur peut retirer ce
> choix à tout moment en décochant la case : le cookie est alors effacé immédiatement.
>
> *d) Mesure d'audience publicitaire.* Les affichages et clics des publicités sont comptés sans
> cookie ni stockage dans le navigateur, à l'aide d'un identifiant aléatoire gardé en mémoire
> jusqu'au rechargement ou à la fermeture de la page. MED-CLICK n'enregistre pour cette mesure ni
> adresse IP ni recherche effectuée ; les doublons sont repérés au moyen d'une empreinte
> transformée chaque jour et effacée après 24 heures ; les statistiques sont agrégées par jour.
>
> *e) Fréquence d'affichage des publicités.* Pour un Utilisateur connecté, lorsqu'une publicité
> présentée après la connexion est limitée à une fois par jour ou par semaine, sa date d'affichage
> est enregistrée dans le navigateur afin de ne pas la présenter plus souvent ; chaque date est
> effacée à la première ouverture de MED-CLICK après 7 jours. Cette information n'est pas
> transmise à MED-CLICK.
>
> *f) Anciens identifiants.* Les versions de MED-CLICK antérieures au [date de mise en production]
> enregistraient dans le navigateur, sans limite de durée, un identifiant publicitaire aléatoire.
> Il est effacé automatiquement à la première ouverture de la nouvelle version et n'est plus créé.
>
> *g) Suppression.* La liste à jour, avec la durée et le rôle de chaque élément, est publiée sur la
> page « Cookies » (https://www.medclick.be/cookies). L'Utilisateur peut à tout moment supprimer ces
> informations depuis les réglages de son navigateur ; le service peut alors lui redemander sa
> spécialité, sa qualité de professionnel de la santé ou une nouvelle connexion.

Mentions de l'adresse de contact (articles « droits de l'Utilisateur », « désinscription » et
« contact ») : remplacer `medclick.info@gmail.be` par **[adresse de contact RGPD à confirmer]**
(§ 8). Aucune adresse de remplacement n'est proposée ici.

## 7. Nouvelle acceptation des conditions ?

Constat technique : l'acceptation est un simple booléen par utilisateur (`user.accepted_terms`,
`terms_accepted_date`) ; la version publiée (`terms_conditions.published_at`) n'est comparée à rien.
**Publier une nouvelle version n'impose aucune nouvelle acceptation**, et la 3.8.0 n'en ajoute pas.

Analyse proposée (à confirmer) : la modification complète l'information, réduit des traitements
existants (identifiant persistant supprimé, fréquence minimisée) et n'ajoute ni obligation ni
traitement nouveau à la charge de l'Utilisateur, en dehors de la recherche INAMI publique, dont les
cookies sont décrits sur la page. Une information datée (mention de la nouvelle version, message à
la prochaine connexion) paraît suffire. Une nouvelle acceptation exigerait un développement
(version acceptée par utilisateur, écran d'acceptation), hors 3.8.0. La correction de l'adresse de
contact ne crée pas d'obligation nouvelle.

## 8. Adresse de contact RGPD (vérification du 08/11/2026)

| Où | Adresse |
|---|---|
| Conditions publiées (`terms_conditions` n° 1, 17/12/2024), 3 mentions | `medclick.info@gmail.be` |
| Fenêtre des conditions à l'inscription (`ConditionsDialog.jsx`), 2 mentions | `medclick.info@gmail.be` |
| Page d'accueil (`landing.js`), page d'erreur, encart « devenir partenaire » | `medclick.info@gmail.com` |
| Expéditeur des e-mails du service (backend) | `service@medclick.be` (envoi) |

DNS : `gmail.be` n'a **aucun enregistrement MX ni adresse** ; un message envoyé à
`medclick.info@gmail.be` ne peut pas être délivré. Les demandes d'exercice des droits adressées
depuis les conditions n'ont donc pas pu aboutir. `medclick.be` dispose d'une messagerie (MX
Hostinger). L'adresse correcte (vraisemblablement celle utilisée ailleurs dans l'application,
`medclick.info@gmail.com`, ou une adresse du domaine) **doit être confirmée par MedClick** avant
publication ; `ConditionsDialog.jsx` sera aligné sur la même adresse.

## 9. Points nécessitant un avis juridique

1. **Qualification générale** : quels éléments du § 2 relèvent de l'exception « strictement
   nécessaire à un service expressément demandé », lesquels exigent un consentement ? Aucune
   exemption n'est présumée.
2. **`mc_inami_pro`** : cookie de session déposé après la déclaration « professionnel de la santé »
   (contenu réservé). Strictement nécessaire ? La déclaration elle-même suffit-elle comme contrôle
   d'accès au contenu ?
3. **`mc_inami_specialite`** : la case « Mémoriser », décochée par défaut et accompagnée d'un lien
   « En savoir plus », vaut-elle demande expresse ou consentement valable (libre, spécifique,
   éclairé, univoque) ? Le retrait (décocher) est-il suffisant ? La durée de **12 mois** est-elle
   acceptable, ou faut-il la réduire ?
4. **Mesure d'audience publicitaire** sans stockage (§ 3) : base juridique (intérêt légitime ?) et
   absence de consentement admissibles ? Traitement pseudonyme de 24 h pour un compte connecté.
5. **`mc-interstitial-seen`** minimisé (§ 4) : admissible sans consentement, ou fréquence côté
   serveur / suppression de la fréquence nécessaire ?
6. **Absence de bandeau** : acceptable si les points 1 à 5 sont admis ? Sinon, quelles finalités
   soumettre au consentement ?
7. **Anciens identifiants** : `mc-ad-visitor` a été déposé sans consentement chez des visiteurs de
   la production. Effacement automatique à la première ouverture de la 3.8.0 + mention f) et page
   `/cookies` : information suffisante, ou communication particulière nécessaire ?
8. **Textes des § 5 et § 6** et **nouvelle acceptation** (§ 7).
9. **Formulations** « aucune donnée personnelle », « fonctionnels », « ne servent à aucun pistage »
   (page `/cookies`) : exactes au sens du RGPD ?
10. **Adresse de contact RGPD** (§ 8) à fournir ; faut-il informer que l'adresse publiée était
    inopérante ?
11. **`ConditionsDialog.jsx`** (fenêtre d'inscription, « c) Cookies et sécurité ») : texte
    historique distinct, non modifié dans la 3.8.0 ; à aligner sur la version validée.
12. Hors 3.8.0, relevé à l'occasion : les conditions publiées prévoient le partage de données
    (nom, e-mail, année, spécialité) avec des partenaires à des fins de « marketing ciblé » ; la
    3.8.0 n'en réalise aucun, mais la clause mérite une relecture.

## 10. Historique de la page `/cookies`

| Version | Date | Changement |
|---|---|---|
| 1.0 | 06/11/2026 | deux cookies de la recherche INAMI (staging uniquement) |
| 1.1 | 07/11/2026 | spécialité mémorisée sur choix explicite ; suppression ; éléments enregistrés ailleurs par MedClick |
| 1.2 | 08/11/2026 | plus d'identifiant publicitaire persistant ; fréquence du plein écran minimale ; colonnes de l'administration, cache de l'application et anciens identifiants décrits (release `465503d`) |
