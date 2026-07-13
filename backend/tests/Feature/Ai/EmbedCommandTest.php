<?php

use App\Models\Company;
use App\Models\Establishment;
use App\Models\Import;
use App\Services\Ai\Contracts\EmbeddingClient;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RegionSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed([RegionSeeder::class, DepartmentSeeder::class]);
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

it('ignore sans échouer une fiche diffusible mais exclue (skipped)', function (): void {
    // Fiche diffusible (passe le filtre SQL) mais en liste d'exclusion :
    // la garde RGPD la refuse dans la boucle → skipped, sans abandon du batch.
    $exclue = Company::create(['siren' => '333333333', 'legal_name' => 'B', 'normalized_name' => 'b', 'status' => 'active', 'is_diffusible' => true]);
    Establishment::create(['siret' => '33333333300011', 'company_id' => $exclue->id, 'name' => 'C', 'normalized_name' => 'c', 'status' => 'active', 'city' => 'BORDEAUX', 'department_code' => '33']);
    DB::table('exclusion_list')->insert(['identifier_type' => 'siren', 'identifier_value' => '333333333', 'reason' => 'opposition', 'created_at' => now(), 'updated_at' => now()]);

    $this->artisan('fbde:ai:embed', ['--department' => '33', '--limit' => 10])->assertSuccessful();

    expect(DB::selectOne('SELECT embedding IS NOT NULL AS has FROM establishments WHERE siret = ?', ['33333333300011'])->has)->toBeFalse();
    $stats = Import::latest('id')->firstOrFail()->stats;
    expect((int) $stats['generated'])->toBe(1)
        ->and((int) $stats['skipped'])->toBe(1)
        ->and((int) $stats['errors'])->toBe(0);
});

it('compte une panne d\'embedding comme erreur sans interrompre le batch (errors)', function (): void {
    // Doublure qui lève : chaque fiche diffusible échoue individuellement,
    // mais la commande doit se terminer avec succès (résilience).
    $this->app->bind(EmbeddingClient::class, function () {
        return new class implements EmbeddingClient
        {
            public function embed(string $text): array
            {
                throw new RuntimeException('API down');
            }
        };
    });

    $this->artisan('fbde:ai:embed', ['--department' => '33', '--limit' => 10])->assertSuccessful();

    expect(DB::selectOne('SELECT embedding IS NOT NULL AS has FROM establishments WHERE siret = ?', ['11111111100011'])->has)->toBeFalse();
    $import = Import::latest('id')->firstOrFail();
    expect($import->status)->toBe('completed')
        ->and((int) $import->stats['generated'])->toBe(0)
        ->and((int) $import->stats['errors'])->toBe(1);
});
