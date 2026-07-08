<?php

use App\Models\Company;
use App\Models\Department;
use App\Models\Establishment;
use App\Models\Region;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RegionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed([RegionSeeder::class, DepartmentSeeder::class]);
});

/** Crée une unité légale et un établissement géolocalisé de test. */
function makeEstablishment(
    string $siret,
    float $lat,
    float $lon,
    array $attributes = [],
): Establishment {
    $company = Company::firstOrCreate(
        ['siren' => substr($siret, 0, 9)],
        [
            'legal_name' => 'Société Test',
            'normalized_name' => 'societe test',
            'status' => 'active',
        ],
    );

    return Establishment::create([
        'siret' => $siret,
        'company_id' => $company->id,
        'name' => 'Établissement '.$siret,
        'normalized_name' => 'etablissement '.$siret,
        'status' => 'active',
        'location' => DB::raw(
            sprintf('ST_SetSRID(ST_MakePoint(%F, %F), 4326)', $lon, $lat),
        ),
        ...$attributes,
    ]);
}

it('charge les référentiels officiels : 18 régions et 101 départements', function (): void {
    expect(Region::count())->toBe(18)
        ->and(Department::count())->toBe(101);

    // Cohérence du rattachement : Paris (75) → Île-de-France (11).
    $paris = Department::where('code', '75')->firstOrFail();
    expect($paris->region->name)->toBe('Île-de-France');

    // Codes alphanumériques corses et outre-mer présents.
    expect(Department::whereIn('code', ['2A', '2B', '971', '976'])->count())->toBe(4);
});

it('trouve les établissements par rayon géographique (EF-01.3, ST_DWithin)', function (): void {
    // Notre-Dame de Paris et la tour Eiffel : ~4,1 km.
    makeEstablishment('11111111100011', 48.8530, 2.3499);
    makeEstablishment('22222222200022', 48.8584, 2.2945);
    // Basilique de Fourvière, Lyon : ~390 km de Paris.
    makeEstablishment('33333333300033', 45.7623, 4.8220);

    $withinFiveKm = Establishment::withinRadius(48.8530, 2.3499, 5)->pluck('siret');

    expect($withinFiveKm)->toContain('11111111100011')
        ->toContain('22222222200022')
        ->not->toContain('33333333300033');

    // Rayon de 1 km : seul Notre-Dame reste.
    expect(Establishment::withinRadius(48.8530, 2.3499, 1)->count())->toBe(1);
});

it('refuse un SIRET en double — clé de déduplication forte (EF-04.1)', function (): void {
    makeEstablishment('11111111100011', 48.85, 2.35);

    expect(fn () => makeEstablishment('11111111100011', 48.86, 2.36))
        ->toThrow(QueryException::class);
});

it('refuse un SIREN en double sur les unités légales (EF-04.1)', function (): void {
    Company::create([
        'siren' => '111111111',
        'legal_name' => 'A',
        'normalized_name' => 'a',
    ]);

    expect(fn () => Company::create([
        'siren' => '111111111',
        'legal_name' => 'B',
        'normalized_name' => 'b',
    ]))->toThrow(QueryException::class);
});

it('rend le journal d’audit immuable — insertion seule (EF-10.3, §8)', function (): void {
    DB::table('audit_logs')->insert([
        'action' => 'test',
        'payload' => json_encode(['k' => 'v']),
    ]);

    $log = DB::table('audit_logs')->where('action', 'test')->first();

    // UPDATE neutralisé par la règle PostgreSQL.
    DB::table('audit_logs')->where('id', $log->id)->update(['action' => 'altered']);
    expect(DB::table('audit_logs')->find($log->id)->action)->toBe('test');

    // DELETE neutralisé également.
    DB::table('audit_logs')->where('id', $log->id)->delete();
    expect(DB::table('audit_logs')->find($log->id))->not->toBeNull();
});

it('relie établissement → unité légale et → département', function (): void {
    $establishment = makeEstablishment('11111111100011', 48.85, 2.35, [
        'department_code' => '75',
    ]);

    expect($establishment->company->siren)->toBe('111111111')
        ->and($establishment->department->name)->toBe('Paris')
        ->and($establishment->company->establishments()->count())->toBe(1);
});

it('mesure la similarité trigramme sur les noms normalisés (EF-04.2)', function (): void {
    // Le seuil CDC est 0,85 pour la file de revue manuelle.
    $similarity = DB::selectOne(
        'SELECT similarity(?, ?) AS s',
        ['boulangerie dupont', 'boulangerie dupond'],
    )->s;

    expect($similarity)->toBeGreaterThan(0.8);
});
