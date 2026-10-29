# Exports Excel (carnet de stage)

En production depuis MedClick 3.2.0 (28/10/2026). Décisions : `docs/DECISIONS.md` (ADR-201 à
ADR-208).

Un seul générateur du carnet de stage officiel (`src/Export/CarnetDeStage/`), exécuté en
asynchrone avec progression réelle, historique permanent, surveillance et alertes. Le format du
fichier est figé par des fichiers de référence : voir « Non-régression du fichier Excel ».

## API Excel

- **API canonique : `/api/exports`** (`App\Controller\ExportController`, détail ci-dessous) :
  demande, progression, historique, téléchargement. Seul parcours du front (bouton « Excel » par
  année).
- **Alias de compatibilité** (temporaires) : `GET /api/excel2/{year}` et `GET /api/excel/{year}`
  (`App\Controller\CarnetDeStageExcelController`). Même générateur, même fichier, génération
  synchrone ; contrat inchangé : 200 + fichier `.xlsx` (`export.xlsx`), 403 année d'un autre
  utilisateur, 404 année ou utilisateur inconnus, 401 sans authentification. Historisés
  (`parameters.channel = "sync"`), sans fichier conservé. Encore appelés par le front CRA servi
  à `frontend.easymed.fun` et par les onglets ouverts avant la mise à jour ; à retirer quand les
  journaux d'accès ne montrent plus d'appel.

## Performances (cause des dépassements de délai, corrigée en 3.2.0)

Avant 3.2.0, la génération insérait une ligne Excel **par intervention** (carnet de stage) et **par code**
(liste récapitulative) avec `insertNewRowBefore()`. Chaque insertion réécrit toute la feuille et
réanalyse toutes ses formules (1 042 dans la liste récapitulative, ~0,15 s par insertion) : coût
quadratique. S'y ajoutaient le chargement du gabarit (~3 s, 1 190 formules, 665 fusions), une
requête par code de nomenclature (N+1) et ~2 700 recherches de style (empreinte MD5 de chaque style
du classeur). Le PHP web s'arrête à 30 s : le profil le plus lourd (~1 350 interventions sur
l'année, 3 650 au total, 371 codes) prenait 82 s dans l'image de production.

Depuis 3.2.0, le générateur garde le même algorithme instruction par instruction, mais insère les lignes d'une section **en un appel** (même résultat : chaque ligne
insérée reprend le style de la ligne modèle), charge les données en un nombre fixe de requêtes,
met le gabarit analysé en cache (signé, `cache:warmup`) et calcule une fois chaque style centré.

| Jeu (fictif) | Avant 3.2.0 | 3.2.0 | Mémoire max (3.2.0) |
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
planifiée exécutée par ce worker et par un gardien indépendant (une ligne de crontab sur l'hôte,
voir « Gardien indépendant du worker »). Pas de SSE : l'interrogation
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

La détection des exports bloqués tourne chaque minute dans le worker, dans le gardien (même si le
worker est arrêté) **et** à chaque consultation de l'état ou de l'administration : un worker
arrêté ne laisse jamais un export « en cours ».
Les changements de statut sont des `UPDATE … WHERE status IN (…)` : API, worker et surveillance ne
peuvent pas s'écraser.

### Alertes (`App\Export\Job\ExportAlerter`)

Destinataire `EXPORT_ALERT_EMAIL` (par défaut `ERROR_ALERT_EMAIL`). E-mail texte : utilisateur
(id, e-mail), type, dates, étape, durée avant l'échec, catégorie, code technique, identifiant de
diagnostic (`EXP-XXXXXXXX`), corrélation des journaux, lien `…/admin/exports?id=…` (connexion
administrateur requise). Jamais de pièce jointe ni de donnée médicale.

- Anti-doublon : un incident (type + catégorie + étape + code) n'est signalé qu'une fois par
  `EXPORT_ALERT_DEDUP_MINUTES` ; les suivants sont marqués « doublon de EXP-… ». Pour une panne du
  worker (`worker_interrupted`, `worker_unavailable`), l'étape en cours est fortuite et n'entre pas
  dans l'empreinte : un worker qui plante en boucle n'envoie qu'un e-mail par période.
- Échec d'envoi : statut `failed`, erreur et tentatives conservées ; nouvelle tentative par la
  surveillance (5, 15, 60, 240 min ; 5 au plus) ou bouton « Renvoyer l'alerte » de l'administration.
- Non téléchargés : évènement seul ; récapitulatif e-mail (au plus un par 24 h) seulement si
  `EXPORT_NOT_DOWNLOADED_ALERT_THRESHOLD` > 0 et atteint.

### Gardien indépendant du worker (`App\Export\Monitoring\ExportWorkerWatch`)

La surveillance tourne chaque minute dans le worker ; si le worker est arrêté (à la main, crash non
relancé), elle s'arrête avec lui. Un **gardien** planifié sur le serveur (cron de l'hôte, chaque
minute, `app:exports:monitor --watchdog` exécuté dans le conteneur de l'API) prend le relais, sans
dépendre d'aucune consultation :

- même surveillance que le worker (exports bloqués, jamais pris en charge, expirations, alertes à
  renvoyer…), une seule à la fois (bail en base, table `export_monitor_state`) ;
- présence du worker : la surveillance planifiée du worker enregistre un signe de vie chaque
  minute ; sans signe de vie depuis `EXPORT_WORKER_ABSENT_SECONDS` (180 s) **et** sans export en
  cours qui avance (génération longue), le worker est déclaré absent ;
- une absence = un incident = **un e-mail** « Worker des exports Excel arrêté » (renvoyé en cas
  d'échec d'envoi : 5, 15, 60, 240 min). Pas de second e-mail si un export bloqué par cet arrêt a
  déjà été signalé, et les exports qui échouent ensuite (`worker_unavailable`) sont marqués
  « incident déjà signalé ». Retour du worker : incident clos (journal), un nouvel arrêt est
  signalé de nouveau ;
- administration : état du worker (actif, arrêté depuis…, jamais vu) et du gardien (actif s'il est
  passé dans les 5 dernières minutes : un gardien absent de la crontab se voit) ;
- surveillance croisée : le worker vérifie à son tour le gardien. Un gardien déjà vu qui ne passe
  plus depuis 10 minutes (ligne retirée de la crontab, cron arrêté…) = **un e-mail** « Gardien des
  exports Excel inactif » (renvoyé en cas d'échec d'envoi), jusqu'à son retour ; un nouvel arrêt
  est signalé de nouveau. Un gardien jamais installé n'est pas signalé par e-mail (`deploy.sh`
  l'avertit, l'administration l'affiche).

Installation : `docs/DEPLOIEMENT-VPS.md` (« Worker des exports Excel ») ; `deploy.sh` avertit si la
ligne manque dans la crontab.

### Administration

`GET /api/admin/exports` (état du worker et du gardien, compteurs, filtres statut/type/utilisateur/période, 50 par page),
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
- Les alias synchrones (`/api/excel2`, `/api/excel`) sont aussi historisés, sans fichier conservé.

## Réglages (`.env` du serveur, défauts dans `config/services.yaml`)

| Variable | Défaut | Rôle |
|---|---|---|
| `MESSENGER_TRANSPORT_DSN` | `doctrine://default?auto_setup=0` | file des exports |
| `EXPORT_STORAGE_DIR` | `%kernel.project_dir%/var/exports` | dossier des fichiers |
| `EXPORT_FILE_TTL_MINUTES` | 120 | durée de vie d'un fichier |
| `EXPORT_TIMEOUT_SECONDS` | 300 | durée maximale d'une génération |
| `EXPORT_STALL_SECONDS` | 120 | sans signe de vie → interrompu |
| `EXPORT_WORKER_ABSENT_SECONDS` | 180 | worker sans signe de vie → absent (gardien) |
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
- Surveillance à la demande : `php bin/console app:exports:monitor` ; gardien (cron, chaque
  minute) : `app:exports:monitor --watchdog` (n'affiche rien quand tout va bien, `-v` pour le détail).
- File : `php bin/console messenger:stats`.
- Développement local sans worker : `php bin/console messenger:consume exports scheduler_exports_monitor -vv`.

## Non-régression du fichier Excel (tests)

- Références figées (format officiel du carnet, validé en préproduction ; données fictives) :
  `tests/Fixtures/excel-reference/` (30 cas : 12 scénarios, 6 tirages aléatoires, 12 cas de l'audit
  des totaux ; `manifest.json` : empreinte SHA-256, date d'édition, origine) et
  `var/excel-reference/` (volumes de 100 à 10 000 interventions, non versionnés). Une référence ne
  se régénère pas : une différence est une régression, ou un changement de format décidé
  explicitement (nouvelles références, nouvelle validation).
- Comparateur `tests/Excel/XlsxComparator.php` : (1) parties du paquet identiques à l'octet ou après
  canonisation XML (seules les dates de `docProps/core.xml` tolérées), (2) sémantique : feuilles,
  chaque cellule (valeur, type, formule, style résolu), fusions, dimensions, mise en page et
  impression, volets, protections, validations, mises en forme conditionnelles, filtres, liens,
  commentaires, dessins, noms définis, propriétés. `php tests/Excel/bin/compare-xlsx.php a.xlsx b.xlsx`.
- Images (`xl/media`) : PhpSpreadsheet les nomme `md5(chemin du gabarit)`, chemin absolu du projet
  compris. Les références ayant été produites dans `C:\wamp64\www\medclick-backend-export`, la
  comparaison échouait dans tout autre dossier (autre poste, CI, conteneur : CI rouge depuis la
  fusion 3.2.0, `ExportLifecycleTest::testSuccessfulExportFromRequestToConfirmedDownload` et les cas
  de `ExcelReferenceTest`). Le comparateur renomme désormais ces images d'après leur contenu (et
  leurs références dans les `.rels`) : le contenu reste comparé à l'octet ; le fichier produit, lui,
  n'a jamais été en cause (nom interne seulement).
- `ExcelReferenceTest` : le fichier produit est comparé à la référence de chacun des 30 cas ;
  `--group excel-volume` pour les volumes. Les alias synchrones servent le même fichier
  (`tests/Export/ExportLifecycleTest.php`).
- Remarque : PhpSpreadsheet nomme l'image du gabarit d'après son chemin ; un fichier produit sous
  Windows et un autre dans le conteneur diffèrent donc par ce seul nom interne (contenu identique).
  Les comparaisons se font toujours dans le même environnement.
