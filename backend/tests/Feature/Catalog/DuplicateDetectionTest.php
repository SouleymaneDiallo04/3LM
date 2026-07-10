<?php

use App\Models\Company;
use App\Models\Establishment;
use App\Models\Import;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RegionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, RegionSeeder::class, DepartmentSeeder::class]);

    $companyA = Company::create([
        'siren' => '111111111', 'legal_name' => 'Dupont SAS',
        'normalized_name' => 'dupont', 'status' => 'active',
    ]);
    $companyB = Company::create([
        'siren' => '222222222', 'legal_name' => 'Dupont Frères',
        'normalized_name' => 'dupont freres', 'status' => 'active',
    ]);

    // Deux SIREN différents, quasi le même nom, la même ville : suspect.
    $this->first = Establishment::create([
        'siret' => '11111111100011', 'company_id' => $companyA->id,
        'name' => 'Boulangerie Dupont', 'normalized_name' => 'boulangerie dupont',
        'status' => 'active', 'city' => 'BORDEAUX', 'postal_code' => '33000', 'department_code' => '33',
    ]);
    $this->second = Establishment::create([
        'siret' => '22222222200011', 'company_id' => $companyB->id,
        'name' => 'Boulangerie Dupont B', 'normalized_name' => 'boulangerie dupont b',
        'status' => 'active', 'city' => 'BORDEAUX', 'postal_code' => '33000', 'department_code' => '33',
    ]);

    // Même SIREN : multi-établissements LÉGITIME, jamais signalé entre eux —
    // mais candidat face aux enseignes similaires d'un AUTRE SIREN.
    $this->sibling = Establishment::create([
        'siret' => '11111111100029', 'company_id' => $companyA->id,
        'name' => 'Boulangerie Dupont', 'normalized_name' => 'boulangerie dupont',
        'status' => 'active', 'city' => 'BORDEAUX', 'postal_code' => '33000', 'department_code' => '33',
    ]);

    // Ville différente : pas signalé.
    Establishment::create([
        'siret' => '22222222200029', 'company_id' => $companyB->id,
        'name' => 'Boulangerie Dupont', 'normalized_name' => 'boulangerie dupont',
        'status' => 'active', 'city' => 'LIBOURNE', 'postal_code' => '33500', 'department_code' => '33',
    ]);
});

it('détecte les doublons probables inter-SIREN et les met en revue manuelle (EF-04.2)', function (): void {
    $this->artisan('fbde:duplicates:detect', ['--department' => '33'])->assertSuccessful();

    $pairs = DB::table('duplicate_reviews')->get();

    // Deux paires inter-SIREN : chacune des « Boulangerie Dupont » du SIREN A
    // face à celle du SIREN B. Jamais de paire intra-SIREN (multi-établissements
    // légitime), jamais Libourne (autre ville).
    $expected = collect([
        collect([$this->first->id, $this->second->id])->sort()->values()->all(),
        collect([$this->sibling->id, $this->second->id])->sort()->values()->all(),
    ]);

    expect($pairs)->toHaveCount(2)
        ->and($pairs->pluck('status')->unique()->all())->toBe(['pending'])
        ->and((float) $pairs->min('similarity'))->toBeGreaterThanOrEqual(0.85);

    $actual = $pairs->map(fn ($p) => collect([$p->establishment_a_id, $p->establishment_b_id])
        ->sort()->values()->all());
    expect($actual->sort()->values()->all())->toBe($expected->sort()->values()->all());

    // Tracé dans imports.
    $import = Import::latest('id')->firstOrFail();
    expect($import->source)->toBe('duplicates')
        ->and((int) $import->stats['flagged'])->toBe(2);
});

it('est rejouable : relancer la détection ne crée pas de paires en double', function (): void {
    $this->artisan('fbde:duplicates:detect', ['--department' => '33'])->assertSuccessful();
    $this->artisan('fbde:duplicates:detect', ['--department' => '33'])->assertSuccessful();

    expect(DB::table('duplicate_reviews')->count())->toBe(2);
});
