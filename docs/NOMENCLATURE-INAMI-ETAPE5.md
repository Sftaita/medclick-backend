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

Deux plans séparés, appliqués l'un après l'autre (déploiement contrôlé) :

```bash
P=data/inami/plan-import-inami-20261101
php bin/console app:nomenclature:import-inami $P-1-ajouts.json                 # 2 ajouts : dry-run
php bin/console app:nomenclature:import-inami $P-1-ajouts.json --apply
php bin/console app:nomenclature:import-inami $P-2-desactivations.json         # 448 abrogations : dry-run
php bin/console app:nomenclature:import-inami $P-2-desactivations.json --apply
php bin/console app:nomenclature:import-inami $P-2-desactivations.json --revert [--apply]   # retour arrière
```

Idempotent (deuxième exécution : « déjà présent » / « déjà appliquée »), plan validé en entier avant
toute écriture (identifiant et code doivent concorder), aucune suppression, abrogation différente
déjà saisie jamais écrasée, trace dans `var/log/nomenclature-<env>-AAAA-MM.log`. `--revert` ne
rétablit que les lignes exactement dans l'état importé ; les ajouts ne sont jamais supprimés.

Vérification ciblée des 448 abrogations (`tools/inami/verify_pairs.py`) : pour chaque ligne, tous
les codes officiels de la prestation (deux colonnes MedClick et leurs codes correspondants
ambulant / hospitalisé) sont abrogés à la même date et le libellé correspond : 448 certaines,
0 douteuse (dont 18 lignes neuro portant le code ambulant dans les deux colonnes ; aucune des 69
lignes orthopédiques inversées n'est concernée).

## Interventions déjà datées après l'abrogation (18 808 en production)

Jamais recodées. Leur date reste corrigible, même vers une autre date postérieure à l'abrogation,
avec un avertissement (`inamiRepealedAt` dans le détail d'une intervention ; `warning` de l'ancienne
route). Une intervention datée avant l'abrogation ne peut pas être déplacée après ; toute nouvelle
saisie exige un code valable à la date.

Régénérer les plans : `python tools/inami/analyze.py <dossier TXT> <export TSV nomenclature> analysis.json`,
`python tools/inami/make_plan.py analysis.json TXT.zip plan.json`, puis
`python tools/inami/verify_pairs.py <dossier TXT> analysis.json plan.json <dossier de sortie>`.
