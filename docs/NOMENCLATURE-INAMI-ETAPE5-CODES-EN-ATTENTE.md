# Étape 5 — décision sur les 25 codes INAMI laissés en attente

Source : NomenSoft, tableaux applicables au 01/11/2026 (`data/inami/MANIFESTE-NOMENSOFT-20261101.txt`).
Codes : hospitalisation / ambulant. « Équivalent MedClick » = code existant au libellé identique ou proche.
Aucun des 1 777 codes en production n'est modifié par ce document ni par le plan 3.

## Synthèse

| Décision | Codes | Plan |
|---|---|---|
| Ajouter (correspondance certaine) — **fait sur staging** | 20 : 1 thoracique, 4 prothèses, 15 fractures | `data/inami/plan-import-inami-20261101-3-complements.json` |
| Ajouter après validation médicale | 1 : plastique 251661 | — |
| Ne pas ajouter | 4 : concertations pancréatiques | — |

## Tableau des 25 codes

| # | Hosp. / amb. | N | Intitulé officiel | Art. — entrée en vigueur | Équivalent MedClick | Raison de l'attente | Proposition |
|---|---|---|---|---|---|---|---|
| 1 | 229482 / 229471 | N250 | Péricardotomie | 14 e) — 01/10/1995, rubrique « Prestations chirurgicales par voie thoracoscopique » | 229261 Péricardotomie (voie classique, N250) | Libellé court identique | **Ajouter** : thor, libellé « Péricardotomie par voie thoracoscopique » |
| 2 | 251661 / 251650 | K150 | Plastie mammaire unilatérale avec insertion d'une prothèse ou d'un expanseur tissulaire | 14 c) II.1.A — 01/04/1985 | 251580 (K180), libellé identique depuis le 01/04/2019 | Deux codes en vigueur, même libellé, valeurs différentes | **Validation médicale** (voir ci-dessous) |
| 3 | 286661 / 286650 | N200 | Retrait d'une prothèse totale de l'articulation trapézo-métacarpienne suivie d'une trapézectomie | 14 k) § 1 C.1° — 01/10/2024 | aucun (275645 = arthroplastie primaire) | Classification à valider | **Ajouter** : ortho, type 1 (électif), poignet-main |
| 4 | 286683 / 286672 | N250 | Remplacement isolé d'un insert en polyéthylène d'une prothèse totale trapézo-métacarpienne | 14 k) § 1 C.1° — 01/10/2024 | aucun (277944 = insert d'épaule) | Classification à valider | **Ajouter** : ortho, type 1, poignet-main |
| 5 | 286705 / 286694 | N440 | Remplacement d'un composant d'une prothèse totale trapézo-métacarpienne (excepté insert) | 14 k) § 1 C.1° — 01/10/2024 | aucun | Classification à valider | **Ajouter** : ortho, type 1, poignet-main |
| 6 | 286742 / 286731 | N925 | Révision d'une prothèse totale de cheville vers une arthrodèse | 14 k) § 1 D.1° — 01/10/2024 | 279204 (libellé MedClick « Remplacement complet … ou révision vers une arthrodèse » ; officiel : remplacement complet seul) | Classification à valider | **Ajouter** : ortho, type 1, cheville |
| 7 | 296984 / 296973 | N30 | ° Fracture d'un doigt | 14 k) § 2 C.1° d) **sans réduction** — 01/04/1985 | 296446 = b) **avec réduction** (N75) | Libellé identique | **Ajouter** : type 2, poignet-main, « … — traitement non sanglant, sans réduction » |
| 8 | 298723 / 298712 | N130 | Fracture ou luxation du bassin | 14 k) § 2 D.1° d) sans réduction — 01/04/1985 | 298222 avec réduction (N250) | Libellé identique | **Ajouter** : type 2, bassin |
| 9 | 298745 / 298734 | N130 | Fracture du col du fémur | idem | 298266 avec réduction (N300) | Libellé identique | **Ajouter** : type 2, hanche |
| 10 | 298760 / 298756 | N130 | Fracture intertrochantérienne du fémur | idem | 298281 avec réduction (N300) | Libellé identique | **Ajouter** : type 2, fémur proximal |
| 11 | 298782 / 298771 | N130 | Fracture de la diaphyse du fémur | idem | 298303 avec réduction (N300) | Libellé identique | **Ajouter** : type 2, diaphyse fémorale |
| 12 | 298826 / 298815 | N90 | ° Fracture de la rotule | idem | 298340 avec réduction (N125) | Libellé identique | **Ajouter** : type 2, genou |
| 13 | 298841 / 298830 | N90 | ° Fracture du plateau tibial | idem | 298362 avec réduction (N200) | Libellé identique | **Ajouter** : type 2, genou |
| 14 | 298863 / 298852 | N90 | ° Fracture diaphysaire de la jambe : tibia ou les deux os | idem | 298384 avec réduction (N250, sans région) | Libellé identique ; région absente du jumeau | **Ajouter** : type 2, jambe (`limb`) |
| 15 | 298885 / 298874 | N80 | ° Fracture du pilon tibial | idem | 298406 avec réduction (N200) | Libellé identique | **Ajouter** : type 2, cheville |
| 16 | 298900 / 298896 | N80 | ° Fracture unimalléolaire | idem | 298421 avec réduction (N125) | Libellé identique | **Ajouter** : type 2, cheville |
| 17 | 298922 / 298911 | N80 | ° Fracture bi- ou trimalléolaire | idem | 298443 avec réduction (N200) | Libellé identique | **Ajouter** : type 2, cheville |
| 18 | 298944 / 298933 | N80 | ° Fracture de l'astragale | idem | 298465 avec réduction (N200) | Libellé identique | **Ajouter** : type 2, pied |
| 19 | 298966 / 298955 | N80 | ° Fracture du calcanéum | idem | 298480 avec réduction (N200) | Libellé identique | **Ajouter** : type 2, pied |
| 20 | 298981 / 298970 | N80 | ° Fracture du tarse antérieur | idem | 298502 avec réduction (N120) | Libellé identique | **Ajouter** : type 2, pied |
| 21 | 299003 / 298992 | N80 | ° Fracture du métatarse (un ou plusieurs métatarsiens d'un même pied) | idem | 298546 avec réduction (N125) | Libellé identique | **Ajouter** : type 2, pied |
| 22 | 350125 / 350114 | K121 | Intervention globale pour la concertation multidisciplinaire (pancréas / région péri-ampullaire), patients envoyés par un spécialiste extérieur | Pseudonomenclature, convention « chirurgie complexe du pancréas » — 01/07/2019 | aucun | Hors article 14 | **Ne pas ajouter** |
| 23 | 350140 / 350136 | K51 | Idem, patients présentés directement au centre | idem | aucun | Hors article 14 | **Ne pas ajouter** |
| 24 | 350162 / 350151 | K17 | Participation à la concertation par le spécialiste référant extérieur | idem | aucun | Hors article 14 | **Ne pas ajouter** |
| 25 | 350184 / 350173 | K17 | Participation à la concertation par le médecin généraliste traitant | idem | aucun | Hors article 14 | **Ne pas ajouter** |

Fractures : sauf « ° Fracture d'un doigt » (membre supérieur, § 2 C), toutes relèvent du
§ 2 D (ceinture pelvienne et membres inférieurs). Le préfixe « ° » officiel est retiré du libellé
MedClick, comme pour les codes « avec réduction » déjà présents.

## Justifications

**Fractures (15).** NomenSoft range les deux familles dans le § 2 « Traitements non sanglants » :
- b) traitement **avec réduction** : codes déjà présents dans MedClick ;
- d) traitement **sans réduction** : nouveaux codes.

Ce sont donc deux actes distincts, de valeur différente : par exemple N130 contre N300 pour le
col du fémur. Aucun de ces codes n'est un traitement sanglant (ostéosynthèse) ; ceux-ci ont leurs
propres codes au § 1. La région reprend celle du code « avec réduction » correspondant, pour
garder les deux modalités groupées dans le carnet. La valeur tronquée `proximalFe` est normalisée
en `proximalFemur`.

**Prothèses (4).** Les 4 actes ont été créés par le KB2024007896, en vigueur le 01/10/2024.
Les trois actes trapézo-métacarpiens complètent 286720 (déjà importé) et relèvent de la région
poignet-main. La révision de prothèse de cheville vers une arthrodèse, 286742, était auparavant
couverte par 279204. Depuis le 01/10/2024, 279204 ne couvre plus que le remplacement complet,
mais le libellé MedClick de 279204 mentionne encore la révision vers une arthrodèse (voir les
décisions).

**Thoracique.** 229261 (1985) correspond à la voie classique, 229482 (1995) à la rubrique
thoracoscopique.

**Plastique.** Avant le 01/04/2019, les deux codes avaient des indications différentes :
- 251661 (K150) : hypoplasie grave ou aplasie congénitale majeure unilatérale, ou malformation ;
- 251580 (K180) : seins tubéreux, par sein.

Depuis 2019, leur libellé officiel est identique. Les indications ne figurent plus que dans les
règles d'application, qui ne sont pas dans NomenSoft. Proposition : ajouter 251661 avec le libellé
« … — hypoplasie / aplasie congénitale unilatérale ou malformation ». Le libellé de 251580 serait
précisé « seins tubéreux » lors de la correction distincte des codes existants.

**Concertations pancréatiques.** Ce sont des forfaits de pseudonomenclature : une réunion de
concertation, pas un acte opératoire. Elles ne correspondent à aucune rubrique du carnet
(interventions, position 1re / 2e main, superviseur). La chirurgie pancréatique elle-même est
déjà codée à l'article 14 d).

## Code commun orthopédie / neurochirurgie

Aucun des 25 codes n'est concerné. Ils relèvent tous d'un seul sous-article.

La question se pose pour l'**article 14 n)**, commun à l'orthopédie et à la neurochirurgie (rachis) :
- ses 130 prestations sont dans MedClick, toutes sous `ortho` ;
- un résident en neurochirurgie ne les voit donc pas.

Recommandation : **ne pas dupliquer les lignes** en `neuro`. Un même code présent deux fois
rendrait ambiguës la liaison des interventions et des favoris par code (`app:update-surgeries`)
ainsi que les statistiques. Il vaut mieux une ligne unique, rendue visible depuis la neurochirurgie
par un filtre « spécialités concernées » de l'API (par exemple, 14 n) visible en `ortho` et en
`neuro`). C'est une évolution fonctionnelle distincte, à décider.

Anomalie constatée (correction distincte) : le code hospitalisation 226542 figure sur deux
lignes ortho :
- id 6957, « Fermeture d'une brèche de la dure mère » ;
- id 6961, « Répétition du traitement chirurgical d'une infection profonde… ».

Officiellement, 226542 appartient à la seconde.

## Recette staging (02/11/2026)

- **Déploiement :** backend `325cd8d`, PWA inchangée.
- **Plan 3 :** simulation (20 codes à ajouter), puis 20 ajoutés ; une seconde exécution les signale « déjà présents ».
- **Données existantes :** empreintes identiques pour les interventions, les favoris et les 1 778 codes déjà présents.
- **PWA :**
  - la recherche « col du fémur » affiche les deux modalités ;
  - un enregistrement avec le compte de recette stocke `298745N130` sur le nouveau code ;
  - l'intervention a ensuite été supprimée, les empreintes sont revenues identiques.
- **API :** les 19 nouveaux codes ortho et le code thoracique sont listés.
