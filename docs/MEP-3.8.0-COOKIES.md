# MedClick 3.8.0 — Cookies, stockage et conditions d'utilisation

**Statut : proposition, non validée juridiquement.** Rien dans ce document ne vaut avis juridique.
Les textes ci-dessous sont à relire par une personne compétente avant publication des conditions
d'utilisation ; les points à trancher sont listés au § 5.

Cadre de référence à vérifier : loi du 13 juin 2005 relative aux communications électroniques,
art. 10/2 (dépôt ou lecture d'informations dans le terminal : consentement, sauf stockage
strictement nécessaire à un service expressément demandé) ; RGPD (information, art. 13) ;
recommandations de l'Autorité de protection des données (APD).

## 1. Ce que la 3.8.0 change (frontend, `fix/inami-cookies-consentement`)

| Avant (staging 3.8.0, jamais en production) | Après |
|---|---|
| `mc_inami_specialite` (12 mois) déposé dès le choix d'une spécialité | Déposé **seulement si l'utilisateur coche « Mémoriser 12 mois »** à côté de la spécialité ; effacé dès qu'il décoche. Sans case cochée : spécialité gardée en mémoire le temps de la visite (aucun stockage) |
| Mention « Retenue 12 mois sur cet appareil » | Case « Mémoriser 12 mois · En savoir plus » (décochée par défaut) |
| `/cookies` : deux cookies de la recherche | `/cookies` version 1.1 (datée) : les deux cookies, leur suppression, et tous les éléments enregistrés ailleurs par MedClick (§ 2) |
| `mc_inami_pro` : cookie de session | Inchangé |

La durée de 12 mois est une constante unique (`REMEMBER_MONTHS`, `frontend/src/features/inami/lib/storage.js`) :
la page, la case et le cookie suivent toute modification.

## 2. Inventaire de ce que MedClick enregistre dans le navigateur (relevé du code, 07/11/2026)

| Nom | Type | Durée | Finalité | Déposé sans action de l'utilisateur ? | En production avant 3.8.0 |
|---|---|---|---|---|---|
| `mc_inami_pro` | cookie de session | fermeture du navigateur | ne pas redemander la déclaration « professionnel de la santé » | non (après « Oui ») | non |
| `mc_inami_specialite` | cookie | 12 mois | retrouver la spécialité | non (case « Mémoriser ») | non |
| `authToken` | stockage local | déconnexion | session (jeton) | non (connexion) | oui |
| `acceptedTerms` | stockage local | déconnexion | acceptation des conditions | non (connexion) | oui |
| `sessionExpired` | stockage de session | onglet | message « session expirée » | oui | oui |
| `medclick:versionRecovery` | stockage de session | onglet | un seul rechargement après mise à jour | oui (en cas d'erreur de chargement) | oui |
| `mc-interstitial-seen` | stockage local | entrées de 7 jours | fréquence de la publicité plein écran après connexion | oui (utilisateur connecté) | oui |
| `mc-ad-visitor` | stockage local | sans limite | identifiant aléatoire pour ne pas compter deux fois un affichage ou un clic (page de connexion, tableau de bord, encarts partenaires) | **oui, y compris pour un visiteur non connecté (page de connexion)** | **oui** |

Côté serveur : aucune adresse IP ni terme de recherche n'est enregistré pour la publicité ou la
recherche INAMI. L'identifiant du visiteur est transformé en empreinte HMAC qui change chaque jour
et est effacée après 24 h (`SponsorDelivery::record`) ; pour un utilisateur connecté, l'empreinte
est calculée sur son identifiant de compte (donnée pseudonyme pendant 24 h). Les statistiques de la
recherche INAMI sont des compteurs quotidiens (`inami_search_stat` : jour, type, spécialité, nombre).
La page `/codes-inami` mesure sa bannière avec un identifiant gardé en mémoire le temps de la page,
jamais écrit dans le navigateur.

## 3. Texte proposé pour les conditions d'utilisation

Remplace le paragraphe « 3. Cookies et sécurité » publié le 17/12/2024 (premier alinéa ; l'alinéa
sur la sécurité est conservé tel quel). Publication depuis l'administration (nouvelle ligne de
`terms_conditions`).

> **3. Cookies, stockage local et sécurité**
>
> MED-CLICK enregistre dans le navigateur de l'Utilisateur uniquement les informations nécessaires
> au fonctionnement du service et à la mesure de sa publicité, sans service de publicité ou de
> mesure tiers et sans aucune donnée permettant d'identifier l'Utilisateur :
>
> - pour la recherche publique des codes INAMI, un cookie de session retenant que l'Utilisateur a
>   déclaré être professionnel de la santé (`mc_inami_pro`, effacé à la fermeture du navigateur) et,
>   uniquement si l'Utilisateur le demande en cochant « Mémoriser », un cookie retenant la spécialité
>   choisie (`mc_inami_specialite`, 12 mois) ;
> - pour l'application, le jeton de session et l'acceptation des présentes conditions (effacés à la
>   déconnexion), ainsi que des informations techniques de la session de navigation ;
> - pour la publicité, la date d'affichage de la publicité plein écran présentée après la connexion
>   (fréquence d'affichage) et un identifiant aléatoire, sans lien avec l'identité de l'Utilisateur,
>   servant uniquement à ne pas compter deux fois un même affichage ou clic.
>
> La liste à jour, avec la durée et le rôle de chaque élément, est publiée sur la page « Cookies »
> (https://www.medclick.be/cookies). L'Utilisateur peut à tout moment supprimer ces informations
> depuis les réglages de son navigateur ; le service peut alors lui redemander sa spécialité ou une
> nouvelle connexion.

Le dernier point de la liste dépend de la décision du § 5, point 3 : si `mc-ad-visitor` est soumis
au consentement ou supprimé, le texte doit être adapté.

## 4. Nouvelle acceptation des conditions ?

Constat technique : l'acceptation est un simple booléen par utilisateur (`user.accepted_terms`,
`terms_accepted_date`) ; la version publiée (`terms_conditions.published_at`) n'est comparée à rien.
**Publier une nouvelle version n'impose donc aucune nouvelle acceptation**, et la 3.8.0 n'en ajoute pas.

Analyse proposée (à confirmer) : la modification complète l'information sur des traitements
existants ou facultatifs, sans créer d'obligation nouvelle pour l'Utilisateur. Une information
(mention datée de la nouvelle version, éventuellement un message à la prochaine connexion)
paraît suffire ; une nouvelle acceptation exigerait un développement (version acceptée par
utilisateur) et n'est pas prévue. Si le juriste conclut qu'elle est nécessaire, c'est une évolution
à part entière, hors 3.8.0.

## 5. Points à faire valider juridiquement

1. **`mc_inami_pro` exempté de consentement ?** Cookie de session déposé après la déclaration
   « professionnel de la santé » (contenu réservé). Hypothèse : strictement nécessaire au service
   demandé.
2. **`mc_inami_specialite` : la case « Mémoriser » vaut-elle demande expresse ou consentement
   valable ?** Et la **durée de 12 mois** est-elle acceptable, ou faut-il la réduire ?
3. **`mc-ad-visitor` (déjà en production, hors recherche INAMI)** : identifiant persistant déposé
   pour la mesure publicitaire, y compris pour un visiteur non connecté de la page de connexion,
   sans consentement. Ce n'est probablement pas strictement nécessaire au service. Options
   techniques, non appliquées : (a) identifiant en mémoire le temps de la page, comme sur la
   recherche INAMI (`EPHEMERAL_PLACEMENTS`, `frontend/src/features/ads/sponsors.js`) — la page
   de connexion rechargée compterait alors un nouvel affichage ; (b) dépôt après consentement
   (bandeau à créer) ; (c) maintien si le juriste l'admet.
4. **`mc-interstitial-seen`** (déjà en production) : fréquence d'une publicité après connexion,
   pour un utilisateur connecté qui a accepté les conditions. Couvert par les conditions, ou
   consentement distinct ?
5. **Absence de bandeau** : confirmer qu'aucun bandeau n'est requis si les points 1 à 4 sont
   exemptés (ou que la case « Mémoriser » suffit pour le point 2).
6. **Texte du § 3** et **besoin d'une nouvelle acceptation** (§ 4).
7. **Adresse de contact RGPD des conditions publiées** : `medclick.info@gmail.be` (domaine
   `gmail.be`) paraît erronée ; à corriger dans la nouvelle version.
8. **Texte « c) Cookies et sécurité » de la fenêtre d'inscription** (`ConditionsDialog.jsx`) :
   texte historique distinct, non modifié ; à aligner sur la version validée.

## 6. Historique de la page `/cookies`

| Version | Date | Changement |
|---|---|---|
| 1.0 | 06/11/2026 | deux cookies de la recherche INAMI (staging uniquement) |
| 1.1 | 07/11/2026 | spécialité mémorisée sur choix explicite ; suppression ; éléments enregistrés ailleurs par MedClick |
