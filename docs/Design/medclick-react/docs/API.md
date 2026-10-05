# Contrat backend

Le front ne dépend que de l'interface `MedClickApi` (`src/api/types.ts`). L'implémentation HTTP est dans
`src/api/http.ts`. Tous les payloads sont en JSON ; les dates métier sont au format `AAAA-MM-JJ`.
Les rôles valent `FIRST_HAND` (1re main), `ASSISTANT` (assistance) ou `OBSERVER` (observation).

## Endpoints

| Méthode | Route | Corps / réponse |
|---|---|---|
| POST | `/auth/login` | `{ email, password }` → `UserProfile` |
| GET | `/me` | `UserProfile` |
| GET | `/dashboard` | `Dashboard` |
| GET | `/surgeries?region=&q=` | `Surgery[]` (plus récentes d'abord) |
| GET | `/surgeries/{id}` | `Surgery` |
| PATCH | `/surgeries/{id}` | champs partiels → `Surgery` |
| DELETE | `/surgeries/{id}` | 204 |
| **POST** | **`/surgeries/batch`** | `BatchRequest` → `BatchResponse` (voir plus bas) |
| GET | `/me/favorite-actes` | `string[]` |
| PUT / DELETE | `/me/favorite-actes/{acteId}` | 204 |
| GET | `/weeks/current?offset=0` | `WeekSummary` (inclut `streak`) |
| GET | `/days/{date}` | `DayDetail` |
| GET | `/milestones` | `Milestone[]` |
| POST | `/milestones/celebrated` | `{ ids: string[] }` → 204 |
| POST | `/me/streak/ack` | `{ weeks }` → 204 |
| GET | `/statistics` | `Statistics` |
| GET | `/partners/current` | `Partner` |

Un enregistrement simple est un lot d'une ligne avec une quantité de 1 : il n'y a qu'une seule route de création.

## POST `/surgeries/batch` — encodage multiple / journée opératoire

```json
{
  "common": { "date": "2026-10-04", "surgeon": "Dr De Muylder", "trainingYear": 3, "nomenclature": "K50 - Plastie LCA" },
  "lines": [
    { "acteId": "lca", "quantity": 3, "roles": ["FIRST_HAND", "FIRST_HAND", "ASSISTANT"] },
    { "acteId": "arthro-genou", "quantity": 2, "roles": ["ASSISTANT", "ASSISTANT"] }
  ]
}
```

Règles :
1. Ne **jamais** stocker une intervention avec `quantity = 3`. Le serveur crée **une ligne `Surgery` par
   unité** (ici 5 enregistrements distincts), chacune modifiable et supprimable individuellement.
2. **Atomique** : tout est validé, puis tout est écrit dans **une seule transaction**. En cas d'erreur,
   rollback complet : on ne se retrouve jamais avec 1 ou 2 interventions sur 3.
3. Validation : `1 ≤ quantity ≤ 20`, `roles.length === quantity`, acte connu, date non future.
4. Réponse (calculée dans la même requête, après le commit) :

```json
{
  "created": [ { "id": "…", "acteId": "lca", "date": "2026-10-04", "role": "FIRST_HAND", "…": "…" } ],
  "before": { "interventions": 121, "firstHand": 44, "…": "…" },
  "after":  { "interventions": 124, "firstHand": 47, "…": "…" },
  "newlyAchieved": [ { "id": "fh-50", "title": "50 interventions en première main", "achievedAt": "…", "celebratedAt": null } ]
}
```

`before` / `after` permettent l'animation 121 → 124. Le front ne lance l'animation de succès
**qu'après** cette réponse.

### Exemple Symfony / Doctrine (si le backend est en Symfony)

```php
#[Route('/api/surgeries/batch', methods: ['POST'])]
public function batch(BatchRequest $req, EntityManagerInterface $em, StatsService $stats, MilestoneService $ms): JsonResponse
{
    $user = $this->getUser();
    $before = $stats->year($user);
    $created = $em->wrapInTransaction(function () use ($req, $em, $user) {
        $out = [];
        foreach ($req->lines as $line) {
            $acte = $this->actes->find($line->acteId) ?? throw new BadRequestHttpException('Acte inconnu');
            if ($line->quantity < 1 || $line->quantity > 20 || count($line->roles) !== $line->quantity) {
                throw new BadRequestHttpException('Quantité ou rôles invalides');
            }
            foreach ($line->roles as $role) {
                $s = (new Surgery())->setUser($user)->setActe($acte)->setRole($role)
                    ->setDate($req->common->date)->setSurgeon($req->common->surgeon)
                    ->setTrainingYear($req->common->trainingYear)->setNomenclature($req->common->nomenclature);
                $em->persist($s);
                $out[] = $s;
            }
        }
        $em->flush();
        return $out;
    });
    $newly = $ms->unlockNewlyReached($user); // pose achievedAt, laisse celebratedAt à null
    return $this->json(['created' => $created, 'before' => $before, 'after' => $stats->year($user), 'newlyAchieved' => $newly]);
}
```

## Milestones — célébrer une seule fois (§37.6)

Table `user_milestone` : `user_id`, `milestone_id`, `achieved_at`, `celebrated_at` (nullable).

- Quand une écriture fait franchir un seuil : insérer avec `achieved_at = now()` et `celebrated_at = NULL`.
- `GET /dashboard` → `pendingCelebrations` = milestones avec `achieved_at` renseigné et `celebrated_at` NULL.
- Le front montre la célébration puis appelle `POST /milestones/celebrated` → `celebrated_at = now()`.
- Ne jamais déduire « à célébrer » de `valeur >= seuil` : un badge déjà obtenu reste visible dans
  Progression › Milestones sans rejouer l'animation.

## Série de semaines (§37.13)

`streak = { weeks, acknowledgedWeeks }`. Si `weeks > acknowledgedWeeks`, le front anime l'augmentation
une fois puis appelle `POST /me/streak/ack`.

## Évolutions (§37.15)

`YearStats.deltas.*` vaut `null` quand la comparaison n'est pas calculable proprement (pas de période
précédente, effectif trop faible…). Le front n'affiche alors aucun badge : aucune comparaison inventée.
