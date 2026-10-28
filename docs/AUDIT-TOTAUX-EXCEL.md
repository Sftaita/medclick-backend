# Audit des totaux du carnet de stage Excel (27/10/2026)

**Statut : écarts historiques documentés, AUCUN corrigé.** Le fichier reste identique à l'octet au
format historique du carnet (exigence en vigueur, fichiers de référence). Chaque correction demande une décision : elle
changerait le fichier officiel.

**Décision du 28/10/2026 (ADR-204 de `docs/DECISIONS.md`)** : aucune correction dans la 3.2.0 ;
les écarts H1 à H15 seront analysés et corrigés après sa mise en production, chacun par une
décision explicite (modification de `TotalsAuditTest`, nouvelles références, nouvelle validation).

## Méthode

1. Chaque total du fichier est rattaché à une règle métier (tableau ci-dessous).
2. `tests/Excel/Audit/CarnetTotalsAuditor.php` relit les données source **en SQL brut** (sans le
   code du moteur), recalcule chaque total selon la règle, puis le compare aux valeurs écrites et aux
   formules **calculées** du fichier. Les plages des formules sont contrôlées (début à la première
   ligne, fin au-delà de la dernière) ainsi que les références des totaux généraux.
3. Jeux de données contrôlés : 12 scénarios ciblés (`audit-*`, un cas particulier chacun,
   résultat attendu calculable à la main), les 12 scénarios fonctionnels, des tirages aléatoires,
   jusqu'à 10 000 interventions.
4. `tests/Excel/Audit/TotalsAuditTest.php` fige l'état audité : toute règle respectée doit le rester,
   chaque écart connu doit garder exactement la même ampleur.
5. Prévalence mesurée sur la copie de préproduction, en agrégats uniquement. Les chiffres ne sont
   pas publiés ici (dépôt public) : ils figurent dans le rapport d'audit transmis.

Le modèle de données n'autorise qu'**un** code de nomenclature et **un** aîné par intervention :
aucun double comptage possible par plusieurs codes ou superviseurs (vérifié : la somme des lignes
du récapitulatif = interventions retenues, règle R5). Nombre d'interventions et nombre de codes sont
distincts : le carnet compte des interventions, le récapitulatif une ligne par code.

## Totaux audités et règles

| Règle | Total vérifié | Résultat |
|---|---|---|
| C1 | Carnet : une ligne par intervention de l'année, triée par date | conforme (sauf H9) |
| C2 | Carnet : marques de position (1 → opérateur seul, 2 → assistant + aîné, 3 → opérateur + aîné) | conforme |
| C3 | Carnet : plages des sommes I, J, L, M | conformes (sauf H9) |
| C4 | Carnet : totaux par position | conformes (sauf H9) |
| C5 | Carnet : « Nbre total des interventions » | conforme (sauf H9, H12) |
| C6 | Carnet : pourcentages | conformes (sauf H9, H13) |
| C7 | Décompte par superviseur (assistances, opérateur + aîné, %), y compris > 26 superviseurs | conforme (sauf H9) |
| C8 | Total du décompte (plage et valeur) | conforme (sauf H9) |
| C9 | Assistances attribuées à un superviseur = assistances du candidat | conforme ; des interventions réelles ont un aîné absent des superviseurs de l'année (donnée) |
| R1 | Récapitulatif : une ligne par code et par section, comptes par année | conforme en orthopédie et traumatologie ; écart H1, H10 en chirurgie générale |
| R2 | Récapitulatif : totaux de ligne et % | conformes |
| R3 | Récapitulatif : sous-totaux (plage et valeur) | écarts H3, H4 |
| R4 | Récapitulatif : TOTAL GENERAL de chaque bloc | conformes en orthopédie et traumatologie ; écarts H2, H4 |
| R5 | Récapitulatif : toutes les interventions comptées | écarts H10, H11, H12 |
| K1–K4 | Consultations : dates, totaux mensuels, annuel, par type | écarts H5, H15 |
| G1–G4 | Gardes : liste, totaux mensuels, patients, nombre de gardes | écart H6 |
| A1, A2 | Rapport : consultations, interventions par jour et moment | conformes |
| A3 | Rapport : formations et gardes en demi-journées | écarts H7, H8, H14 |
| A4, A5 | Rapport : totaux mensuels et annuels (plages, 12 mois) | conformes (reportent H7, H8, H14) |
| E1 | Évaluation : un jour par jour de formation, dans la bonne section | conforme |

## Écarts historiques

Prévalence (qualitative, chiffres dans le rapport transmis) : copie de préproduction.

### H1 — Chirurgie générale : interventions rangées sous une autre spécialité ⚠️ majeur
- Règle : un acte de chirurgie vasculaire doit figurer sous « VASCULAIRE ».
- Actuel : le moteur remplit les sections dans l'ordre (DIGESTIVE, GENERAL, UROLOGIE…) avec les
  seules spécialités présentes : dès qu'une spécialité manque, les suivantes glissent d'une section.
  Ex. (`audit-specialites-generales`) : 1 vasculaire, 1 plastique, 1 neurochirurgie → affichées sous
  DIGESTIVE, GENERAL et UROLOGIE ; VASCULAIRE, PLASTIQUE, NEUROLOGIE vides.
- Conséquence : répartition par spécialité fausse dans la liste récapitulative (totaux par section).
- Prévalence : **majorité** des utilisateurs ayant de la chirurgie générale.

### H2 — Chirurgie générale : « TOTAL GENERAL » incomplet ⚠️
- Formules du gabarit (ligne TOTAL GENERAL du bloc, ex. ligne 187 sans colonne ajoutée) :
  - année 1 `E187 = SUM(E158,E162,E170,E174,E178,E182,E186)` : **omet UROLOGIE (E166)** ;
  - année 2 `F187` : omet UROLOGIE et NEUROLOGIE ;
  - années 4, 6 et 7 (`H187`, `J187`, `K187`) : omettent THORACIQUE ;
  - les colonnes Opérateur sont complètes.
- Ex. (`petit`) : assistances 1re année = 4 affiché, 7 attendu.
- Prévalence : fréquente (tout utilisateur ayant de l'urologie, de la neurochirurgie ou de la
  chirurgie thoracique en assistance ; aggravée par H1, qui déplace les données entre sections).

### H3 — Chirurgie générale : sous-totaux des années 6 et 7 ⚠️
- Formules du gabarit, dans chaque section du bloc : `J = SUM(H:I)` et `K = SUM(I:J)` sur la ligne
  « Sous total » (somme horizontale des sous-totaux des années 4 et 5) au lieu de `SUM(J16:J17)`.
- Ex. (`audit-annees-6-7`, une aide digestive par année) : année 6 = 2 (attendu 1), année 7 = 3
  (attendu 1) ; le total assistant de la section (somme des années) est gonflé d'autant.
- Prévalence : fréquente (chirurgie générale en assistance en années 4 à 7).

### H4 — Plus de 7 années de formation : sous-totaux absents
- Les colonnes d'années ajoutées (8, 9…) décalent les formules : dans chaque section, deux
  colonnes d'années n'ont plus de sous-total (cellules vides), et les TOTAL GENERAL de ces colonnes
  sont vides (`audit-neuf-annees` : années 7 et 8).
- Prévalence : rare.

### H5 — Consultations : plus de 9 dates dans un mois ⚠️ majeur
- Le moteur insère une colonne au-delà de la 9e date, mais la valeur écrite est ensuite déplacée
  ou écrasée : les dates au-delà de 9 **disparaissent** du tableau. Le total mensuel est une plage
  fixe `=SUM(B14:J14)`.
- Ex. (`audit-consultations-10-dates`, 12 dates de 2 patients en novembre) : novembre = 18
  (attendu 24), total annuel = 23 (attendu 29). Avec beaucoup de dates (`consultations-denses`), le
  total annuel (2 541) ne correspond plus à la somme des types affichée à côté (3 188).
- Prévalence : **fréquente** (une part importante des années de formation).

### H6 — Gardes : plus de 9 gardes dans un mois ⚠️
- Total mensuel en plage fixe `=SUM(B12:J12)` : les gardes au-delà de la 9e ne sont pas comptées.
  Au-delà d'environ 20 gardes dans un mois, une valeur écrase la formule du total du mois et le
  « Nb total de gardes » est écrit au milieu du tableau (cellule `R11` dans `consultations-denses`).
- Ex. (`audit-gardes-10-par-mois`, 11 gardes de 3 patients) : « Nb patients traités » = 34
  (attendu 40).
- Prévalence : occasionnelle.

### H7 — Rapport d'activité : garde finissant à minuit
- Une garde terminant à 00:00 compte une demi-journée « matin » le lendemain (règle de l'ancien
  moteur : fin ≤ 12:00 → matin).
- Ex. (`audit-garde-minuit`) : 12:00 → 00:00 = 3 demi-journées (attendu 2).
- Prévalence : fréquente.

### H8 — Rapport d'activité : évènement à cheval sur le nouvel an
- Les jours intermédiaires sont comparés par numéro de jour dans l'année (`date('z')`) : du
  30/12 au 02/01, 31/12 et 01/01 ne sont pas comptés.
- Ex. (`audit-nouvel-an`, congrès du 30/12 13:00 au 02/01 11:00) : 3 demi-journées (attendu 9).
- Prévalence : occasionnelle.

### H9 — Deux maîtres de stage dans une année
- Le tableau du carnet est décalé d'une ligne, ses totaux ne couvrent plus que 2 lignes (ex. :
  1 intervention au lieu de 6), et le second maître de stage n'apparaît pas dans le décompte.
- Prévalence : **0** (l'API impose désormais un seul maître de stage).

### H10 — Interventions sans nomenclature
- En chirurgie générale, toutes les interventions sans nomenclature (toutes spécialités, y compris
  l'orthopédie sans nomenclature) sont regroupées sur **une seule ligne**, au libellé et dans la
  section de la première.
- Prévalence : occasionnelle.

### H11 — Interventions absentes du récapitulatif (données de référence)
Ces interventions sont dans le carnet mais n'apparaissent dans aucune ligne du récapitulatif :
| Cause |
|---|
| Orthopédie, sous-type vide |
| Orthopédie, sous-type tronqué `proximalFe` / `distalFemu` (au lieu de `proximalFemur` / `distalFemur`) |
| Spécialité `favorites` |
| Chirurgie générale avec sous-type (ex. neurochirurgie « back ») |
| Orthopédie avec nomenclature sans type électif/trauma |
Correction possible côté données (référentiel), sans changer le moteur ; à décider.

### H12 — Positions hors 1, 2, 3
- Listées dans le carnet sans marque, non comptées dans « Nbre total des interventions ».
- Prévalence : 0.

### H13 — Aucune intervention : `#DIV/0!`
- Les pourcentages du carnet (`=I75/(I75+J75+L75+M75)`) affichent `#DIV/0!` pour une année sans
  intervention. Cosmétique.

### H14 — Formations de durée nulle
- Début = fin : comptées comme une matinée dans le rapport d'activité (aucune demi-journée réelle).
- Prévalence : fréquente.

### H15 — Types de consultation non reconnus
- Les totaux par type ne connaissent que `ortho`, `traumato`, `dig`, `uro`, `vasc`, `plast`. Le
  frontend enregistre aussi `plastic`, `general`, `thor` : ces consultations sont dans le total
  annuel mais dans aucun type.
- Prévalence : occasionnelle (surtout `plastic`).

## Conforme

Carnet (lignes, positions, totaux, pourcentages, décompte par superviseur jusqu'à 30 superviseurs),
orthopédie élective et traumatologie (lignes, totaux de ligne, sous-totaux, TOTAL GENERAL),
consultations et gardes jusqu'à 9 par mois, rapport d'activité (consultations et interventions),
évaluation du candidat. Cas de référence calculé à la main : `TotalsAuditTest::testHandComputedReferenceCase`.
