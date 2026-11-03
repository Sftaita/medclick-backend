# Plan de mise en production — correction des références 14 n) (ADR-212)

À exécuter seulement après décision explicite, avec le SHA du commit de fusion `<B>`. Backend et worker uniquement : la
PWA reste sur son commit en service (`<F>`). Une seule migration attendue : `Version20261107090000` (colonne additive
`nomenclature.deactivation_reason`, compatible avec la version en service).
État de départ : 1 797 codes (1 349 actifs, 448 inactifs), 56 autorisations, 130 classements.

## 1. Avant
- CI verte sur `<B>`, `./deploy.sh --verify` de la version en service : OK.
- Dump vérifié `backups/prod-avant-references-14n-$TS.sql.gz`, configuration, images.
- Empreintes des interventions, favoris et nomenclature ; exports par feuille des années de référence (357, 616, 437,
  225, 338, 492, 243) ; autres conteneurs du VPS.

## 2. Déploiement
`./deploy.sh --migrate <B> <F>` (uniquement `Version20261107090000`, sinon arrêt), contrôle de la version en service,
puis `./deploy.sh <B> <F>`.

## 3. Correction
`data/inami/plan-correction-references-14n-20261101.json`
1. `php bin/console app:nomenclature:correct-references <plan>` : **à corriger 72, ignorée 0**.
2. Avec `--apply` : **corrigée 72**.
3. Seconde exécution avec `--apply` : **corrigée 0, ignorée 72**.

## 4. Partage
`data/inami/plan-partage-rachis-14n-20261101-2-corriges.json`
1. `php bin/console app:nomenclature:share-speciality <plan>` : **à partager 72, déjà sans autorisation 2**.
2. Avec `--apply` : **partagé 72**.
3. Seconde exécution avec `--apply` : **déjà autorisé 72, déjà sans autorisation 2**.

## 5. Désactivation des doublons
`data/inami/plan-desactivation-doublons-14n-20261101.json`, après la correction (la commande le vérifie).
1. `php bin/console app:nomenclature:deactivate-duplicates <plan>` : **à désactiver 2, ignorée 0**.
2. Avec `--apply` : **désactivée 2**.
3. Seconde exécution avec `--apply` : **désactivée 0, ignorée 2**.

## 6. Contrôles
- Nomenclature : **1 797 codes, 1 347 actifs, 450 inactifs**, dont 448 abrogations officielles et 2 désactivations
  administratives (6950, 6957 : `deactivation_reason` renseignée, champs `inami_repeal*` vides).
- Seules les colonnes de codes des 72 lignes et le statut des 2 lignes changent.
- Autorisations 128, classements 130. 6950 et 6957 : absentes de toutes les listes, à toute date ; 6951 et 6956 proposées.
- Codes sur plusieurs lignes : uniquement 225632, 226494, 226542.
- Interventions et favoris : aucune ligne disparue ni modifiée (nouvelles saisies possibles).
- Exports : toutes les feuilles identiques, hors nouvelles saisies.
- Statistiques, journaux, `error_log`, autres applications : inchangés.
- Aucune écriture de test en production sans décision explicite.

## 7. Conditions d'arrêt
Migration inattendue, simulation différente, intervention ou favori modifié, écart d'export inexpliqué, erreur 5xx.

## 8. Retour arrière
Dans cet ordre (vérifié sur une copie isolée) :
1. `deactivate-duplicates <plan de désactivation> --apply --revert` : rétablit exactement le statut des 2 lignes.
2. `share-speciality <plan de partage 2> --apply --revert` : retire les 72 autorisations. Les interventions saisies en
   neurochirurgie gardent leur spécialité et restent au récapitulatif par le classement historique.
3. `correct-references <plan de correction> --apply --revert` : rétablit les anciens codes. Les interventions saisies
   entre-temps gardent le code corrigé enregistré.
4. Code : redéployer le commit précédent ; colonne : `doctrine:migrations:execute --down` de `Version20261107090000`.
5. Dernier recours : restauration du dump, décision explicite, perte des écritures postérieures.
