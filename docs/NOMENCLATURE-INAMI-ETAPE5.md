# Nomenclature INAMI, étape 5 : actualisation depuis NomenSoft

Actualisation des codes INAMI des neuf spécialités MedClick à partir des tableaux officiels NomenSoft
(ADR-210). **Staging uniquement tant que la mise en production n'est pas autorisée.**

## Sources officielles

- Page : https://www.inami.fgov.be/fr/nomenclature/tableaux-de-reference — publication applicable au
  **01/11/2026**, créée le **09/10/2026** (vérifiée le 02/11/2026 ; historique :
  …/tableaux-avec-des-donnees-de-base-de-la-nomenclature-historique, dernière mise à jour 30/09/2026).
- Fichiers archivés et empreintes SHA-256 : `data/inami/MANIFESTE-NOMENSOFT-20261101.txt`
  (TXT.zip, XML.zip, mises à jour, schéma Excel 26/11/2025, description technique, tableaux
  synoptiques). Les fichiers eux-mêmes (190 Mo) ne sont pas versionnés.
- Tables utilisées (TXT.zip) : `NOMEN_SUMMARY_EXT` (code, dates de début et de fin, libellé FR,
  article / sous-article, catégorie ambulant / hospitalisé, code correspondant, lettre-clé),
  `NOMEN_CODE_AUTHORITY_LIM` + `NOMEN_AUTHORITY_EXT` + `NOMEN_LEGALCHANGE` (actes « Création » 0 et
  « Suppression » -1, arrêté royal, date de publication, date d'application).

## Règles du rapprochement (`tools/inami/analyze.py`)

- Code valide : début ≤ 02/11/2026 ≤ fin (31/12/2999 = sans fin).
- **Abrogation établie** : date de fin officielle passée **et** acte « Suppression » dont la date
  d'effet est le lendemain de cette date de fin. Date officielle d'abrogation MedClick = ce lendemain
  (premier jour sans validité). Sinon : « à vérifier », jamais désactivé.
- Un code absent des tableaux n'est jamais considéré comme abrogé (« introuvable »).
- Nouveau code : prestation hospitalière (ou neutre) valide d'un sous-article où MedClick a déjà des
  codes, dont **aucun** code de la paire officielle (hospitalisé / ambulant) n'est dans MedClick.
  Spécialité proposée d'après la répartition des codes MedClick existants du même sous-article.
- Import automatique exclu (validation médicale) : sous-article hors article 14, article 14 n)
  (orthopédie **ou** neurochirurgie), libellé identique à un code existant (modalité à préciser),
  classification orthopédique non établie, libellé > 500 caractères.
- Orthopédie : type et région repris d'un code MedClick au libellé quasi identique (similarité ≥ 0,90),
  région de la liste fermée obligatoire ; les autres spécialités n'ont ni type ni région.
- Codes existants : aucun libellé, type, sous-type, code ni identifiant modifié ; seules les
  abrogations établies sont importées (statut, date officielle, source, vérification).

## Import (`app:nomenclature:import-inami`)

```bash
php bin/console app:nomenclature:import-inami data/inami/plan-import-inami-20261101.json             # dry-run
php bin/console app:nomenclature:import-inami data/inami/plan-import-inami-20261101.json --apply     # écriture (transaction)
```

Idempotent (deuxième exécution : « déjà présent » / « déjà appliquée »), plan validé en entier avant
toute écriture (identifiant et code doivent concorder), aucune suppression, abrogation différente
déjà saisie jamais écrasée, trace dans `var/log/nomenclature-<env>-AAAA-MM.log`.

Régénérer le plan : `python tools/inami/analyze.py <dossier TXT> <export TSV nomenclature> analysis.json`
puis `python tools/inami/make_plan.py analysis.json TXT.zip plan.json`.
