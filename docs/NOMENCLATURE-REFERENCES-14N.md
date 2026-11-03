# Correction des références de l'article 14 n) (ADR-212)

Source : NomenSoft, tableaux applicables au 01/11/2026. Détail ligne par ligne, avant et après :
`data/inami/plan-correction-references-14n-20261101.json`.

## Résultat

| | Lignes | Effet |
|---|---|---|
| Corrigées | 72 | 69 lignes décalées, 6872, 6968, et 6871 (dépendance de 6872) |
| Inchangées, non partagées | 2 | 6950 et 6957 : doublons d'un acte porté par une autre ligne |
| Autorisées en neurochirurgie après correction | 128 | 56 de la 3.7.0 + les 72 corrigées |

Aucune des 72 corrections n'est une simple inversion des colonnes. Exemple, ligne 6840 (K200) :
`225094 / 225083` devient `225083 / 225072` ; 225094 est le code ambulant de l'acte suivant (K250).

## Ce qui lit quoi

| Usage | Source | Effet d'une correction de la nomenclature |
|---|---|---|
| Création d'intervention | code de la nomenclature, copié | **code corrigé** pour les nouvelles saisies |
| Modification, acte inchangé | code, libellé, spécialité de l'intervention | aucun |
| Modification, autre acte choisi | code du nouvel acte, copié | code corrigé, choix explicite |
| Liste et détail des interventions | copies de l'intervention | aucun |
| Carnet Excel | `Surgeries.code`, `name` | aucun |
| Récapitulatif Excel | `Surgeries.code`, `name` ; groupe par lien de nomenclature ; type et région de la nomenclature | aucun sur l'historique |
| Statistiques | comptage des interventions par rôle | aucun |
| Favori existant | copie du favori, lien de nomenclature | copie conservée |
| Saisie depuis un favori | `surgeryId`, code de la nomenclature | **code corrigé** |
| Listes de choix, messages de refus | code de la nomenclature | code corrigé |

Commandes manuelles, aucune n'est planifiée (crontab : sauvegarde et gardien des exports) :
- `app:repair-surgery-nomenclature` : une intervention dont le code diffère de sa nomenclature sort de son périmètre.
  Elle n'est donc pas recodée. Les interventions corrigées ne sont plus candidates à la correction de la spécialité
  « favorites » (`FAVORITES_CODE_MISMATCH`).
- `app:update-surgeries` : uniquement les interventions sans nomenclature.
- `app:update-favorites` : uniquement les favoris sans nomenclature depuis ADR-212.

## Favoris

84 favoris liés aux lignes corrigées gardent leur ancien code copié. Ce code n'est pas affiché par la PWA et ne sert pas
à la saisie. Il reste renvoyé par `getMyList` et `getById`. Il est actualisé seulement si l'utilisateur choisit un autre
acte. Proposition pour plus tard : renvoyer aussi le code courant, sans réécrire la copie.

## Corrections distinctes, non traitées ici

1. **6950 et 6957, à décider** (une intervention chacune, aucun favori). Elles dupliquent 6951 et 6956 et gardent leurs
   anciens codes. Une saisie en orthopédie sur ces lignes copie donc encore un code inexact. Recommandation : les
   désactiver, sans suppression, ce qui les retire des listes en conservant leur historique.
2. **Doublon 226542** : 6957 et 6961. Disparaît des listes si 6957 est désactivée ; 6961 est exacte.
3. **Deux actes officiels sans ligne MedClick** : 225621/225610 (cure de spondylolisthèse, K560) et 225901/225890
   (scoliose thoraco-lombaire de plus de 6 niveaux, K670).
4. **Code affiché au récapitulatif** : une ligne regroupe par nomenclature et affiche le code de la première
   intervention. Après correction, une nouvelle intervention s'additionne donc sur la ligne de l'ancien code. Les
   compteurs restent justes, mais le code affiché peut être l'ancien.
5. **Messages** : l'avertissement d'abrogation et les refus citent le code de la nomenclature, pas celui de
   l'intervention. Sans effet aujourd'hui : aucune des 72 lignes n'est abrogée.
6. **Erreur `error_log` n° 10** : voir ci-dessous.

## Erreur Webpack (`error_log` n° 10)

`GET` ou `HEAD /` sur l'API rend `app/index.html.twig`, ancien gabarit de `AppController` qui attend
`public/build/entrypoints.json`, absent de l'image. Réponse 500 à chaque appel. Sans impact sur les utilisateurs : la
PWA est servie à part et n'appelle jamais `/`. Correction proposée, séparée : retirer cette route ou répondre sans gabarit.
