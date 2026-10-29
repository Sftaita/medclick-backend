# Validation de MedClick 3.3.0 — espaces sponsors

Recette du 29/10/2026 avant mise en production. **Version non déployée en production** : fusion et
déploiement soumis à accord.

| | Backend | Frontend |
|---|---|---|
| Branche | `feature/espaces-sponsors` | `feature/espaces-sponsors` |
| PR | Sftaita/medclick-backend#3 | Sftaita/pwa-medclick#3 (privé) |
| Code validé en préproduction | `bb7b38c` | `2299970` (3.3.0) |
| À déployer | `db77087` (= `bb7b38c` + documentation) | `2299970` |
| Base | `master` 93e0b55 (3.2.0 en production) | `main` 4abe9ce (3.2.0 en production) |

## Préproduction (MariaDB 11.8.9, copie de production)

Copie : sauvegarde chiffrée quotidienne de production `medclick-prod-20261029T035001Z` (SHA-256
vérifié), restaurée dans la préproduction par `restore.sh into` ; base de préproduction sauvegardée
juste avant (`backups/staging-avant-3.3.0-20261029-063804.sql.gz`). Aucune écriture en production.

Données marketing de production : 1 campagne (« Test Arthrex », inactive, images collées tronquées
de janvier 2025), 39 vues historiques, aucun visuel importé, 1 partenaire.

| Contrôle | Résultat |
|---|---|
| Empreintes (`deploy/vps/sponsors-verification.sql`) des campagnes, vues, visuels, partenaires | **identiques** avant la migration, après, après le retour arrière, après la remontée et après la recette (données de recette supprimées) |
| Campagne existante après migration | `["login_interstitial"]`, priorité 5, non exclusive ; 39 vues en `legacy_view`, sans empreinte de visiteur |
| Fenêtre de déploiement (nouveau code, schéma non migré) | `/api/marketing/*` en 500, toutes les autres routes en 200 |
| Retour arrière (`migrate prev`) | empreintes identiques ; structure de `marketing` et `marketing_view` identique au dump de production |
| Retour à la 3.2.0 (`./deploy.sh 49e6a0d 86dc29c`) | déploiement complet (code 0), aucune migration en attente, routes en 200 |
| Remontée 3.3.0 complète (`./deploy.sh bb7b38c 2299970`) | code 0 ; assets de la 3.2.0 repris (ancien fichier JS servi : 200) |
| `doctrine:schema:update --dump-sql` | seul écart : index préexistant `wdbi_name_191_031fbf8277a9` sur `nomenclature` (présent avant la 3.2.0, hors périmètre) |
| Recette commerciale via l'API HTTPS | **27/27** : campagne existante intacte et réenregistrable sans modification ; connexion seule ; tableau de bord sans le sponsor de connexion ; logo servi ; rotation pondérée (120 tirages : priorité 10 servie 114 fois, priorité 1 servie 36 fois) ; exclusivité seule sur son emplacement et seconde exclusivité refusée ; impressions et clics dédoublonnés (visiteur anonyme, utilisateur connecté sur deux onglets) ; emplacement non acheté ignoré ; bilan et CSV par annonceur ; historique séparé ; statistiques réservées aux administrateurs |
| Comptage réel en base | 6 événements enregistrés (4 impressions, 2 clics) pour 6 envoyés, malgré plus de 120 lectures de `/slots` |
| Rendu de la connexion (préproduction, 1225 px) | un seul logo (en-tête) ; encart entier sur la photo ; impression envoyée par le navigateur |

## Contrôles complémentaires

| Point | Résultat |
|---|---|
| **Troncature de `GROUP_CONCAT`** | Défaut trouvé et corrigé (`bb7b38c`) : au-delà de 1 Mo (`group_concat_max_len`), l'ancienne empreinte était tronquée sans erreur. Démonstration sur 60 000 lignes : avertissement 1260, modification de la dernière ligne non détectée. Nouvelle empreinte par ligne (MD5 combiné par `BIT_XOR` sur 128 bits, nombre de lignes, octets), sans troncature possible |
| **Doublons au passage de minuit** | Défaut trouvé et corrigé (`14cf816`) : l'empreinte quotidienne du visiteur faisait compter deux fois une impression à 23:50 puis à 00:10. Empreintes du jour et de la veille comparées sur la fenêtre de 30 minutes ; tests avec horloge simulée (23:50 → 00:10 ignoré, 00:21 compté ; connecté 23:59:30 → 00:00:30 ignoré). Le test échoue avec l'ancien comportement (mutation) |
| **Anciens utilisateurs et mise à jour de la PWA** | Simulation locale avec les builds de production 3.2.0 puis 3.3.0 : l'utilisateur 3.2.0 fonctionne avec le nouveau backend ; la 3.3.0 est détectée et mise en attente sans l'interrompre, appliquée au changement d'écran suivant (rechargement unique), précache de 31 entrées, aucune réponse d'API en cache, puis carrousel affiché |
| CI GitHub Actions | verte sur `bb7b38c` (push et pull_request) |
| Suite backend | 335 tests (+2 de minuit) ; frontend : 407 tests, Node 24.22, build et lint OK |

## Risques résiduels

- Fenêtre de déploiement : entre le démarrage du nouveau backend et la migration, `/api/marketing/*`
  répond 500 (publicité absente, aucune autre fonction touchée) ; ces erreurs sont capturées et peuvent
  déclencher une alerte e-mail. Enchaîner la migration aussitôt.
- Retour arrière du backend : supprime les impressions et clics mesurés depuis la mise en production
  (exporter le CSV avant).
- Visuels : un visuel externe inaccessible laisse la zone vide (taille réservée) ; préférer les visuels
  importés. Une bannière seule de 1,5 Mo au plus est chargée aussi sur mobile : fournir la bannière mobile.
- Index `nomenclature` hors mapping (préexistant) : à régulariser séparément.

## Plan de déploiement définitif

Voir `docs/SPONSORS.md` (« Mise en production », « Retour arrière »).

SHA à déployer : les commits de fusion sur `master` et `main`, après accord. Avant le déploiement,
vérifier que leur code est exactement celui validé en préproduction (sortie vide attendue) :
`git diff --stat bb7b38c <sha_master> -- src config migrations public templates composer.lock deploy`
et `git diff --stat 2299970 <sha_main> -- frontend ':!frontend/docs'`. Sans fusion préalable, les
SHA ci-dessous (têtes des branches validées) conviennent.

Dans `/opt/stack/apps/medclick-prod` :

1. `./backup.sh /opt/stack/apps/medclick-prod prod` (contrôle du SHA-256 et de la copie distante).
2. Empreinte « avant » : `sponsors-verification.sql` → `avant.txt`.
3. `./deploy.sh db7708707f9b1ec008af0bf29437df3eb274d621 7b4b4a23f5b461e117f70f220c674bdb02d8b6d4` → s'arrête sur « 1 migration(s) en attente ».
4. Aussitôt : `doctrine:migrations:migrate -n`, `doctrine:schema:update --dump-sql` (seul l'index
   préexistant), empreinte « après » identique, `GET /api/marketing/slots` → 200.
5. Frontend 3.3.0 : archive `229997063931cf63efb7d56564e9c44ac7be8c61` dans `src/pwa` (dossier `frontend` remplacé) + `.commit`, puis
   `./deploy.sh db7708707f9b1ec008af0bf29437df3eb274d621 229997063931cf63efb7d56564e9c44ac7be8c61`.
6. Contrôles : connexion, tableau de bord, administration › Campagnes et Statistiques ; journal
   `docs/VERSIONS.md` du front daté.

Retour arrière : frontend précédent d'abord ; puis export CSV, désactivation des campagnes au nouveau
format, `migrate prev`, `./deploy.sh 3df788f7b7ae27988dcc3c1c76d56feb977508eb 7b4b4a23f5b461e117f70f220c674bdb02d8b6d4`
(3.2.0 actuellement en production) ; en dernier
recours, restauration de la sauvegarde de l'étape 1.
