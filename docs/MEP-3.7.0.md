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
2. `./deploy.sh --migrate <B> <F>` : deux migrations attendues, `Version20261105090000` et
   `Version20261106090000` (création de `nomenclature_shared_speciality` et
   `nomenclature_historical_speciality`, additives, compatibles avec la version en service).
3. Contrôle de la version en service, puis `./deploy.sh <B> <F>`.
4. Vérification finale du script : backend et worker sur `<B>`, PWA sur `<F>`, aucune migration en attente.

La PWA 3.7.0 fonctionne aussi avec l'ancien backend (champ ignoré) ; une PWA en cache sans `speciality`
enregistre l'acte dans sa spécialité principale.

## 4. Plan de partage
`data/inami/plan-partage-rachis-14n-20261101.json` : `share` 56, `withdraw` 74, `classify` 130.
**Les 74 lignes de `withdraw` (dont 72 au code hospitalier erroné) ne sont pas accessibles en
neurochirurgie** tant que leur référence n'est pas corrigée.
1. `php bin/console app:nomenclature:share-speciality <plan>` : **à partager 56, sans autorisation 74,
   classement à ajouter 130**.
2. Même commande avec `--apply` : **partagé 56, classement ajouté 130, autorisation retirée 0**.
3. Seconde exécution avec `--apply` : **déjà autorisé 56, déjà sans autorisation 74, déjà classé 130**.

## 5. Contrôles
- Nomenclature : 1 797 codes, 1 349 actifs, 448 inactifs ; empreintes des lignes existantes inchangées.
- `nomenclature_shared_speciality` : 56 lignes `neuro` ; aucune pour les 74 lignes de `withdraw`.
- `nomenclature_historical_speciality` : 130 lignes `neuro`.
- Interventions et favoris existants : aucune ligne disparue ni modifiée (nouvelles lignes des
  utilisateurs possibles).
- `/api/nomenclature/neuro` : actes neuro + 56 lignes ; `/api/nomenclature/ortho` et les autres
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
Migration autre que celles attendues, simulation différente des chiffres ci-dessus, ligne existante modifiée, écart
d'export inexpliqué, erreur 5xx : arrêt, aucune correction improvisée.

## 7. Retour arrière
| Situation | Action |
|---|---|
| Partage à retirer | `share-speciality <plan> --apply --revert` (autorisations et classements). Les interventions déjà enregistrées en `neuro` gardent leur spécialité. |
| Code | `./deploy.sh 9878a07330f350f8a83c31c16cfa0ce2daa94a14 6ae3b34e3ff35764527ad0daf8205c9a70282d74` (l'ancien code ignore la table). |
| Tables | `doctrine:migrations:execute --down` de `Version20261106090000` puis `Version20261105090000`, seulement après le retrait du partage. |
| Dernier recours | Restauration de `backups/prod-avant-3.7.0-$TS.sql.gz`, décision explicite, perte des écritures postérieures. |

Après retour arrière, les interventions en `neuro` sur un code 14 n) restent valides, mais sortent du
récapitulatif tant que le classement n'est pas rétabli.
