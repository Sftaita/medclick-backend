# MedClick 3.8.0 — Cookies, stockage et conditions d'utilisation

**Statut : proposition pour validation juridique, non validée.** Rien dans ce document ne vaut avis
juridique. Les textes des § 5 et § 6 sont à relire par une personne compétente avant publication ;
les questions à trancher sont listées au § 8.

Cadre de référence à vérifier : loi du 13 juin 2005 relative aux communications électroniques,
art. 10/2 (stocker ou lire une information dans le terminal : consentement préalable, sauf
transmission d'une communication ou stockage strictement nécessaire à un service expressément
demandé par l'utilisateur) ; RGPD (information, art. 13 ; minimisation, art. 5) ; recommandations
de l'Autorité de protection des données (APD) et lignes directrices 2/2023 de l'EDPB sur le
champ technique de l'art. 5(3) de la directive « vie privée et communications électroniques ».

## 1. Branches concernées

| Branche (PWA) | Contenu | Statut |
|---|---|---|
| `fix/inami-cookies-consentement` (`8b1884d`) | spécialité mémorisée seulement sur « Mémoriser » ; `/cookies` 1.1 | **dans la release 3.8.0** (`8638ce5`), testée |
| `fix/publicite-sans-identifiant-persistant` (`25f20ae`, depuis `8638ce5`) | § 3 et § 4 : plus d'identifiant publicitaire persistant ; fréquence du plein écran minimale ; `/cookies` 1.2 | **proposée, hors release**, à intégrer sur décision |

## 2. Inventaire (relevé du code, 07/11/2026)

| Nom | Type | Durée | Finalité | Déposé sans action de l'utilisateur ? | Production actuelle | Release 3.8.0 | Avec `25f20ae` |
|---|---|---|---|---|---|---|---|
| `mc_inami_pro` | cookie de session | fermeture du navigateur | ne pas redemander la déclaration « professionnel de la santé » | non (après « Oui ») | — | oui | oui |
| `mc_inami_specialite` | cookie | 12 mois | retrouver la spécialité | non (case « Mémoriser ») | — | oui | oui |
| `authToken`, `acceptedTerms` | stockage local | déconnexion | session, acceptation des conditions | non (connexion) | oui | oui | oui |
| `sessionExpired`, `medclick:versionRecovery` | stockage de session | onglet | message « session expirée » ; un seul rechargement après une mise à jour | oui | oui | oui | oui |
| `mc-interstitial-seen` | stockage local | entrées de 7 jours, purgées à la connexion suivante | fréquence de la publicité plein écran (§ 4) | oui (compte connecté) | oui | oui | **minimisé** |
| `mc-ad-visitor` | stockage local | **sans limite** | dédoublonnage de la mesure publicitaire (§ 3) | **oui, visiteur non connecté compris** | oui | oui | **supprimé** |

## 3. `mc-ad-visitor`

**Finalité exacte.** Ne pas compter deux fois une impression (publicité vue à 50 % pendant 1 s) ou
un clic du même visiteur : page de connexion (bannière publique), tableau de bord, encarts
d'entreprise. Il ne sert ni au ciblage, ni au profilage, ni à la facturation individuelle.

**Fonctionnement actuel (production et release 3.8.0).**
- Navigateur : UUID aléatoire créé à la première mesure, écrit dans le stockage local sans date
  d'expiration et réutilisé à chaque visite (`frontend/src/features/ads/sponsors.js`). La recherche
  INAMI ne l'utilise pas (identifiant en mémoire le temps de la page).
- Envoi : `POST /api/marketing/events` et `/api/partner/events` (`{ campaign | partner, placement, type, visitor }`).
- Serveur : si l'utilisateur est connecté, l'identifiant reçu est **ignoré** et remplacé par
  `u:<id du compte>` ; sinon `a:<identifiant>`. Il n'est jamais stocké tel quel : empreinte
  HMAC-SHA256 avec un secret serveur et **la date du jour** (elle change chaque jour), effacée
  après 24 h ; doublon = même empreinte, même campagne, même emplacement, même type sur **30 minutes**
  (`SponsorDelivery::record`, `PartnerEvents`). Aucune IP, aucun agent utilisateur, aucune URL
  n'est enregistré avec l'événement. Statistiques = comptages agrégés par jour.

**Données collectées.** Côté navigateur : un identifiant aléatoire. Côté serveur : campagne,
emplacement, type, date-heure, empreinte quotidienne (24 h). Pour un compte connecté, l'empreinte
porte sur l'identifiant du compte : donnée pseudonyme pendant 24 h.

**Nécessaire ?** Probablement **non** au sens de l'art. 10/2 : la mesure d'audience publicitaire
n'est pas le service demandé par l'utilisateur. Surtout, la conservation sans limite est
**disproportionnée** au besoin réel (30 minutes), et inutile pour un compte connecté (ignoré par le
serveur).

**Adaptations possibles.**

| Option | Effet sur la vie privée | Effet sur les statistiques |
|---|---|---|
| A. Identifiant en mémoire de l'onglet, jamais écrit (**retenue, `25f20ae`**) ; clé existante effacée au démarrage | plus aucun stockage ni lecture dans le terminal pour la mesure ; rien qui survive à la fermeture de l'onglet | doublons ignorés dans un même onglet (navigation incluse) ; un rechargement ou un nouvel onglet dans les 30 min compte un nouvel affichage. Écart attendu faible, surtout sur la page de connexion |
| B. Stockage de session (onglet) | stockage dans le terminal : même question de consentement | identique à A, plus le rechargement |
| C. Empreinte serveur IP + navigateur | traitement de l'IP et lecture d'informations du terminal (empreinte) : à éviter | dédoublonnage multi-onglets |
| D. Consentement (bandeau) | conforme si consentement valable | nécessite un bandeau à créer ; statistiques partielles sans consentement |

Les comptages publiés dans l'administration restent des impressions et clics dédoublonnés par jour ;
aucune modification du serveur ni migration n'est nécessaire (le format de l'identifiant est
inchangé).

## 4. `mc-interstitial-seen`

**Finalité exacte.** Ne pas remontrer trop tôt la publicité plein écran affichée **après la
connexion** (compte connecté uniquement), selon la fréquence réglée dans l'administration :
à chaque connexion, une fois par jour, une fois par semaine (`frontend/src/features/ads/campaign.js`).

**Fonctionnement actuel.** Après chaque affichage, le navigateur écrit `{ "<id de campagne>": <date> }`
dans le stockage local, quelle que soit la fréquence ; les entrées de plus de 7 jours sont retirées
lors de l'écriture suivante. La valeur est lue seulement par le navigateur pour décider d'afficher
ou non la publicité ; **elle n'est jamais envoyée au serveur**. Aucun identifiant de personne.

**Données collectées.** Numéros de campagne et dates d'affichage, dans le navigateur seulement.

**Nécessaire ?** À trancher : ce n'est pas indispensable au carnet de stage, mais cela **limite**
l'exposition publicitaire de l'utilisateur, dans un service gratuit financé par la publicité et
dont il a accepté les conditions. Sans ce stockage, la publicité serait montrée à chaque connexion.

**Adaptations possibles.**

| Option | Effet |
|---|---|
| A. Minimiser (**retenue, `25f20ae`**) : rien n'est écrit pour « à chaque connexion » ; entrées de plus de 7 jours effacées dès le démarrage ; clé retirée quand elle est vide | stockage limité au cas où il protège l'utilisateur d'une répétition, 7 jours au plus |
| B. Fréquence tenue par le serveur, par compte (aucun stockage dans le terminal) | nécessite une table, une migration et une modification de `/api/marketing/active` : évolution à part entière, hors 3.8.0 ; traitement de données liées au compte, à couvrir par les conditions |
| C. Supprimer la fréquence (« à chaque connexion » pour toutes les campagnes) | aucun stockage ; publicité plus fréquente pour l'utilisateur |

Recommandation technique : A pour la 3.8.0 si le juriste l'admet ; B ensuite si un consentement
est jugé nécessaire.

## 5. Texte proposé pour la page `/cookies` (version 1.2, avec `25f20ae`)

Texte intégré au code de la branche `fix/publicite-sans-identifiant-persistant` ; la version 1.1
de la release diffère seulement par la ligne `mc-ad-visitor` (identifiant persistant) et la
mention « le temps de la page ».

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
> aléatoire est gardé en mémoire tant que l'onglet reste ouvert, puis oublié. Les visites et les
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
> | `mc-interstitial-seen` | Stockage local | 7 jours au plus | Compte connecté : numéro et date de la publicité plein écran vue après la connexion, seulement si elle est limitée à une fois par jour ou par semaine, pour ne pas la remontrer trop tôt. Rien n'est envoyé au serveur. |
>
> Version 1.2 du 7 novembre 2026.

## 6. Texte proposé pour les conditions d'utilisation

Remplace le premier alinéa du paragraphe « 3. Cookies et sécurité » publié le 17/12/2024 ;
l'alinéa sur la sécurité est conservé. Publication depuis l'administration (nouvelle ligne de
`terms_conditions`), après validation. Version écrite pour la page 1.2 (avec `25f20ae`).

> **3. Cookies, stockage local et sécurité**
>
> MED-CLICK n'enregistre dans le navigateur de l'Utilisateur que les informations nécessaires au
> fonctionnement du service ou demandées par l'Utilisateur, sans service de publicité ou de mesure
> tiers :
>
> - pour la recherche publique des codes INAMI, un cookie de session retenant que l'Utilisateur a
>   déclaré être professionnel de la santé (`mc_inami_pro`, effacé à la fermeture du navigateur) et,
>   uniquement si l'Utilisateur le demande en cochant « Mémoriser », un cookie retenant la spécialité
>   choisie (`mc_inami_specialite`, 12 mois) ;
> - pour l'application, le jeton de session et l'acceptation des présentes conditions (effacés à la
>   déconnexion), ainsi que des informations techniques limitées à l'onglet ouvert ;
> - pour un Utilisateur connecté, la date d'affichage de la publicité plein écran présentée après la
>   connexion, lorsqu'elle est limitée à une fois par jour ou par semaine (7 jours au plus), afin de
>   ne pas la lui présenter plus souvent ; cette information n'est pas transmise à MED-CLICK.
>
> Les affichages et clics des publicités sont comptés sans cookie ni stockage dans le navigateur,
> à l'aide d'un identifiant aléatoire oublié à la fermeture de l'onglet. MED-CLICK n'enregistre
> pour cette mesure ni adresse IP ni recherche effectuée ; les statistiques sont agrégées par jour.
>
> La liste à jour, avec la durée et le rôle de chaque élément, est publiée sur la page « Cookies »
> (https://www.medclick.be/cookies). L'Utilisateur peut à tout moment supprimer ces informations
> depuis les réglages de son navigateur ; le service peut alors lui redemander sa spécialité ou une
> nouvelle connexion.

Sans `25f20ae`, l'alinéa sur la mesure publicitaire est faux pour la page de connexion et le
tableau de bord (identifiant persistant) : ce texte ne doit être publié qu'avec cette correction,
ou adapté.

## 7. Nouvelle acceptation des conditions ?

Constat technique : l'acceptation est un simple booléen par utilisateur (`user.accepted_terms`,
`terms_accepted_date`) ; la version publiée (`terms_conditions.published_at`) n'est comparée à rien.
**Publier une nouvelle version n'impose aucune nouvelle acceptation**, et la 3.8.0 n'en ajoute pas.

Analyse proposée (à confirmer) : la modification complète l'information sur des traitements
existants, en réduit certains et n'ajoute aucune obligation pour l'Utilisateur. Une information
datée (mention de la nouvelle version, éventuellement un message à la prochaine connexion) paraît
suffire. Une nouvelle acceptation exigerait un développement (version acceptée par utilisateur),
hors 3.8.0.

## 8. Questions pour la validation juridique

1. `mc_inami_pro` (session, après la déclaration « professionnel de la santé ») : exempté de
   consentement comme strictement nécessaire au contenu demandé ?
2. `mc_inami_specialite` : la case « Mémoriser », décochée par défaut, vaut-elle demande expresse
   ou consentement valable ? La durée de **12 mois** est-elle acceptable ?
3. Mesure publicitaire avec un identifiant **en mémoire de l'onglet** (`25f20ae`) : confirmer
   qu'aucun consentement n'est requis (aucun stockage ni lecture dans le terminal au-delà de
   l'envoi de la mesure, pas d'IP conservée).
4. `mc-interstitial-seen` minimisé (§ 4, option A) : admissible sans consentement pour un compte
   connecté ayant accepté les conditions, ou option B (serveur) nécessaire ?
5. Absence de bandeau, si les points 1 à 4 sont admis.
6. Textes des § 5 et § 6, et besoin d'une nouvelle acceptation (§ 7).
7. Adresse de contact RGPD des conditions publiées : `medclick.info@gmail.be` (domaine `gmail.be`)
   paraît erronée ; adresse correcte à fournir.
8. Texte « c) Cookies et sécurité » de la fenêtre d'inscription (`ConditionsDialog.jsx`), distinct
   et non modifié : à aligner sur la version validée.
9. Période de transition : la production actuelle a déjà déposé `mc-ad-visitor` chez des
   visiteurs ; `25f20ae` l'efface à leur prochaine visite. Une information particulière est-elle
   nécessaire ?

## 9. Historique de la page `/cookies`

| Version | Date | Changement |
|---|---|---|
| 1.0 | 06/11/2026 | deux cookies de la recherche INAMI (staging uniquement) |
| 1.1 | 07/11/2026 | spécialité mémorisée sur choix explicite ; suppression ; éléments enregistrés ailleurs par MedClick (release 3.8.0) |
| 1.2 | 07/11/2026 | plus d'identifiant publicitaire persistant ; fréquence du plein écran minimale (`25f20ae`, proposée) |
