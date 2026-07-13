# Module IA — Bloc B (prospects similaires EF-08.3) — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development pour exécuter ce plan tâche par tâche. Les étapes utilisent des cases à cocher (`- [ ]`).

**Goal :** Sur la fiche d'un établissement, proposer les 8 prospects les plus similaires (embeddings Mistral + pgvector), au niveau national, en excluant la fiche et son propre SIREN, sous garde RGPD.

**Architecture :** Contrat `EmbeddingClient` substituable (Mistral réel + doublure déterministe), colonne `vector(1024)` + index HNSW sur `establishments`, requête KNN cosinus en SQL brut, garde RGPD partagée (`ProspectGuard`), endpoint `GET /companies/{id}/similar`, panneau frontend sur la fiche. Backfill par commande batch.

**Tech Stack :** Laravel 13 / PHP 8.4, PostgreSQL 16 + PostGIS + **pgvector**, React 19 / Tailwind v4, Mistral `mistral-embed` (1024 dims).

## Global Constraints

- **RGPD** : JAMAIS de SIRET/SIREN dans le texte embeddé (allow-list partagée avec le résumé). Génération ET exposition refusées pour une fiche non-diffusible (`companies.is_diffusible === false`) ou en `exclusion_list`.
- **`is_diffusible` est sur la table `companies`** (unité légale), pas `establishments`.
- **Zéro appel réseau dans les tests** : driver `fake` lié via `FBDE_AI_DRIVER` (déjà forcé dans `phpunit.xml`).
- **Dimension = 1024** (mistral-embed, validé).
- Clé Mistral jamais journalisée ; erreurs client neutres (503 générique).
- Commits en français, **sans** trailer `Co-Authored-By`.
- Le vecteur d'embedding n'est jamais renvoyé au client ni exposé par `EstablishmentResource`.

---

## File Structure

- `docker/postgres/Dockerfile` — image Postgres dérivée (postgis + pgvector).
- `docker-compose.yml` — service `postgres` en `build:`.
- `backend/database/migrations/*_enable_pgvector.php` — extension `vector`.
- `backend/database/migrations/*_add_embedding_to_establishments.php` — colonne + index HNSW.
- `backend/app/Services/Ai/Contracts/EmbeddingClient.php` — contrat.
- `backend/app/Services/Ai/FakeEmbeddingClient.php` — doublure déterministe.
- `backend/app/Services/Ai/MistralEmbeddingClient.php` — client réel.
- `backend/app/Services/Ai/ProspectGuard.php` — garde RGPD partagée (extraite de CompanySummarizer).
- `backend/app/Services/Ai/CompanyEmbedder.php` — génère + stocke le vecteur.
- `backend/app/Services/Ai/SimilarProspects.php` — requête KNN.
- `backend/app/Console/Commands/EmbedCompaniesCommand.php` — backfill.
- `backend/app/Http/Controllers/Api/V1/AiController.php` — méthode `similar()`.
- `backend/config/fbde.php`, `backend/app/Providers/AiServiceProvider.php` — config + binding.
- `frontend/src/features/ai/api.ts`, `SimilarProspectsPanel.tsx`, `EstablishmentPage.tsx`.
- `.github/workflows/ci.yml` — pgvector dans le Postgres du CI.

---

## Task 1 : Infrastructure pgvector (image Docker + extension)

**Files:** Create `docker/postgres/Dockerfile` ; Modify `docker-compose.yml` ; Create migration `enable_pgvector` ; Test `backend/tests/Feature/Ai/PgvectorTest.php`

**Interfaces — Produces:** extension `vector` disponible ; type `vector` et opérateur `<=>` utilisables.

- [ ] **Step 1 : Dockerfile dérivé**

```dockerfile
# docker/postgres/Dockerfile
FROM postgis/postgis:16-3.4
RUN apt-get update \
 && apt-get install -y --no-install-recommends postgresql-16-pgvector \
 && rm -rf /var/lib/apt/lists/*
```

- [ ] **Step 2 : docker-compose — construire l'image**

Dans `docker-compose.yml`, service `postgres`, remplacer la ligne `image: postgis/postgis:16-3.4` par :

```yaml
    build:
      context: ./docker/postgres
    image: fbde-postgres:16
```

(Conserver `container_name`, `environment`, `volumes`, `ports`, `healthcheck` tels quels.)

- [ ] **Step 3 : Rebuild + recreate du conteneur**

Run :
```bash
docker compose build postgres
docker compose up -d postgres
```
Expected : conteneur `fbde_postgres` reparti sur l'image dérivée.

- [ ] **Step 4 : Migration d'activation**

```php
<?php
// backend/database/migrations/2026_07_13_000001_enable_pgvector.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
    }

    public function down(): void
    {
        DB::statement('DROP EXTENSION IF EXISTS vector');
    }
};
```

- [ ] **Step 5 : Test que pgvector fonctionne (RED puis GREEN)**

```php
<?php
// backend/tests/Feature/Ai/PgvectorTest.php
use Illuminate\Support\Facades\DB;

it('a l\'extension pgvector et calcule une distance cosinus', function (): void {
    $row = DB::selectOne("SELECT ('[1,0,0]'::vector(3) <=> '[1,0,0]'::vector(3)) AS same,
                                 ('[1,0,0]'::vector(3) <=> '[0,1,0]'::vector(3)) AS diff");
    expect((float) $row->same)->toBe(0.0)
        ->and((float) $row->diff)->toBeGreaterThan(0.9);
});
```

Run : `docker compose exec -T app php artisan migrate && docker compose exec -T app php artisan test tests/Feature/Ai/PgvectorTest.php`
Expected : PASS.

- [ ] **Step 6 : Commit**

```bash
git add docker/postgres/Dockerfile docker-compose.yml backend/database/migrations/2026_07_13_000001_enable_pgvector.php backend/tests/Feature/Ai/PgvectorTest.php
git commit -m "Bloc B : image Postgres dérivée avec pgvector + activation de l'extension"
```

---

## Task 2 : Contrat EmbeddingClient + doublure + binding

**Files:** Create `EmbeddingClient.php`, `FakeEmbeddingClient.php` ; Modify `config/fbde.php`, `AiServiceProvider.php` ; Test `EmbeddingBindingTest.php`

**Interfaces — Produces:** `EmbeddingClient::embed(string $text): array` (1024 floats) ; binding `fake`→`FakeEmbeddingClient`.
**Consumes:** `FBDE_AI_DRIVER`, `services.mistral.key` (Task Bloc A).

- [ ] **Step 1 : Config**

Dans `backend/config/fbde.php`, bloc `ai`, ajouter :

```php
        'embedding_model' => env('FBDE_AI_EMBEDDING_MODEL', 'mistral-embed'),
        'embedding_endpoint' => env('FBDE_AI_EMBEDDING_ENDPOINT', 'https://api.mistral.ai/v1/embeddings'),
        'embedding_dimensions' => 1024,
```

- [ ] **Step 2 : Contrat**

```php
<?php
// backend/app/Services/Ai/Contracts/EmbeddingClient.php
namespace App\Services\Ai\Contracts;

interface EmbeddingClient
{
    /** @return list<float> vecteur de dimension fixe (1024 pour mistral-embed) */
    public function embed(string $text): array;
}
```

- [ ] **Step 3 : Test doublure + binding (RED)**

```php
<?php
// backend/tests/Feature/Ai/EmbeddingBindingTest.php
use App\Services\Ai\Contracts\EmbeddingClient;
use App\Services\Ai\FakeEmbeddingClient;

it('lie la doublure d\'embedding en environnement de test', function (): void {
    expect(app(EmbeddingClient::class))->toBeInstanceOf(FakeEmbeddingClient::class);
});

it('produit un vecteur déterministe de 1024 flottants normalisés', function (): void {
    $client = new FakeEmbeddingClient();
    $a = $client->embed('boulangerie bordeaux');
    $b = $client->embed('boulangerie bordeaux');
    $c = $client->embed('garage lyon');

    expect($a)->toHaveCount(1024)
        ->and($a)->toBe($b)          // déterministe
        ->and($a)->not->toBe($c);    // dépend du texte

    $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $a)));
    expect($norm)->toBeGreaterThan(0.99)->toBeLessThan(1.01); // normalisé
});
```

Run : `docker compose exec -T app php artisan test tests/Feature/Ai/EmbeddingBindingTest.php` → FAIL (classes manquantes).

- [ ] **Step 4 : Doublure déterministe**

```php
<?php
// backend/app/Services/Ai/FakeEmbeddingClient.php
namespace App\Services\Ai;

use App\Services\Ai\Contracts\EmbeddingClient;

/** Doublure déterministe (tests/offline) : vecteur unitaire dérivé du texte. */
class FakeEmbeddingClient implements EmbeddingClient
{
    public function embed(string $text): array
    {
        mt_srand(crc32($text));
        $v = [];
        for ($i = 0; $i < 1024; $i++) {
            $v[] = mt_rand(-1000, 1000) / 1000;
        }
        mt_srand();
        $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $v))) ?: 1.0;

        return array_map(fn ($x) => $x / $norm, $v);
    }
}
```

- [ ] **Step 5 : Binding**

Dans `backend/app/Providers/AiServiceProvider.php`, ajouter le binding `EmbeddingClient` en miroir de `AiClient` : en mode `fake` → singleton `FakeEmbeddingClient` ; en mode `mistral` → `MistralEmbeddingClient` (créé Task 3). Exemple :

```php
$this->app->singleton(\App\Services\Ai\Contracts\EmbeddingClient::class, function () {
    return config('fbde.ai.driver') === 'mistral'
        ? new \App\Services\Ai\MistralEmbeddingClient()
        : new \App\Services\Ai\FakeEmbeddingClient();
});
```

> Si `MistralEmbeddingClient` n'existe pas encore au moment du binding (Task 3 après), garder la branche `mistral` mais l'implémenter en Task 3 ; en test le driver est `fake`, donc la branche `mistral` n'est pas évaluée.

- [ ] **Step 6 : Lancer — PASS**, puis **Commit**

```bash
docker compose exec -T app vendor/bin/pint
git add backend/config/fbde.php backend/app/Services/Ai/Contracts/EmbeddingClient.php backend/app/Services/Ai/FakeEmbeddingClient.php backend/app/Providers/AiServiceProvider.php backend/tests/Feature/Ai/EmbeddingBindingTest.php
git commit -m "Bloc B : contrat EmbeddingClient + doublure déterministe + binding par config"
```

---

## Task 3 : MistralEmbeddingClient

**Files:** Create `MistralEmbeddingClient.php` ; Test `MistralEmbeddingClientTest.php`
**Consumes:** `services.mistral.key`, `fbde.ai.embedding_endpoint/model`.

- [ ] **Step 1 : Test (RED) avec Http::fake**

```php
<?php
// backend/tests/Feature/Ai/MistralEmbeddingClientTest.php
use App\Services\Ai\MistralEmbeddingClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

it('appelle l\'API embeddings et renvoie le vecteur', function (): void {
    config(['services.mistral.key' => 'k-test', 'fbde.ai.embedding_endpoint' => 'https://api.mistral.ai/v1/embeddings', 'fbde.ai.embedding_model' => 'mistral-embed']);
    Http::fake(['api.mistral.ai/*' => Http::response(['data' => [['embedding' => [0.1, 0.2, 0.3]]]])]);

    $vec = (new MistralEmbeddingClient())->embed('boulangerie');

    expect($vec)->toBe([0.1, 0.2, 0.3]);
    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer k-test')
        && $r['model'] === 'mistral-embed'
        && $r['input'] === ['boulangerie']);
});

it('lève sur échec HTTP', function (): void {
    config(['services.mistral.key' => 'k-test']);
    Http::fake(['api.mistral.ai/*' => Http::response('nope', 500)]);
    expect(fn () => (new MistralEmbeddingClient())->embed('x'))->toThrow(RequestException::class);
});
```

Run → FAIL (classe manquante).

- [ ] **Step 2 : Implémentation**

```php
<?php
// backend/app/Services/Ai/MistralEmbeddingClient.php
namespace App\Services\Ai;

use App\Services\Ai\Contracts\EmbeddingClient;
use Illuminate\Support\Facades\Http;

/** Embeddings Mistral (mistral-embed) — HTTP JSON, sans dépendance externe. */
class MistralEmbeddingClient implements EmbeddingClient
{
    public function embed(string $text): array
    {
        return Http::withToken((string) config('services.mistral.key'))
            ->timeout(30)
            ->post((string) config('fbde.ai.embedding_endpoint'), [
                'model' => config('fbde.ai.embedding_model'),
                'input' => [$text],
            ])
            ->throw()
            ->json('data.0.embedding');
    }
}
```

- [ ] **Step 3 : PASS + Commit**

```bash
docker compose exec -T app vendor/bin/pint
git add backend/app/Services/Ai/MistralEmbeddingClient.php backend/tests/Feature/Ai/MistralEmbeddingClientTest.php
git commit -m "Bloc B : MistralEmbeddingClient (mistral-embed, lève sur échec)"
```

---

## Task 4 : Schéma — colonne embedding + index HNSW

**Files:** Create migration `add_embedding_to_establishments` ; Test `EmbeddingColumnTest.php`
**Interfaces — Produces:** colonne `establishments.embedding vector(1024)`, `embedded_at`, index HNSW cosinus.

- [ ] **Step 1 : Migration**

```php
<?php
// backend/database/migrations/2026_07_13_000002_add_embedding_to_establishments.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('establishments', function (Blueprint $table): void {
            $table->timestampTz('embedded_at')->nullable();
        });
        // Type pgvector : hors Blueprint natif → SQL brut.
        DB::statement('ALTER TABLE establishments ADD COLUMN embedding vector(1024)');
        DB::statement('CREATE INDEX establishments_embedding_hnsw ON establishments USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS establishments_embedding_hnsw');
        DB::statement('ALTER TABLE establishments DROP COLUMN IF EXISTS embedding');
        Schema::table('establishments', function (Blueprint $table): void {
            $table->dropColumn('embedded_at');
        });
    }
};
```

- [ ] **Step 2 : Test (RED puis GREEN)**

```php
<?php
// backend/tests/Feature/Ai/EmbeddingColumnTest.php
use App\Models\Company;
use App\Models\Establishment;
use Illuminate\Support\Facades\DB;

it('stocke et relit un vecteur d\'embedding', function (): void {
    $c = Company::create(['siren' => '111111111', 'legal_name' => 'X', 'normalized_name' => 'x', 'status' => 'active']);
    $e = Establishment::create(['siret' => '11111111100011', 'company_id' => $c->id, 'name' => 'X', 'normalized_name' => 'x', 'status' => 'active']);

    $vec = '[' . implode(',', array_fill(0, 1024, 0.01)) . ']';
    DB::update('UPDATE establishments SET embedding = ?::vector, embedded_at = now() WHERE id = ?', [$vec, $e->id]);

    $row = DB::selectOne('SELECT embedded_at, embedding IS NOT NULL AS has_vec FROM establishments WHERE id = ?', [$e->id]);
    expect($row->has_vec)->toBeTrue()->and($row->embedded_at)->not->toBeNull();
});
```

Run : `docker compose exec -T app php artisan migrate && docker compose exec -T app php artisan test tests/Feature/Ai/EmbeddingColumnTest.php` → PASS.

- [ ] **Step 3 : Commit**

```bash
git add backend/database/migrations/2026_07_13_000002_add_embedding_to_establishments.php backend/tests/Feature/Ai/EmbeddingColumnTest.php
git commit -m "Bloc B : colonne embedding vector(1024) + index HNSW cosinus"
```

---

## Task 5 : ProspectGuard partagée + Prompts::embeddingText + CompanyEmbedder

**Files:** Create `ProspectGuard.php`, `CompanyEmbedder.php` ; Modify `Prompts.php`, `CompanySummarizer.php` ; Test `EmbedderTest.php`
**Interfaces:**
- Consumes: `EmbeddingClient`, `Prompts`, `AiGenerationDenied`.
- Produces: `ProspectGuard::assertAllowed(Establishment): void` ; `Prompts::embeddingText(Establishment): string` ; `CompanyEmbedder::embed(Establishment): void` (génère + stocke).

- [ ] **Step 1 : Extraire la garde RGPD partagée**

Créer `backend/app/Services/Ai/ProspectGuard.php` avec la logique **exacte** de `CompanySummarizer::assertAllowed` (non-diffusible + exclusion_list) :

```php
<?php
// backend/app/Services/Ai/ProspectGuard.php
namespace App\Services\Ai;

use App\Models\Establishment;
use App\Services\Ai\Exceptions\AiGenerationDenied;
use Illuminate\Support\Facades\DB;

/** Garde RGPD partagée (résumé, argumentaire, embedding, similarité). */
class ProspectGuard
{
    public function assertAllowed(Establishment $establishment): void
    {
        if ($establishment->company?->is_diffusible === false) {
            throw new AiGenerationDenied('Fiche non-diffusible : génération IA non autorisée.');
        }

        $siren = $establishment->company?->siren;
        $excluded = DB::table('exclusion_list')
            ->where(fn ($q) => $q->where('identifier_type', 'siret')
                ->where('identifier_value', $establishment->siret))
            ->when($siren, fn ($q) => $q->orWhere(fn ($s) => $s
                ->where('identifier_type', 'siren')->where('identifier_value', $siren)))
            ->exists();

        if ($excluded) {
            throw new AiGenerationDenied('Fiche en liste d\'exclusion : génération IA non autorisée.');
        }
    }
}
```

Puis refactorer `CompanySummarizer::assertAllowed` pour **déléguer** à `ProspectGuard` (injecter `ProspectGuard` dans le constructeur, `assertAllowed` appelle `$this->guard->assertAllowed($e)`). Les tests existants du Bloc A doivent rester verts.

- [ ] **Step 2 : Prompts::embeddingText (réutilise la minimisation)**

Dans `backend/app/Services/Ai/Prompts.php`, rendre la minimisation réutilisable et ajouter :

```php
    /** Texte à embedder : mêmes données minimisées RGPD que le résumé. */
    public function embeddingText(Establishment $e): string
    {
        $text = $this->minimizedFacts($e);
        if (($desc = $this->sanitizeUntrusted($e->description)) !== null) {
            $text .= "\n" . $desc;
        }

        return $text;
    }
```

(`minimizedFacts` et `sanitizeUntrusted` existent déjà, privées — les garder telles quelles, `embeddingText` est dans la même classe.)

- [ ] **Step 3 : Test CompanyEmbedder (RED)**

```php
<?php
// backend/tests/Feature/Ai/EmbedderTest.php
use App\Models\Company;
use App\Models\Establishment;
use App\Services\Ai\CompanyEmbedder;
use App\Services\Ai\Exceptions\AiGenerationDenied;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->c = Company::create(['siren' => '111111111', 'legal_name' => 'Dupont', 'normalized_name' => 'dupont', 'status' => 'active']);
    $this->e = Establishment::create(['siret' => '11111111100011', 'company_id' => $this->c->id, 'name' => 'Boulangerie Dupont', 'normalized_name' => 'boulangerie dupont', 'status' => 'active', 'naf_code' => '10.71C', 'city' => 'BORDEAUX']);
});

it('génère et stocke un embedding', function (): void {
    app(CompanyEmbedder::class)->embed($this->e);
    $row = DB::selectOne('SELECT embedded_at, embedding IS NOT NULL AS has FROM establishments WHERE id = ?', [$this->e->id]);
    expect($row->has)->toBeTrue()->and($row->embedded_at)->not->toBeNull();
});

it('refuse d\'embedder une fiche non-diffusible (RGPD)', function (): void {
    $this->e->company->update(['is_diffusible' => false]);
    expect(fn () => app(CompanyEmbedder::class)->embed($this->e))->toThrow(AiGenerationDenied::class);
});
```

Run → FAIL.

- [ ] **Step 4 : CompanyEmbedder**

```php
<?php
// backend/app/Services/Ai/CompanyEmbedder.php
namespace App\Services\Ai;

use App\Models\Establishment;
use App\Services\Ai\Contracts\EmbeddingClient;
use Illuminate\Support\Facades\DB;

class CompanyEmbedder
{
    public function __construct(
        private readonly EmbeddingClient $client,
        private readonly Prompts $prompts,
        private readonly ProspectGuard $guard,
    ) {}

    /** Génère l'embedding (garde RGPD) et le stocke en base. */
    public function embed(Establishment $establishment): void
    {
        $this->guard->assertAllowed($establishment);

        $vector = $this->client->embed($this->prompts->embeddingText($establishment));
        $literal = '[' . implode(',', $vector) . ']';

        DB::update(
            'UPDATE establishments SET embedding = ?::vector, embedded_at = now() WHERE id = ?',
            [$literal, $establishment->id],
        );
    }
}
```

- [ ] **Step 5 : Lancer toute la suite Ai (PASS, y compris Bloc A) + Commit**

Run : `docker compose exec -T app php artisan test tests/Feature/Ai` → tout vert.
```bash
docker compose exec -T app vendor/bin/pint
git add backend/app/Services/Ai/ProspectGuard.php backend/app/Services/Ai/CompanyEmbedder.php backend/app/Services/Ai/Prompts.php backend/app/Services/Ai/CompanySummarizer.php backend/tests/Feature/Ai/EmbedderTest.php
git commit -m "Bloc B : garde RGPD partagée (ProspectGuard) + embeddingText + CompanyEmbedder"
```

---

## Task 6 : SimilarProspects + endpoint GET /companies/{id}/similar

**Files:** Create `SimilarProspects.php` ; Modify `AiController.php`, `routes/api.php` ; Test `SimilarTest.php`
**Interfaces:**
- Consumes: `ProspectGuard`, `ScoringService::tier`.
- Produces: `SimilarProspects::for(Establishment, int): array` ; endpoint `POST` non — **GET** `/companies/{id}/similar`.

- [ ] **Step 1 : Test (RED)**

```php
<?php
// backend/tests/Feature/Ai/SimilarTest.php
use App\Models\Company;
use App\Models\Establishment;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RegionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

function seedVec(Establishment $e, array $vec): void {
    DB::update('UPDATE establishments SET embedding = ?::vector, embedded_at = now() WHERE id = ?',
        ['[' . implode(',', array_pad($vec, 1024, 0)) . ']', $e->id]);
}

function mkEstab(string $siren, string $siret, bool $diffusible = true): Establishment {
    $c = Company::create(['siren' => $siren, 'legal_name' => $siren, 'normalized_name' => $siren, 'status' => 'active', 'is_diffusible' => $diffusible]);
    return Establishment::create(['siret' => $siret, 'company_id' => $c->id, 'name' => $siret, 'normalized_name' => $siret, 'status' => 'active', 'naf_code' => '10.71C', 'city' => 'BORDEAUX']);
}

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, RegionSeeder::class, DepartmentSeeder::class]);
    $this->user = User::factory()->create()->syncRoles('commercial');
    // Fiche de référence : vecteur [1,0,0,...]
    $this->ref = mkEstab('100000001', '10000000100011');
    seedVec($this->ref, [1, 0, 0]);
    // Proche (autre SIREN) : [0.9,0.1,0]
    $this->near = mkEstab('200000002', '20000000200022');
    seedVec($this->near, [0.9, 0.1, 0]);
    // Lointaine (autre SIREN) : [0,1,0]
    $this->far = mkEstab('300000003', '30000000300033');
    seedVec($this->far, [0, 1, 0]);
});

it('renvoie les prospects similaires classés, sans soi ni le même SIREN', function (): void {
    // Succursale du même SIREN que la référence, très proche → doit être exclue.
    $sibling = Establishment::create(['siret' => '10000000100029', 'company_id' => $this->ref->company_id, 'name' => 'sib', 'normalized_name' => 'sib', 'status' => 'active', 'city' => 'BORDEAUX']);
    seedVec($sibling, [1, 0, 0]);
    $id = $this->ref->id;

    $data = $this->actingAs($this->user)->getJson("/api/v1/companies/{$id}/similar")->json('data');

    $sirets = array_column($data, 'siret');
    expect($sirets)->toContain('20000000200022')          // proche présent
        ->and($sirets)->not->toContain('10000000100011')  // pas soi
        ->and($sirets)->not->toContain('10000000100029')  // pas le même SIREN
        ->and($sirets[0])->toBe('20000000200022');         // proche avant lointaine
});

it('exclut les fiches non-diffusibles des résultats (RGPD)', function (): void {
    $hidden = mkEstab('400000004', '40000000400044', diffusible: false);
    seedVec($hidden, [0.95, 0.05, 0]); // plus proche que near, mais non-diffusible
    $id = $this->ref->id;

    $sirets = array_column($this->actingAs($this->user)->getJson("/api/v1/companies/{$id}/similar")->json('data'), 'siret');
    expect($sirets)->not->toContain('40000000400044');
});

it('renvoie 422 si la fiche courante n\'a pas d\'embedding', function (): void {
    $bare = mkEstab('500000005', '50000000500055'); // pas de seedVec
    $this->actingAs($this->user)->getJson("/api/v1/companies/{$bare->id}/similar")->assertStatus(422);
});

it('refuse pour une fiche courante non-diffusible (RGPD)', function (): void {
    $this->ref->company->update(['is_diffusible' => false]);
    $this->actingAs($this->user)->getJson("/api/v1/companies/{$this->ref->id}/similar")->assertStatus(422);
});
```

Run → FAIL.

- [ ] **Step 2 : Service SimilarProspects**

```php
<?php
// backend/app/Services/Ai/SimilarProspects.php
namespace App\Services\Ai;

use App\Models\Establishment;
use App\Services\Ai\Exceptions\AiGenerationDenied;
use App\Services\Scoring\ScoringService;
use Illuminate\Support\Facades\DB;

class SimilarProspects
{
    public function __construct(
        private readonly ProspectGuard $guard,
        private readonly ScoringService $scoring,
    ) {}

    /**
     * Plus-proches-voisins cosinus. Lève AiGenerationDenied si la fiche est
     * non-diffusible ; lève NotIndexed si elle n'a pas encore d'embedding.
     *
     * @return list<array{id:int,siret:string,name:?string,city:?string,naf_code:?string,commercial_tier:?string,proximity:float}>
     */
    public function for(Establishment $establishment, int $limit = 8): array
    {
        $this->guard->assertAllowed($establishment);

        $self = DB::selectOne('SELECT embedding::text AS vec FROM establishments WHERE id = ?', [$establishment->id]);
        if ($self === null || $self->vec === null) {
            throw new Exceptions\NotIndexed('Fiche pas encore indexée pour la similarité.');
        }

        $rows = DB::select(
            'SELECT e.id, e.siret, e.name, e.city, e.naf_code, e.commercial_score,
                    (e.embedding <=> ?::vector) AS distance
             FROM establishments e
             JOIN companies c ON c.id = e.company_id
             WHERE e.embedding IS NOT NULL
               AND e.id <> ?
               AND e.company_id <> ?
               AND c.is_diffusible = true
               AND NOT EXISTS (
                 SELECT 1 FROM exclusion_list x
                 WHERE (x.identifier_type = \'siret\' AND x.identifier_value = e.siret)
                    OR (x.identifier_type = \'siren\' AND x.identifier_value = c.siren))
             ORDER BY e.embedding <=> ?::vector
             LIMIT ?',
            [$self->vec, $establishment->id, $establishment->company_id, $self->vec, $limit],
        );

        return array_map(fn ($r) => [
            'id' => (int) $r->id,
            'siret' => $r->siret,
            'name' => $r->name,
            'city' => $r->city,
            'naf_code' => $r->naf_code,
            'commercial_tier' => $this->scoring->tier($r->commercial_score),
            // distance cosinus 0..2 → proximité 1..0
            'proximity' => round(1 - ((float) $r->distance) / 2, 3),
        ], $rows);
    }
}
```

Créer aussi `backend/app/Services/Ai/Exceptions/NotIndexed.php` :

```php
<?php
namespace App\Services\Ai\Exceptions;

use RuntimeException;

/** La fiche n'a pas encore d'embedding : la similarité est indisponible. */
class NotIndexed extends RuntimeException {}
```

- [ ] **Step 3 : Endpoint dans AiController**

Ajouter les imports `use App\Services\Ai\SimilarProspects;` et `use App\Services\Ai\Exceptions\NotIndexed;`, puis :

```php
    public function similar(Establishment $establishment, SimilarProspects $service): JsonResponse
    {
        try {
            $data = $service->for($establishment);
        } catch (AiGenerationDenied $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (NotIndexed $e) {
            return response()->json(['message' => $e->getMessage(), 'not_indexed' => true], 422);
        }

        return response()->json(['data' => $data]);
    }
```

- [ ] **Step 4 : Route**

Dans `backend/routes/api.php`, à côté de `companies.summary` :

```php
            Route::get('companies/{establishment}/similar', [AiController::class, 'similar'])
                ->name('companies.similar');
```

- [ ] **Step 5 : PASS + Commit**

```bash
docker compose exec -T app vendor/bin/pint
git add backend/app/Services/Ai/SimilarProspects.php backend/app/Services/Ai/Exceptions/NotIndexed.php backend/app/Http/Controllers/Api/V1/AiController.php backend/routes/api.php backend/tests/Feature/Ai/SimilarTest.php
git commit -m "Bloc B : similarité KNN cosinus + endpoint similar (exclut soi/même SIREN, filtre RGPD, 422 si pas indexée)"
```

---

## Task 7 : Commande batch fbde:ai:embed

**Files:** Create `EmbedCompaniesCommand.php` ; Test `EmbedCommandTest.php`
**Consumes:** `CompanyEmbedder`.

- [ ] **Step 1 : Test (RED)** — jumelle de `SummarizeCommandTest` : deux companies (une diffusible, une non), la commande n'embedde que la diffusible, trace dans `imports` (source `ai_embedding`, stats generated=1).

```php
<?php
// backend/tests/Feature/Ai/EmbedCommandTest.php
use App\Models\Company;
use App\Models\Establishment;
use App\Models\Import;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $d = Company::create(['siren' => '111111111', 'legal_name' => 'D', 'normalized_name' => 'd', 'status' => 'active', 'is_diffusible' => true]);
    $n = Company::create(['siren' => '222222222', 'legal_name' => 'N', 'normalized_name' => 'n', 'status' => 'active', 'is_diffusible' => false]);
    $this->a = Establishment::create(['siret' => '11111111100011', 'company_id' => $d->id, 'name' => 'A', 'normalized_name' => 'a', 'status' => 'active', 'city' => 'BORDEAUX', 'department_code' => '33']);
    Establishment::create(['siret' => '22222222200011', 'company_id' => $n->id, 'name' => 'B', 'normalized_name' => 'b', 'status' => 'active', 'city' => 'BORDEAUX', 'department_code' => '33']);
});

it('embedde les diffusibles et trace l\'exécution', function (): void {
    $this->artisan('fbde:ai:embed', ['--department' => '33', '--limit' => 10])->assertSuccessful();

    $ra = DB::selectOne('SELECT embedding IS NOT NULL AS has FROM establishments WHERE siret = ?', ['11111111100011']);
    $rb = DB::selectOne('SELECT embedding IS NOT NULL AS has FROM establishments WHERE siret = ?', ['22222222200011']);
    expect($ra->has)->toBeTrue()->and($rb->has)->toBeFalse();

    $import = Import::latest('id')->firstOrFail();
    expect($import->source)->toBe('ai_embedding')
        ->and($import->status)->toBe('completed')
        ->and((int) $import->stats['generated'])->toBe(1);
});
```

- [ ] **Step 2 : Commande** (copier la structure de `SummarizeCompaniesCommand`, source `ai_embedding`, appelle `$embedder->embed($e)` au lieu de stocker un résumé) :

```php
<?php
// backend/app/Console/Commands/EmbedCompaniesCommand.php
namespace App\Console\Commands;

use App\Models\Establishment;
use App\Models\Import;
use App\Services\Ai\CompanyEmbedder;
use App\Services\Ai\Exceptions\AiGenerationDenied;
use Illuminate\Console\Command;
use Throwable;

/** Pré-génération des embeddings (EF-08.3) en masse — tracée, résiliente. */
class EmbedCompaniesCommand extends Command
{
    protected $signature = 'fbde:ai:embed
        {--department= : Limiter à un département}
        {--limit=100 : Nombre maximal de fiches}';

    protected $description = 'Génère les embeddings des établissements diffusibles non indexés';

    public function handle(CompanyEmbedder $embedder): int
    {
        $import = Import::create(['source' => 'ai_embedding', 'status' => 'running', 'started_at' => now()]);
        $stats = ['generated' => 0, 'skipped' => 0, 'errors' => 0];

        try {
            Establishment::query()
                ->where('status', 'active')
                ->whereHas('company', fn ($q) => $q->where('is_diffusible', true))
                ->whereNull('embedding')
                ->when($this->option('department'), fn ($q, $d) => $q->where('department_code', $d))
                ->with('company')
                ->limit((int) $this->option('limit'))
                ->get()
                ->each(function (Establishment $e) use ($embedder, &$stats): void {
                    try {
                        $embedder->embed($e);
                        $stats['generated']++;
                    } catch (AiGenerationDenied) {
                        $stats['skipped']++;
                    } catch (Throwable) {
                        $stats['errors']++;
                    }
                });

            $this->table(['générés', 'ignorés', 'erreurs'], [[$stats['generated'], $stats['skipped'], $stats['errors']]]);
            $import->update(['status' => 'completed', 'finished_at' => now(), 'stats' => $stats]);
        } catch (Throwable $e) {
            $import->update(['status' => 'failed', 'finished_at' => now(), 'error' => $e->getMessage()]);
            throw $e;
        }

        return self::SUCCESS;
    }
}
```

> Note : `whereNull('embedding')` fonctionne (IS NULL) même si la colonne est de type vector.

- [ ] **Step 3 : PASS + Commit**

```bash
docker compose exec -T app vendor/bin/pint
git add backend/app/Console/Commands/EmbedCompaniesCommand.php backend/tests/Feature/Ai/EmbedCommandTest.php
git commit -m "Bloc B : commande batch fbde:ai:embed (diffusibles only, tracée, résiliente)"
```

---

## Task 8 : Frontend — panneau « Prospects similaires »

**Files:** Modify `frontend/src/features/ai/api.ts` ; Create `SimilarProspectsPanel.tsx` ; Modify `EstablishmentPage.tsx`

- [ ] **Step 1 : Client API**

Dans `frontend/src/features/ai/api.ts`, ajouter :

```ts
export interface SimilarProspect {
  id: number
  siret: string
  name: string | null
  city: string | null
  naf_code: string | null
  commercial_tier: 'A' | 'B' | 'C' | 'D' | null
  proximity: number
}

export async function getSimilar(id: number): Promise<SimilarProspect[]> {
  const { data } = await api.get(`/companies/${id}/similar`)
  return data.data
}
```

Et un helper d'erreur : si 422 avec `not_indexed`, distinguer « pas indexée » du refus RGPD (réutiliser/étendre `aiErrorMessage`).

- [ ] **Step 2 : Composant `SimilarProspectsPanel`**

`frontend/src/features/ai/SimilarProspectsPanel.tsx` : carte cohérente avec les autres cartes IA (`rounded-xl border border-slate-200 bg-white p-5 shadow-sm`), titre « Prospects similaires » + puce IA. Au montage, appelle `getSimilar(id)` ; skeleton pendant le chargement ; liste des prospects (nom = lien `/entreprises/{id}`, ville, libellé NAF humain, badge palier, barre de proximité en %). Gérer :
- **422 not_indexed** → état pédagogique : « Cette fiche n'est pas encore indexée. L'indexation se fait par lots (fbde:ai:embed). »
- **422 RGPD** → message RGPD serveur.
- **liste vide** (indexée mais aucun voisin) → « Aucun prospect similaire trouvé. »
Respecter `aria-live`, focus visibles.

- [ ] **Step 3 : Intégration fiche**

Dans `EstablishmentPage.tsx`, importer et placer `<SimilarProspectsPanel establishment={e} />` après `<PitchPanel>` (section pleine largeur `md:col-span-2`), avant « Collecte des données ».

- [ ] **Step 4 : Vérifier + QA**

Run : `cd frontend && npx tsc -b && npx vite build` (0 erreur).
QA navigateur (driver `mistral` avec la clé) : lancer `docker compose exec -T app php artisan fbde:ai:embed --department=33 --limit=200`, ouvrir une fiche indexée → vérifier la liste des similaires ; ouvrir une fiche non indexée → état pédagogique. Capturer.

- [ ] **Step 5 : Commit** (lock npm seulement si dépendance ajoutée — ici non)

```bash
git add frontend/src/features/ai/api.ts frontend/src/features/ai/SimilarProspectsPanel.tsx frontend/src/pages/EstablishmentPage.tsx
git commit -m "Bloc B frontend : panneau prospects similaires sur la fiche (états indexée/non-indexée/vide)"
```

---

## Task 9 : CI pgvector + revue sécu + code-review

**Files:** Modify `.github/workflows/ci.yml`

- [ ] **Step 1 : pgvector dans le CI**

Les service containers GitHub n'acceptent qu'une image publiée (pas de build local). Deux options — retenir la plus simple : **installer pgvector dans le conteneur service au runtime**, avant `php artisan migrate`. Ajouter une étape au job `backend`, après le démarrage des services :

```yaml
      - name: Installer pgvector dans le service Postgres
        run: |
          docker exec $(docker ps -qf "ancestor=postgis/postgis:16-3.4") sh -c \
            "apt-get update && apt-get install -y postgresql-16-pgvector"
```

> Vérifier le nom/ancestor réel du conteneur service dans le runner (adapter le filtre si besoin). Alternative : publier `fbde-postgres` sur ghcr et l'utiliser comme `image:` du service.

- [ ] **Step 2 : Suite complète verte**

Run : `docker compose exec -T app php artisan test` (toute la suite) + `cd frontend && npm run lint && npm run build`. Corriger jusqu'au vert.

- [ ] **Step 3 : Revue sécurité + code-review**

`scripts/review-package MERGE_BASE HEAD` → revue finale (RGPD embeddings, pas de SIRET/SIREN, filtres KNN, secret jamais journalisé, injection SQL du littéral vecteur — vérifier que `?::vector` est bien paramétré, pas de concaténation directe hors du littéral construit à partir de flottants).

- [ ] **Step 4 : Commit + push**

```bash
git add .github/workflows/ci.yml
git commit -m "Bloc B : CI installe pgvector dans le service Postgres"
git push -u origin feat/module-ia-bloc-b
```

---

## Auto-revue du plan

- **Couverture spec** : infra pgvector (T1), embeddings substituables (T2/T3), schéma (T4), garde+texte+génération (T5), similarité+API (T6), backfill (T7), frontend (T8), CI+revue (T9). ✅
- **Cohérence des types** : `EmbeddingClient::embed(): array` (1024 floats) constant ; `SimilarProspects::for(): array` de DTO ; `ProspectGuard::assertAllowed()` réutilisée par CompanySummarizer (refactor T5), CompanyEmbedder (T5), SimilarProspects (T6). ✅
- **Sécurité** : le littéral vecteur est construit uniquement à partir de flottants (`implode(',', $vector)`) puis passé en binding `?::vector` — pas d'injection ; les filtres RGPD sont dans la requête KNN et dans la garde. ✅
- **Risque connu** : disponibilité du paquet `postgresql-16-pgvector` dans l'image postgis (PGDG). Si absent, replier sur compilation depuis les sources dans le Dockerfile (documenté à l'exécution de T1).
