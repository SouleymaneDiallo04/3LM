<?php

use App\Models\Company;
use App\Models\Establishment;
use App\Models\Import;
use App\Services\Ai\Contracts\AiClient;
use App\Services\Ai\FakeAiClient;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RegionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, RegionSeeder::class, DepartmentSeeder::class]);
    // is_diffusible est porté par l'unité légale : une company diffusible, une non.
    $diffusible = Company::create([
        'siren' => '111111111', 'legal_name' => 'Dupont SAS',
        'normalized_name' => 'dupont', 'status' => 'active', 'is_diffusible' => true,
    ]);
    $nonDiffusible = Company::create([
        'siren' => '222222222', 'legal_name' => 'Martin EI',
        'normalized_name' => 'martin', 'status' => 'active', 'is_diffusible' => false,
    ]);
    Establishment::create([
        'siret' => '11111111100011', 'company_id' => $diffusible->id,
        'name' => 'A', 'normalized_name' => 'a', 'status' => 'active',
        'city' => 'BORDEAUX', 'department_code' => '33',
    ]);
    // Unité légale non-diffusible : ignorée par la commande.
    Establishment::create([
        'siret' => '22222222200011', 'company_id' => $nonDiffusible->id,
        'name' => 'B', 'normalized_name' => 'b', 'status' => 'active',
        'city' => 'BORDEAUX', 'department_code' => '33',
    ]);
});

it('pré-génère les résumés diffusibles et trace l\'exécution', function (): void {
    app(FakeAiClient::class)->response = 'Résumé batch.';

    $this->artisan('fbde:ai:summarize', ['--department' => '33', '--limit' => 10])
        ->assertSuccessful();

    expect(Establishment::where('siret', '11111111100011')->value('ai_summary'))->toBe('Résumé batch.')
        ->and(Establishment::where('siret', '22222222200011')->value('ai_summary'))->toBeNull();

    $import = Import::latest('id')->firstOrFail();
    expect($import->source)->toBe('ai_summary')
        ->and($import->status)->toBe('completed')
        ->and((int) $import->stats['generated'])->toBe(1);
});

it('ignore sans échouer une fiche diffusible mais exclue (skipped)', function (): void {
    app(FakeAiClient::class)->response = 'Résumé batch.';
    // Fiche diffusible (passe le filtre SQL) mais en liste d'exclusion :
    // la garde RGPD la refuse dans la boucle → skipped, sans abandon du batch.
    $exclue = Company::create([
        'siren' => '333333333', 'legal_name' => 'Bernard SAS',
        'normalized_name' => 'bernard', 'status' => 'active', 'is_diffusible' => true,
    ]);
    Establishment::create([
        'siret' => '33333333300011', 'company_id' => $exclue->id,
        'name' => 'C', 'normalized_name' => 'c', 'status' => 'active',
        'city' => 'BORDEAUX', 'department_code' => '33',
    ]);
    DB::table('exclusion_list')->insert([
        'identifier_type' => 'siren', 'identifier_value' => '333333333',
        'reason' => 'opposition', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->artisan('fbde:ai:summarize', ['--department' => '33', '--limit' => 10])
        ->assertSuccessful();

    expect(Establishment::where('siret', '11111111100011')->value('ai_summary'))->toBe('Résumé batch.')
        ->and(Establishment::where('siret', '33333333300011')->value('ai_summary'))->toBeNull();

    $stats = Import::latest('id')->firstOrFail()->stats;
    expect((int) $stats['generated'])->toBe(1)
        ->and((int) $stats['skipped'])->toBe(1)
        ->and((int) $stats['errors'])->toBe(0);
});

it('compte une panne IA comme erreur sans interrompre le batch (errors)', function (): void {
    // Doublure qui lève : chaque fiche diffusible échoue individuellement,
    // mais la commande doit se terminer avec succès (résilience).
    $this->app->bind(AiClient::class, function () {
        return new class implements AiClient
        {
            public function chat(array $messages, array $options = []): string
            {
                throw new RuntimeException('API down');
            }
        };
    });

    $this->artisan('fbde:ai:summarize', ['--department' => '33', '--limit' => 10])
        ->assertSuccessful();

    expect(Establishment::where('siret', '11111111100011')->value('ai_summary'))->toBeNull();

    $import = Import::latest('id')->firstOrFail();
    expect($import->status)->toBe('completed')
        ->and((int) $import->stats['generated'])->toBe(0)
        ->and((int) $import->stats['errors'])->toBe(1);
});
