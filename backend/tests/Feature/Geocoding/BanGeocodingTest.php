<?php

use App\Models\Company;
use App\Models\Establishment;
use App\Models\Import;
use App\Services\Geocoding\GeocodingService;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RegionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Crée un établissement non géolocalisé avec adresse. */
function makeUngeocoded(string $siret, array $attributes = []): Establishment
{
    $company = Company::firstOrCreate(
        ['siren' => substr($siret, 0, 9)],
        ['legal_name' => 'Société', 'normalized_name' => 'societe'],
    );

    return Establishment::create([
        'siret' => $siret,
        'company_id' => $company->id,
        'status' => 'active',
        'address_line' => '12 RUE DE LA PAIX',
        'postal_code' => '75002',
        'city' => 'PARIS',
        'city_code' => '75102',
        ...$attributes,
    ]);
}

/** Réponse CSV BAN simulée : id → [lon, lat, score]. */
function fakeBanResponse(array $rows): string
{
    $lines = ['id,address,postcode,city,city_code,latitude,longitude,result_score,result_label'];

    foreach ($rows as $id => $r) {
        $lines[] = $r === null
            ? "{$id},x,x,x,x,,,,"
            : "{$id},x,x,x,x,{$r[1]},{$r[0]},{$r[2]},libellé";
    }

    return implode("\n", $lines);
}

it('géocode les établissements sans coordonnées via la BAN (batch CSV)', function (): void {
    makeUngeocoded('11111111100011');
    makeUngeocoded('22222222200022', ['address_line' => '8 AVENUE JEAN JAURES', 'postal_code' => '69007', 'city' => 'LYON', 'city_code' => '69387']);

    Http::fake([
        '*/search/csv/*' => Http::response(fakeBanResponse([
            '11111111100011' => [2.3312, 48.8695, 0.92],
            '22222222200022' => [4.8423, 45.7458, 0.87],
        ])),
    ]);

    $stats = app(GeocodingService::class)->geocodeMissing();

    expect($stats)->toBe(['candidates' => 2, 'geocoded' => 2, 'below_threshold' => 0, 'unmatched' => 0]);

    $paris = Establishment::where('siret', '11111111100011')->firstOrFail();
    expect($paris->geo_source)->toBe('ban')
        ->and((float) $paris->geo_score)->toBe(0.92)
        ->and(
            Establishment::withinRadius(48.8695, 2.3312, 0.1)->pluck('siret'),
        )->toContain('11111111100011');
});

it('écarte les appariements sous le seuil de score — candidats au repli Nominatim', function (): void {
    makeUngeocoded('11111111100011');

    Http::fake([
        '*/search/csv/*' => Http::response(fakeBanResponse([
            '11111111100011' => [2.3312, 48.8695, 0.31], // < 0.5
        ])),
    ]);

    $stats = app(GeocodingService::class)->geocodeMissing();

    expect($stats['below_threshold'])->toBe(1)
        ->and($stats['geocoded'])->toBe(0)
        ->and(Establishment::where('siret', '11111111100011')->firstOrFail()->location)->toBeNull();
});

it('compte les adresses non appariées par la BAN', function (): void {
    makeUngeocoded('11111111100011');

    Http::fake([
        '*/search/csv/*' => Http::response(fakeBanResponse([
            '11111111100011' => null, // aucune coordonnée renvoyée
        ])),
    ]);

    $stats = app(GeocodingService::class)->geocodeMissing();

    expect($stats)->toBe(['candidates' => 1, 'geocoded' => 0, 'below_threshold' => 0, 'unmatched' => 1]);
});

it('ignore les établissements déjà géolocalisés ou sans adresse', function (): void {
    // Déjà géolocalisé.
    makeUngeocoded('11111111100011');
    DB::update(
        "UPDATE establishments SET location = ST_SetSRID(ST_MakePoint(2.3, 48.8), 4326)::geography,
         geo_source = 'sirene' WHERE siret = '11111111100011'",
    );
    // Sans la moindre adresse.
    makeUngeocoded('22222222200022', ['address_line' => null, 'city' => null]);

    Http::fake();

    $stats = app(GeocodingService::class)->geocodeMissing();

    expect($stats['candidates'])->toBe(0);
    Http::assertNothingSent();
});

it('filtre par département et trace l\'exécution via la commande (imports)', function (): void {
    makeUngeocoded('11111111100011', ['department_code' => null]);
    makeUngeocoded('33333333300033', [
        'address_line' => '1 PLACE DE LA BOURSE', 'postal_code' => '33000',
        'city' => 'BORDEAUX', 'city_code' => '33063',
    ]);
    Establishment::where('siret', '33333333300033')->update(['department_code' => null]);

    // Rattache uniquement le bordelais au département 33.
    $this->seed([RegionSeeder::class, DepartmentSeeder::class]);
    Establishment::where('siret', '33333333300033')->update(['department_code' => '33']);

    Http::fake([
        '*/search/csv/*' => Http::response(fakeBanResponse([
            '33333333300033' => [-0.5731, 44.8412, 0.95],
        ])),
    ]);

    $this->artisan('fbde:geocode', ['--department' => '33'])->assertSuccessful();

    // Seul l'établissement du 33 a été traité.
    expect(Establishment::where('siret', '11111111100011')->firstOrFail()->location)->toBeNull()
        ->and(Establishment::where('siret', '33333333300033')->firstOrFail()->geo_source)->toBe('ban');

    $import = Import::latest('id')->firstOrFail();
    expect($import->source)->toBe('ban')
        ->and($import->status)->toBe('completed')
        ->and($import->stats['geocoded'])->toBe(1)
        ->and($import->stats['rate_percent'])->toBe(100);
});
