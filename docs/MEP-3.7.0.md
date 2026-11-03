# Plan de mise en production — MedClick 3.7.0 (rachis partagé orthopédie / neurochirurgie)

Décision : ADR-211 (`docs/DECISIONS.md`). À exécuter seulement après décision explicite de mise en
production, avec les SHA des deux commits de fusion (`<B>` backend, `<F>` PWA).
Dossier : `/opt/stack/apps/medclick-prod`. Commandes `deploy.sh` lancées avec `</dev/null`.

État de départ attendu : backend `9878a07`, PWA `6ae3b34` ; 1 797 codes (1 349 actifs, 448 inactifs).

## 1. Prérequis
- PR backend et PWA fusionnées (commit de fusion classique), CI verte sur `master`.
- `./deploy.sh --verify 9878a07330f350f8a83c31c16cfa0ce2daa94a14 6ae3b34e3ff35764527ad0daf8205c9a70282d74` : OK.
- Services sains, journaux et `error_log` sans erreur.

## 2. Sauvegardes et références
- Dump vérifié `backups/prod-avant-3.7.0-$TS.sql.gz` (+ `.sha256`), `.env`, `docker-compose.yml`,
  `deploy.sh`, liste des images, copie de `src/pwa`.
- `validation/nomenclature-etape3-fingerprint.sh 3.7.0-1-avant` : interventions, favoris, nomenclature.
- Empreintes par feuille des exports : années 357, 616, 437, 225 (concernées) ; 338, 492, 243 (témoins).
- Lignes du récapitulatif de ces 7 années (code et compteurs).
- Liste des conteneurs des autres applications.

## 3. Déploiement
1. Livrer la PWA : `git archive <F> frontend | ssh <vps> "tar -x -C .../src/pwa"`, puis `echo <F> > src/pwa/.commit`.
2. `./deploy.sh --migrate <B> <F>` : une seule migration attendue, `Version20261105090000` (création de
   `nomenclature_shared_speciality`, additive, compatible avec la version en service).
3. Contrôle de la version en service, puis `./deploy.sh <B> <F>`.
4. Vérification finale du script : backend et worker sur `<B>`, PWA sur `<F>`, aucune migration en attente.

La PWA 3.7.0 fonctionne aussi avec l'ancien backend (champ ignoré) ; une PWA en cache sans `speciality`
enregistre l'acte dans sa spécialité principale.

## 4. Plan de partage
`data/inami/plan-partage-rachis-14n-20261101.json` : 128 lignes (6871 et 6957 exclues).
1. `php bin/console app:nomenclature:share-speciality <plan>` : **à partager 128, déjà autorisé 0**.
2. Même commande avec `--apply` : **partagé 128**.
3. Seconde exécution avec `--apply` : **partagé 0, déjà autorisé 128**.

## 5. Contrôles
- Nomenclature : 1 797 codes, 1 349 actifs, 448 inactifs ; empreintes des lignes existantes inchangées.
- `nomenclature_shared_speciality` : 128 lignes `neuro` ; aucune pour 6871 et 6957.
- Interventions et favoris existants : aucune ligne disparue ni modifiée (nouvelles lignes des
  utilisateurs possibles).
- `/api/nomenclature/neuro` : actes neuro + 128 lignes ; `/api/nomenclature/ortho` et les autres
  spécialités : mêmes lignes qu'avant.
- `POST`, `PUT` et `PATCH /api/favorites` : 405 ; `/api/favorites/addNew`, `updateNew`, `getMyList`,
  `DELETE` : fonctionnent.
- Exports : **seul le récapitulatif des années 357, 616, 437 et 225 change** : 6, 7, 7 et 1 lignes
  ajoutées (21 lignes pour 43 interventions, aucune retirée). Le récapitulatif regroupant toutes les
  années d'un utilisateur, les autres années de ces 4 utilisateurs changent de la même façon. Années
  témoins identiques. Les données de production peuvent avoir évolué : un écart doit être expliqué.
- Santé : services sains, `error_log` et journaux sans erreur, autres applications du VPS inchangées.
- Aucune écriture de test en production sans décision explicite.

## 6. Conditions d'arrêt
Migration autre que celle attendue, simulation différente de 128, ligne existante modifiée, écart
d'export inexpliqué, erreur 5xx : arrêt, aucune correction improvisée.

## 7. Retour arrière
| Situation | Action |
|---|---|
| Partage à retirer | `share-speciality <plan> --apply --revert`. Les interventions déjà enregistrées en `neuro` gardent leur spécialité. |
| Code | `./deploy.sh 9878a07330f350f8a83c31c16cfa0ce2daa94a14 6ae3b34e3ff35764527ad0daf8205c9a70282d74` (l'ancien code ignore la table). |
| Table | `doctrine:migrations:execute --down 'DoctrineMigrations\Version20261105090000'`, seulement après le retrait du partage. Vérifié sur staging. |
| Dernier recours | Restauration de `backups/prod-avant-3.7.0-$TS.sql.gz`, décision explicite, perte des écritures postérieures. |

Après retour arrière, les interventions créées en `neuro` sur un acte partagé restent valides, mais
sortent du récapitulatif tant que le partage n'est pas rétabli.
