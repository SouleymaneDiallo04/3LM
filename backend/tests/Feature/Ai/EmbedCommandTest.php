<?php

use App\Models\Company;
use App\Models\Establishment;
use App\Models\Import;
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
