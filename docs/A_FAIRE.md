# Travaux à faire — MedClick après la version 3.2.0

> État au 28/10/2026. **Backlog, pas un journal de fonctionnalités livrées.**
> Les trois chantiers ci-dessous sont **non réalisés**, et **ne bloquent pas** la version 3.2.0 déjà en production.
> Aucun changement de code, de base de données ou de déploiement n'est autorisé par cette liste.
> Toute intervention suit la règle : branche dédiée → tests → staging → décision explicite avant production.

## 1. Étudier un dépôt Git unique (« monorepo ») — à décider

**Situation :** deux dépôts GitHub servent une seule application :
- `Sftaita/medclick-backend` (public, branche `master`) : API Symfony, déploiement VPS, documentation technique ;
- `Sftaita/pwa-medclick` (privé, branche `main`) : frontend React/Vite, avec un ancien frontend CRA à la racine.

**À faire :**
- Étudier le regroupement du backend et du frontend dans **un dépôt privé unique**, avec des dossiers séparés et une documentation centrale.
- Conserver les services API et frontend indépendants, leurs tests, la sécurité et les environnements staging/production.
- Évaluer la conservation des historiques Git, des droits d'accès et le traitement de l'historique du dépôt backend actuellement public ; vérifier l'absence de secrets et de données personnelles.
- Adapter les pipelines CI, `deploy/vps/deploy.sh`, les références aux SHA et la livraison du frontend (actuellement transféré sous forme d'archive privée).
- Comparer les bénéfices au coût de migration, tester sur staging et formaliser une **nouvelle ADR** avant tout changement.

**Terminé quand :** une décision explicite « monorepo ou deux dépôts », son plan de migration/retour arrière et, si retenu, les tests et le déploiement staging sont validés. **Aucune fusion de dépôts décidée à ce stade.**

## 2. Nettoyer ou retirer l'ancien frontend React CRA et ses libellés Excel — à faire

**Situation :** dans `Sftaita/pwa-medclick`, le répertoire `src/js/` à la racine correspond à l'ancien frontend Create React App (CRA). Il contient encore les libellés « Excel (New) » et « Excel (Old) ». Il **n'est pas déployé par la chaîne VPS actuelle** ; le frontend moderne `frontend/` (Vite) affiche un seul bouton « Excel » par année.

**À faire :**
- Vérifier où l'ancien frontend reste accessible, les PWA/onglets anciens encore actifs et les utilisateurs éventuels, sans perturber leur accès.
- Décider entre correction des libellés et retrait contrôlé de l'ancien frontend ; supprimer toute distinction trompeuse « ancien/nouveau Excel » dans l'interface encore servie.
- Préserver les téléchargements et le format officiel du carnet.
- **Ne pas supprimer** `/api/excel` et `/api/excel2` tant que des clients les appellent. Mesurer les appels dans les journaux et fixer une période sans usage avant leur retrait (ADR-203).
- Tester la compatibilité, la transition PWA/service worker et le retour arrière sur staging avant toute modification de production.

**Terminé quand :** aucun utilisateur ne voit plus ces anciens libellés, les clients encore actifs restent compatibles et le retrait éventuel des alias est explicitement validé.

## 3. Corriger les 15 écarts historiques du fichier Excel (H1 à H15) — à traiter séparément

**Situation :** l'audit `docs/AUDIT-TOTAUX-EXCEL.md` détaille **15 écarts historiques non corrigés** concernant notamment certains totaux ou répartitions. Ils proviennent du comportement historique reproduit par le moteur unique de MedClick 3.2.0 ; **le nouveau générateur n'a pas introduit ces écarts**. Les fichiers de la 3.2.0 sont conformes aux fichiers de référence et au format historique.

**À faire :**
- Reprendre H1 à H15 **un par un**, apprécier leur impact et fixer l'ordre de correction avec le responsable.
- Pour chaque correction, expliciter la règle métier attendue, les conséquences sur le carnet officiel et l'éventuel traitement des données de référence.
- Obtenir l'accord du responsable avant de modifier le contenu ou les calculs du fichier Excel.
- Adapter `TotalsAuditTest`, les fichiers de référence figés et les tests de non-régression **uniquement après décision explicite** (ADR-202 et ADR-204).
- Comparer les fichiers, faire une recette sur staging et documenter la version qui introduit chaque changement.

**Terminé quand :** chaque écart est soit corrigé et validé, soit conservé avec une justification et une décision tracée.

## Références

- `docs/DECISIONS.md` : ADR-001 (séparation historique des dépôts), ADR-202 (générateur unique), ADR-203 (alias de compatibilité), ADR-204 (écarts H1–H15), ADR-207 (déploiement).
- `docs/EXPORTS.md` : fonctionnement de l'export et compatibilité des anciennes routes.
- `docs/AUDIT-TOTAUX-EXCEL.md` : description et impact des écarts H1 à H15.
- `docs/DEPLOIEMENT-VPS.md` : déploiement, transfert du frontend privé et transition PWA.
- Frontend privé : `frontend/docs/VERSIONS.md` et `frontend/docs/A_FAIRE.md`.

**Règle :** garder ce fichier à jour lorsque l'état d'un chantier évolue et consigner les décisions structurantes dans une ADR. Rien de ce backlog n'est un défaut bloquant pour le fonctionnement actuellement déployé.
