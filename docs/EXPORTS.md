# Exports Excel (carnet de stage)

Génération asynchrone du carnet de stage officiel, avec progression réelle, historique permanent,
surveillance et alertes. Le fichier produit est **identique à l'octet** à celui de l'ancien moteur
(`/api/excel2`, commit `b659514`) ; voir « Équivalence » ci-dessous.

## Cause des dépassements de délai (corrigée)

L'ancien moteur insérait une ligne Excel **par intervention** (carnet de stage) et **par code**
(liste récapitulative) avec `insertNewRowBefore()`. Chaque insertion réécrit toute la feuille et
réanalyse toutes ses formules (1 042 dans la liste récapitulative, ~0,15 s par insertion) : coût
quadratique. S'y ajoutaient le chargement du gabarit (~3 s, 1 190 formules, 665 fusions), une
requête par code de nomenclature (N+1) et ~2 700 recherches de style (empreinte MD5 de chaque style
du classeur). Le PHP web s'arrête à 30 s : le profil le plus lourd (~1 350 interventions sur
l'année, 3 650 au total, 371 codes) prenait 82 s dans l'image de production.

Le nouveau moteur (`src/Export/CarnetDeStage/`) garde le même algorithme instruction par
instruction, mais insère les lignes d'une section **en un appel** (même résultat : chaque ligne
insérée reprend le style de la ligne modèle), charge les données en un nombre fixe de requêtes,
met le gabarit analysé en cache (signé, `cache:warmup`) et calcule une fois chaque style centré.

| Jeu (fictif) | Ancien moteur | Nouveau moteur | Mémoire max (nouveau) |
|---|---|---|---|
| 100 interventions | 9,2 s | 1,5 s | ~84 Mo |
| 1 000 | 53,7 s | 6,1 s | ~83 Mo |
| profil réel (1 344 / 3 646, 371 codes) | 127,7 s (82,3 s image prod) | 10,8 s (7,0 s worker) | ~84 Mo |
| 10 000 | 3 068 s (51 min) | 33,9 s (17,3 s worker) | ~150 Mo |

Poste de développement (Windows) sauf « image prod » / « worker » : image Docker de production sur
MariaDB 11.8, worker limité à 1 CPU.

## Architecture

```
Front ── POST /api/exports {yearId} ──► API (FrankenPHP)
          │                               ├─ export_job (statut « pending ») + évènement
          │                               └─ message GenerateExport → messenger_messages (Doctrine)
          │                                                 │
          │                     worker (même image, conteneur dédié, 1 CPU / 768 Mo)
          │                     messenger:consume exports scheduler_exports_monitor
          │                       ├─ GenerateExportHandler : génère var/exports/<uuid>.xlsx,
          │                       │  écrit étape + avancement + signe de vie (≤ 0,5 s)
          │                       └─ ExportMonitor chaque minute (Symfony Scheduler)
          ├─ GET /api/exports/{id}  (toutes les 1 s, puis 2 s) ◄── progression réelle
          ├─ GET /api/exports/{id}/download  (propriétaire uniquement, flux par blocs)
          └─ POST /api/exports/{id}/confirm {size}  (réception complète confirmée)
```

Choix : Symfony Messenger avec le transport Doctrine (la base existante, aucun service à ajouter),
un seul worker (une génération à la fois : ressources bornées sur le VPS partagé), surveillance
planifiée exécutée par ce worker (aucune tâche cron sur l'hôte). Pas de SSE : l'interrogation
périodique suffit (une requête légère par seconde et par export en cours) et traverse Traefik sans
réglage.

### API utilisateur (`App\Controller\ExportController`)

| Route | Rôle |
|---|---|
| `POST /api/exports` `{yearId}` | 202 créé ; 200 si un export identique est déjà en cours (double clic, deux onglets) ; 403 année d'un autre ; 429 au-delà de 30 demandes/h |
| `GET /api/exports?yearId=` | exports des dernières 24 h (reprise après rafraîchissement) |
| `GET /api/exports/{id}` | état, étape, `processed`/`total`/`percent` (si total réel), position dans la file, étapes faites et leur durée |
| `POST /api/exports/{id}/cancel` | immédiat en attente ; sinon arrêt à la prochaine progression |
| `GET /api/exports/{id}/download` | fichier (410 expiré, 409 indisponible) |
| `POST /api/exports/{id}/confirm` `{size}` | statut « téléchargé » si la taille reçue est exacte |

Un export d'un autre utilisateur répond **404**, y compris à un administrateur.

### Statuts et étapes

`pending` → `running` → `ready` → `downloaded` (confirmé) ; ou `failed`, `cancelled`, `expired`
(fichier supprimé sans téléchargement confirmé). Étapes (`App\Export\ExportStep`) : préparation,
récupération des interventions, chargement du modèle, **traitement des interventions (n / N)**,
**liste récapitulative (lignes écrites / lignes)**, autres feuilles, finalisation. Seules les deux
étapes à total réel ont un pourcentage ; les autres affichent une animation et leur nom.

### Téléchargement : observé ou confirmé

- `firstServedAt` / évènement `download_served` : tous les octets écrits sans coupure détectée
  côté serveur (ne prouve pas la réception).
- `firstDownloadedAt` / `download_confirmed` / statut `downloaded` : le navigateur a reçu le
  fichier entier (taille vérifiée).
- `download_interrupted`, `download_refused`, `download_size_mismatch`, `not_downloaded`.

### Échecs détectés (catégorie, `App\Export\ExportFailure`)

| Catégorie | Détection |
|---|---|
| `database` | exception DBAL/ORM au chargement (code avec SQLSTATE) |
| `timeout` | > `EXPORT_TIMEOUT_SECONDS` : signal SIGALRM (pcntl) et contrôle à chaque progression ; surveillance si le worker est figé |
| `memory` | erreur fatale « Allowed memory size » enregistrée par la fonction d'arrêt du worker |
| `xlsx_generation` | exception PhpSpreadsheet (gabarit, construction, calcul des formules) |
| `file_write` | dossier absent/non inscriptible, écriture ou renommage impossible (aucun fichier partiel) |
| `worker_interrupted` | aucun signe de vie depuis `EXPORT_STALL_SECONDS`, ou message redélivré après un arrêt brutal |
| `worker_unavailable` | jamais pris en charge après `EXPORT_PENDING_MAX_SECONDS` |
| `not_found`, `access_denied` | données supprimées entre-temps (pas d'alerte) |

La détection des exports bloqués tourne chaque minute dans le worker **et** à chaque consultation
de l'état ou de l'administration : un worker arrêté ne laisse jamais un export « en cours ».
Les changements de statut sont des `UPDATE … WHERE status IN (…)` : API, worker et surveillance ne
peuvent pas s'écraser.

### Alertes (`App\Export\Job\ExportAlerter`)

Destinataire `EXPORT_ALERT_EMAIL` (par défaut `ERROR_ALERT_EMAIL`). E-mail texte : utilisateur
(id, e-mail), type, dates, étape, durée avant l'échec, catégorie, code technique, identifiant de
diagnostic (`EXP-XXXXXXXX`), corrélation des journaux, lien `…/admin/exports?id=…` (connexion
administrateur requise). Jamais de pièce jointe ni de donnée médicale.

- Anti-doublon : un incident (type + catégorie + étape + code) n'est signalé qu'une fois par
  `EXPORT_ALERT_DEDUP_MINUTES` ; les suivants sont marqués « doublon de EXP-… ».
- Échec d'envoi : statut `failed`, erreur et tentatives conservées ; nouvelle tentative par la
  surveillance (5, 15, 60, 240 min ; 5 au plus) ou bouton « Renvoyer l'alerte » de l'administration.
- Non téléchargés : évènement seul ; récapitulatif e-mail (au plus un par 24 h) seulement si
  `EXPORT_NOT_DOWNLOADED_ALERT_THRESHOLD` > 0 et atteint.

### Administration

`GET /api/admin/exports` (compteurs, filtres statut/type/utilisateur/période, 50 par page),
`GET /api/admin/exports/{id}` (étapes, évènements, erreur, alerte — consultation journalisée dans
l'historique de l'export), `POST /api/admin/exports/{id}/alert/retry`. Page « Surveillance des
exports » du front (`/admin/exports`). Aucun accès aux fichiers.

## Confidentialité et conservation

- `export_job` / `export_job_event` : aucune donnée médicale (type, année demandée, compteurs, dates,
  codes). Jamais le message brut d'une exception (il peut contenir des valeurs) : classe,
  emplacement dans le code, SQLSTATE, pile d'appels **sans arguments** dans les journaux.
- Fichiers : `var/exports/<uuid>.xlsx` (volume Docker partagé API/worker, hors racine web),
  supprimés après `EXPORT_FILE_TTL_MINUTES` ; fichiers orphelins ou partiels supprimés après 1 h.
- Historique : supprimé après `EXPORT_HISTORY_RETENTION_DAYS` (365 j par défaut).
- Sauvegardes : les nouvelles tables sont dans la base MariaDB, donc dans `backup.sh` (dump
  complet) ; les fichiers temporaires ne sont volontairement pas sauvegardés.
- Les anciennes routes synchrones (`/api/excel2`, `/api/excel`) sont aussi historisées
  (`parameters.channel = "sync"`), sans fichier conservé.

## Réglages (`.env` du serveur, défauts dans `config/services.yaml`)

| Variable | Défaut | Rôle |
|---|---|---|
| `MESSENGER_TRANSPORT_DSN` | `doctrine://default?auto_setup=0` | file des exports |
| `EXPORT_STORAGE_DIR` | `%kernel.project_dir%/var/exports` | dossier des fichiers |
| `EXPORT_FILE_TTL_MINUTES` | 120 | durée de vie d'un fichier |
| `EXPORT_TIMEOUT_SECONDS` | 300 | durée maximale d'une génération |
| `EXPORT_STALL_SECONDS` | 120 | sans signe de vie → interrompu |
| `EXPORT_PENDING_MAX_SECONDS` | 900 | jamais pris en charge → échec |
| `EXPORT_NOT_DOWNLOADED_AFTER_MINUTES` | 30 | signalement « non téléchargé » |
| `EXPORT_NOT_DOWNLOADED_ALERT_THRESHOLD` | 0 | récapitulatif e-mail (0 = jamais) |
| `EXPORT_ALERT_EMAIL` | `ERROR_ALERT_EMAIL` | destinataire des alertes |
| `EXPORT_ALERT_DEDUP_MINUTES` | 60 | période anti-doublon |
| `EXPORT_HISTORY_RETENTION_DAYS` | 365 | conservation de l'historique |
| `EXPORT_MEMORY_LIMIT` | 512M | limite PHP pendant une génération |

## Exploitation

- Worker : service `worker` des Compose VPS (`deploy/vps/`), démarré par `deploy.sh` après le
  contrôle des migrations. `docker compose logs worker` ; arrêt propre (`docker compose stop worker`)
  : la génération en cours se termine (120 s de grâce).
- Surveillance à la demande : `php bin/console app:exports:monitor`.
- File : `php bin/console messenger:stats`.
- Développement local sans worker : `php bin/console messenger:consume exports scheduler_exports_monitor -vv`.

## Équivalence du fichier Excel (tests)

- Oracle : copie figée de l'ancien moteur (`tests/Excel/Legacy/`), seuls namespace, nom de classe
  et route changent.
- Références produites **avant** la refactorisation : `tests/Fixtures/excel-reference/` (12 scénarios
  fictifs) et `var/excel-reference/` (volumes, `--group excel-reference-generation`).
- Comparateur `tests/Excel/XlsxComparator.php` : (1) parties du paquet identiques à l'octet ou après
  canonisation XML (seules les dates de `docProps/core.xml` tolérées), (2) sémantique : feuilles,
  chaque cellule (valeur, type, formule, style résolu), fusions, dimensions, mise en page et
  impression, volets, protections, validations, mises en forme conditionnelles, filtres, liens,
  commentaires, dessins, noms définis, propriétés. `php tests/Excel/bin/compare-xlsx.php a.xlsx b.xlsx`.
- `ExcelEquivalenceTest` : ancien vs nouveau moteur (12 scénarios + 6 tirages aléatoires), nouveau
  vs références ; `--group excel-volume` pour 100 à 10 000 interventions.
- Remarque : PhpSpreadsheet nomme l'image du gabarit d'après son chemin ; un fichier produit sous
  Windows et un autre dans le conteneur diffèrent donc par ce seul nom interne (contenu identique).
  Les comparaisons se font toujours dans le même environnement.
