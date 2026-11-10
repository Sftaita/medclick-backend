# Campagnes e-mail

Première campagne : **« Recherche INAMI »** (`recherche-inami`), annonce de la recherche publique des
codes INAMI (`https://www.medclick.be/codes-inami`). Aucun envoi collectif n'a eu lieu.

## Où

- **Administration** : onglet **Campagnes e-mail** (`/admin/campagnes-email`), puis « Ouvrir la
  campagne » (`/admin/campagnes-email/recherche-inami`) : objet, titre, expéditeur, lien, aperçu
  ordinateur et mobile, e-mail test, statut des envois, sélection des destinataires (compteurs).
  La liste des désinscriptions se trouve sur la première page.
- **Contenu** : `templates/email/campagnes/recherche-inami.html.twig` (+ `.txt.twig`), texte validé,
  repris tel quel. Définition (objet, titre, lien, visuel, texte alternatif) :
  `src/EmailCampaign/EmailCampaignCatalog.php`. Visuel : `data/email-campaigns/recherche-inami.jpg`
  (1 200 × 900, JPEG, 204 Ko, issu de `Visuel.png` de 1,75 Mo, proportions conservées).
- **Envoi** : Symfony Mailer (comme les autres e-mails), transport dédié `campaigns`
  (`config/packages/mailer.yaml`). Pas de second système d'envoi.

## Rendu

- Mise en page de 600 px en tableaux, styles en ligne, une colonne sur smartphone (requête média),
  bouton en VML pour Outlook sous Windows. Le bouton « Rechercher un code INAMI » est un vrai lien
  HTML, indépendant de l'image (l'image renvoie aussi vers la recherche).
- Image **intégrée au message** (pièce « inline », `cid:`), et non chargée depuis un serveur :
  elle s'affiche sans téléchargement externe et ne permet aucun suivi d'ouverture.
- Version texte complète (messageries sans HTML).
- **Aucun pixel de suivi, aucun paramètre de suivi** dans les liens (le lien est exactement
  `https://www.medclick.be/codes-inami`).
- L'aperçu de l'administration utilise le même gabarit ; seuls changent la source de l'image (`data:`)
  et le lien de désinscription (inactif dans l'aperçu).

## Expéditeur

`MedClick <contact@medclick.be>` (réponse à la même adresse). Contrôles **avant** tout envoi
(`src/EmailCampaign/CampaignSender.php`), envoi refusé sinon :

1. l'expéditeur est une adresse `@medclick.be` (jamais une adresse personnelle) ;
2. le transport `CAMPAIGN_MAILER_DSN` est défini (non nul) ;
3. son compte SMTP **est** l'adresse d'expédition (`contact@medclick.be`) : le message part de la
   boîte `contact@` et non de `service@` (expéditeur technique des e-mails de service, inchangé).

DNS de `medclick.be` vérifiés le 10/11/2026 : SPF `include:_spf.mail.hostinger.com`, DKIM publié
(Hostinger), DMARC `p=none`, MX Hostinger.

Configuration (jamais dans le dépôt) : `.env.local` en local, `.env` du VPS en production :

```
CAMPAIGN_MAILER_DSN=smtp://contact%40medclick.be:<mot de passe encodé URL>@smtp.hostinger.com:465
```

## Destinataires (sélection, aucun envoi)

Calculée à chaque consultation (`src/EmailCampaign/EmailCampaignAudience.php`), l'administration ne
reçoit que des compteurs :

- **éligibles** : compte activé (adresse confirmée), adresse valide, non désinscrite, pas encore
  servie pour cette campagne ; une adresse comptée une fois ;
- **actifs** : dernière connexion (ou création du compte sans connexion) depuis moins de 12 mois ;
  **anciens** : au-delà ;
- indicateur « dont conditions d'utilisation acceptées », utile à l'analyse juridique.

## Désinscription

- Lien en pied de page, propre à chaque envoi (jeton aléatoire de 48 caractères, **sans l'adresse**) :
  `https://api-medclick.easymed.fun/email/desinscription/<jeton>` (`PUBLIC_API_URL`).
- Ouvrir le lien affiche une page de confirmation (les antivirus des messageries ouvrent les liens ;
  un simple affichage ne désinscrit pas). Le bouton enregistre la désinscription.
- En-têtes `List-Unsubscribe` et `List-Unsubscribe-Post` (RFC 8058) : bouton « Se désabonner » de
  Gmail, Outlook et Apple Mail, désinscription en un clic.
- Enregistrement côté serveur : table `email_unsubscribe` (adresse, origine, campagne, date). Une
  adresse désinscrite ne reçoit plus aucune campagne, ni e-mail test, même si le compte est supprimé
  puis recréé. Les e-mails de service (activation, mot de passe) ne sont pas concernés.
- Administration : recherche, ajout manuel (demande reçue sur contact@), réinscription (uniquement à
  la demande expresse de la personne).
- Routage : `/email/desinscription` ajouté aux règles Traefik (`www.medclick.be` après la bascule B,
  préproduction) et à l'accès de recette ; `api-medclick.easymed.fun` route déjà tout le backend.

## Protection contre les envois multiples

- Un message par destinataire (jamais de copie ni de liste visible).
- Chaque adresse est **réservée en base avant l'envoi** (`email_campaign_send.dedup_key` unique =
  campagne + adresse) : une relance de la commande, ou deux exécutions simultanées, ne peuvent pas
  écrire deux fois à la même adresse.
- Un envoi interrompu (statut « en cours » resté tel quel) n'est jamais renvoyé automatiquement ;
  un envoi en échec seulement avec `--retry-failed`.
- E-mails test : uniquement vers l'adresse de l'administrateur connecté (ou
  `EMAIL_CAMPAIGN_TEST_RECIPIENTS`), 10 par heure et par administrateur.

## Commande

```bash
# E-mail test (même rendu que la campagne)
php bin/console app:email-campaign:send recherche-inami --test=adresse@exemple.be
# Simulation (par défaut) : compteurs, aucun envoi
php bin/console app:email-campaign:send recherche-inami --audience=actifs
# Envoi collectif : refusé tant que EMAIL_CAMPAIGN_BULK_ENABLED ne vaut pas 1
php bin/console app:email-campaign:send recherche-inami --audience=actifs --limit=100 --pause-ms=1500 --confirm
```

## Avant tout envoi collectif (non autorisé à ce jour)

**Condition bloquante : le lien de désinscription doit fonctionner en production.** Tant que cette
branche n'est pas déployée, `/email/desinscription/<jeton>` n'existe pas sur
`api-medclick.easymed.fun` : un e-mail envoyé avant le déploiement (e-mail test compris) contient un
lien qui renvoie une erreur 404. La campagne n'est pas prête pour l'envoi collectif tant qu'un e-mail
test envoyé depuis la production n'a pas permis de se désinscrire réellement (puis de se réinscrire
depuis l'administration).

1. **Base juridique** à valider (avis juridique) :
   - l'e-mail présente un nouveau service de MedClick : c'est une communication à caractère
     promotionnel (Code de droit économique, art. XII.13, et arrêté royal du 4 avril 2003) ;
   - le § 4 des conditions 3.8.0 (« En fournissant son adresse e-mail, l'Utilisateur consent… ») est
     un consentement groupé avec les conditions d'utilisation : il ne répond probablement pas aux
     exigences du RGPD (consentement libre, spécifique, distinct) ;
   - piste à confirmer : l'exception des clients existants (AR 4/4/2003, art. 1er) pour un service
     analogue de MedClick, avec possibilité d'opposition simple, applicable plutôt aux **utilisateurs
     actifs** ; pour les **anciens** utilisateurs (plus de 12 mois sans connexion), l'appui est plus
     fragile ;
   - mentions d'identification de l'expéditeur (dénomination, adresse géographique, numéro
     d'entreprise) à ajouter au pied de page si l'avis l'exige : elles ne figurent pas dans le
     contenu validé et n'ont pas été inventées.
2. Mettre en production le backend (migration `Version20261110090000`) et la PWA de cette branche.
3. Définir `CAMPAIGN_MAILER_DSN` sur le VPS, vérifier l'administration (« configuration vérifiée »),
   envoyer un e-mail test depuis la production, vérifier Gmail, Outlook et smartphone.
4. Vérifier les limites d'envoi de la boîte Hostinger `contact@` et choisir `--limit` / `--pause-ms`
   en conséquence (plusieurs exécutions si nécessaire, sans risque de doublon).
5. Simulation (`--audience=actifs` puis `anciens`) et validation des compteurs.
6. Décision explicite, puis `EMAIL_CAMPAIGN_BULK_ENABLED=1` pour la durée de l'envoi seulement,
   `--confirm`, puis retour à `0`. Suivre le statut des envois et les désinscriptions dans
   l'administration.
