# Plan de mise en production — MedClick 3.6.1 (nomenclature INAMI, étape 5)

Actualisation NomenSoft (tableaux applicables au 01/11/2026) : 2 ajouts, 448 abrogations établies ;
correction des dates des interventions déjà datées après l'abrogation (avertissement, sans recodage).
**Rien n'est exécuté en production sans autorisation explicite.** Références :
`docs/NOMENCLATURE-INAMI-ETAPE5.md`, `docs/DEPLOIEMENT-VPS.md`.

**Aucune migration.** Le déploiement du code peut provoquer une courte indisponibilité (recréation du
backend) ; les imports de données n'en provoquent aucune. Aucune notification aux utilisateurs.

## 0. Prérequis 🔒

PR fusionnées (`M` backend, `N` PWA), CI verte sur `M`, contenu fusionné = branches recettées ;
production saine (`./deploy.sh --verify` sur les SHA en service) ; aucun export en cours ; instantané
des autres applications du VPS.

## 1. Sauvegardes et empreintes 🔒

Comme `docs/MEP-3.6.0.md` §1 (base vérifiée, `.env`, Compose, `deploy.sh`, frontend, images),
préfixe `prod-avant-3.6.1-$TS`, puis `validation/nomenclature-etape3-fingerprint.sh 3.6.1-1-avant`.

## 2. Code (aucune donnée modifiée)

```bash
git -C <pwa> archive N frontend | ssh surgicalhub-prod "cd /opt/stack/apps/medclick-prod && mv src/pwa src/pwa.bcf7434 && mkdir src/pwa && tar -x -C src/pwa && echo N > src/pwa/.commit"
./deploy.sh M N && ./deploy.sh --verify M N        # verrou : aucune migration ; code 0
```

Contrôles : empreintes identiques ; « Version 3.6.1 » ; journaux sans erreur.

## 3. Ajouts (2 codes) 🔒

```bash
P=data/inami/plan-import-inami-20261101
docker compose exec -T backend php bin/console app:nomenclature:import-inami $P-1-ajouts.json           # attendu : 2 à ajouter
docker compose exec -T backend php bin/console app:nomenclature:import-inami $P-1-ajouts.json --apply   # 2 ajoutés
```

Contrôles : 2 nouvelles lignes ; les 1 775 lignes existantes, interventions et favoris inchangés.

## 4. Désactivations (448 abrogations établies) 🔒

```bash
docker compose exec -T backend php bin/console app:nomenclature:import-inami $P-2-desactivations.json           # attendu : 448 à désactiver, 0 conflit
docker compose exec -T backend php bin/console app:nomenclature:import-inami $P-2-desactivations.json --apply   # 448 désactivés
docker compose exec -T backend php bin/console app:nomenclature:import-inami $P-2-desactivations.json --apply   # idempotence : 448 déjà appliquées
```

Contrôles : interventions et favoris identiques ligne par ligne ; identité, libellé, type et
sous-type des 1 775 lignes inchangés ; 448 inactives avec date officielle, source et vérification ;
exports Excel des années les plus concernées identiques avant / après (empreinte des feuilles,
horloge figée) ; journaux et `error_log` sans erreur.

## Retour arrière

| Étape | Action |
|---|---|
| Désactivations | `… import-inami $P-2-desactivations.json --revert` (simulation) puis `--revert --apply` : rétablit exactement les lignes importées, ne touche pas une ligne modifiée depuis. |
| Ajouts | jamais supprimés ; désactivation par l'administration si nécessaire. |
| Code | **après** le retour arrière des désactivations : `mv src/pwa src/pwa.N && mv src/pwa.bcf7434 src/pwa` puis `./deploy.sh 66c0b4aec4546c8dcea5219e8254520b0d9bd2ba bcf7434e46dad135f209d88134696c3b1733e189` (sans la règle de correction des dates déjà postérieures). |
| Dernier recours | restauration de `backups/prod-avant-3.6.1-$TS.sql.gz`, décision explicite, perte des écritures postérieures. |
