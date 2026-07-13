# Module IA — Bloc B : prospects similaires (EF-08.3)

**Date :** 2026-07-13
**Statut :** Design approuvé (à implémenter)
**Prérequis :** Bloc A (Module IA) fusionné dans `main`.

## Objectif

Sur la fiche d'un établissement, proposer les **prospects les plus similaires**
au niveau national : d'autres entreprises au profil sémantiquement proche
(activité, taille, présence en ligne), classées par similarité d'embeddings,
en excluant la fiche elle-même et les établissements de sa propre unité légale.

## Décisions actées (brainstorming)

1. **Portée = similarité sémantique nationale** — pas de contrainte géographique
   ni de bornage sectoriel. Pur plus-proches-voisins par embedding.
2. **Fournisseur = Mistral** (`mistral-embed`, **1024 dimensions**, validé par
   appel API réel). Clé dans `backend/.env` (`MISTRAL_API_KEY`, `FBDE_AI_DRIVER=mistral`).
3. **Exclusions** : la fiche courante **et** tous les établissements du même
   SIREN (succursales = même entreprise, pas de nouveaux prospects).
4. **Top 8** résultats.

## Architecture

Même patron substituable que le Bloc A : contrat fin, client réel + doublure de
test, service métier, garde RGPD réutilisée. Aucun appel réseau dans les tests.

```
EmbeddingClient (contrat)  ─┬─ MistralEmbeddingClient (réel, mistral-embed)
                            └─ FakeEmbeddingClient (doublure déterministe)
Prompts::embeddingText()  → texte minimisé RGPD à embedder
CompanyEmbedder            → génère + stocke le vecteur (réutilise assertAllowed)
SimilarProspects           → requête KNN cosinus + exclusions + filtre RGPD
AiController::similar()     → GET /companies/{id}/similar
fbde:ai:embed              → backfill batch (jumelle de fbde:ai:summarize)
SimilarProspectsPanel.tsx  → panneau frontend sur la fiche
```

### Infrastructure — pgvector

L'image `postgis/postgis:16-3.4` n'inclut pas pgvector. On l'ajoute sans perdre
PostGIS via une image dérivée :

- `docker/postgres/Dockerfile` : `FROM postgis/postgis:16-3.4` + installation du
  paquet PGDG `postgresql-16-pgvector`.
- `docker-compose.yml` : le service `postgres` passe de `image:` à `build:` sur
  ce Dockerfile (image locale `fbde-postgres`).
- Migration `enable_pgvector` : `CREATE EXTENSION IF NOT EXISTS vector`.

### Contrat et clients d'embedding

```php
interface EmbeddingClient {
    /** @return list<float> vecteur de 1024 flottants */
    public function embed(string $text): array;
}
```

- `MistralEmbeddingClient` : `Http::withToken(config('services.mistral.key'))
  ->post(config('fbde.ai.embedding_endpoint'), ['model' => config('fbde.ai.embedding_model'),
  'input' => [$text]])->throw()->json('data.0.embedding')`. Endpoint
  `https://api.mistral.ai/v1/embeddings`, modèle `mistral-embed`.
- `FakeEmbeddingClient` : vecteur **déterministe** dérivé du texte (hash → 1024
  flottants normalisés). Zéro réseau. Permet de tester la commande de backfill.
- Binding par `FBDE_AI_DRIVER` (mistral | fake) dans `AiServiceProvider`, comme
  l'`AiClient`. `phpunit.xml` force déjà `fake`.

Config ajoutée à `fbde.ai` : `embedding_model` (`mistral-embed`),
`embedding_endpoint`, `embedding_dimensions` (1024).

### Schéma

Migration `add_embedding_to_establishments` :
- `embedding vector(1024)` **nullable** (colonne pgvector).
- `embedded_at timestamptz` **nullable** (horodatage de génération).
- Index **HNSW** cosinus : `CREATE INDEX establishments_embedding_hnsw
  ON establishments USING hnsw (embedding vector_cosine_ops)`.

Le vecteur n'est **pas** exposé par `EstablishmentResource` (donnée interne,
lourde). Il est manipulé en SQL brut (littéral `'[...]'::vector`), jamais comme
attribut Eloquent sérialisé.

### Texte embeddé (minimisation RGPD)

`Prompts::embeddingText(Establishment): string` réutilise **la même allow-list**
que le résumé (`minimizedFacts`) : nom, libellé/segment NAF, ville, tranche
d'effectif, présence web, réseaux sociaux, + description **assainie** si
présente. **Jamais de SIRET ni SIREN.** Centralise la minimisation avec le
Bloc A (une seule source de vérité).

### Génération (backfill)

Commande `fbde:ai:embed --department= --limit=` — jumelle de `fbde:ai:summarize` :
- Cible les établissements **diffusibles** (`whereHas('company', is_diffusible=true)`)
  **sans embedding** (`whereNull('embedding')`), optionnellement par département,
  plafonnés par `--limit`.
- Pour chacun : `assertAllowed()` (garde RGPD, réutilisée), puis
  `embed(embeddingText())`, puis `UPDATE ... SET embedding = ?::vector,
  embedded_at = now()`.
- Résiliente (une erreur par fiche n'arrête pas le lot : compteurs
  générés/ignorés/erreurs), tracée dans `imports` (source `ai_embedding`,
  statut running→completed/failed, stats).

### Requête de similarité et API

`SimilarProspects::for(Establishment $e, int $limit = 8): Collection`
- Refuse si `$e` n'a pas d'embedding (retourne vide → l'API répond 422
  « fiche pas encore indexée ») ou si non-diffusible (`assertAllowed`).
- Sinon, SQL brut :
  ```sql
  SELECT e.id, e.siret, e.name, e.city, e.naf_code, e.commercial_score,
         (e.embedding <=> :vec) AS distance
  FROM establishments e
  JOIN companies c ON c.id = e.company_id
  WHERE e.embedding IS NOT NULL
    AND e.id <> :id
    AND e.company_id <> :companyId          -- exclut le même SIREN
    AND c.is_diffusible = true              -- RGPD : diffusibles seulement
    AND NOT EXISTS (                         -- RGPD : hors liste d'exclusion
      SELECT 1 FROM exclusion_list x
      WHERE (x.identifier_type='siret' AND x.identifier_value=e.siret)
         OR (x.identifier_type='siren' AND x.identifier_value=c.siren))
  ORDER BY e.embedding <=> :vec
  LIMIT :limit
  ```
- `distance` cosinus (0 = identique, 2 = opposé) convertie en **score de
  proximité** `1 - distance/2` pour l'affichage.

`GET /companies/{id}/similar` (groupe `permission:companies.view`) :
- 200 `{ data: [ {id, siret, name, city, naf_code, commercial_tier, proximity} ] }`
- 422 si non-diffusible / exclue (garde RGPD) **ou** si la fiche n'a pas encore
  d'embedding (message distinct : « Fiche pas encore indexée pour la similarité »).
- 503 si le service d'embedding échoue (uniquement sur le chemin qui régénère ;
  ici la lecture ne régénère pas → pas de 503 en lecture standard).

### Frontend

`frontend/src/features/ai/SimilarProspectsPanel.tsx`, intégré à la fiche sous
l'argumentaire :
- Liste des 8 prospects : nom (lien vers la fiche), ville, activité (libellé NAF
  humain), badge palier commercial, **score de proximité** discret (barre ou %).
- **État vide clair** : si 422 « pas encore indexée » → message pédagogique
  (« Cette fiche n'est pas encore indexée. L'indexation se fait par lots. »)
  plutôt qu'une erreur brute.
- Chargé à l'affichage de la fiche (ou au dépliage du panneau), skeleton pendant
  le chargement, cohérent avec les cartes IA existantes.

## Sécurité / RGPD

- **Aucun SIRET/SIREN** dans le texte embeddé (allow-list partagée avec le Bloc A).
- Génération **et** exposition réservées aux fiches **diffusibles** et hors liste
  d'exclusion (garde `assertAllowed` réutilisée + filtres SQL dans la requête KNN).
- Clé Mistral jamais journalisée ; erreurs client neutres.
- Le vecteur d'embedding n'est pas renvoyé au client.

## Tests (TDD, zéro réseau)

- **EmbeddingClient / binding** : le driver `fake` lie `FakeEmbeddingClient` ;
  `embed()` renvoie 1024 flottants déterministes.
- **MistralEmbeddingClient** : `Http::fake` — POST correct (Bearer, modèle,
  input), parse `data.0.embedding`, lève sur échec HTTP.
- **CompanyEmbedder / commande** : génère + stocke un vecteur ; ignore les
  non-diffusibles ; résilience (fiche exclue → skipped, panne → errors) ; trace
  `imports`.
- **SimilarProspects / endpoint** : on **insère des vecteurs connus** en base
  (littéraux `::vector`, sans passer par l'embedder) pour des établissements de
  SIREN différents, plus la fiche courante et une succursale du même SIREN, plus
  une fiche non-diffusible ; on assert l'**ordre cosinus**, l'**exclusion de soi
  et du même SIREN**, l'**exclusion RGPD**, le top-8, et le 422 « pas indexée »
  quand la fiche courante n'a pas d'embedding. Test déterministe sans réseau.

> Les tests nécessitent pgvector : le CI construit désormais le Postgres via le
> Dockerfile dérivé (ou installe `postgresql-16-pgvector` dans le service). À
> vérifier dans `.github/workflows/ci.yml`.

## Hors périmètre

- Ré-embedding automatique à la péremption (pour l'instant : re-run manuel de la
  commande sur les fiches enrichies depuis). Un indicateur de péremption pourra
  être ajouté plus tard, comme pour le résumé.
- Recherche sémantique en texte libre (« trouve-moi des entreprises comme
  “traiteur bio” ») — extension future, non demandée.
- Blocage géographique / sectoriel — écarté par décision produit (portée
  nationale).

## Process

Spec → `writing-plans` → `subagent-driven-development` (TDD, un subagent par
tâche + revue spec/qualité) → revue sécu + code-review → `finishing-a-development-branch`.
Branche : `feat/module-ia-bloc-b`.
