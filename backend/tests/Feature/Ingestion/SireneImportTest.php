<?php

use App\Models\Company;
use App\Models\Establishment;
use App\Models\Import;
use App\Services\Ingestion\IngestionService;
use App\Services\Ingestion\NameNormalizer;
use App\Services\Ingestion\Sirene\SireneStockConnector;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RegionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    // RoleSeeder : la commande d'import notifie le rôle administrateur (EF-10.4).
    $this->seed([RoleSeeder::class, RegionSeeder::class, DepartmentSeeder::class]);

    // Exclusion RGPD active pour l'unité 555555555 (§2.4).
    DB::table('exclusion_list')->insert([
        'identifier_type' => 'siren',
        'identifier_value' => '555555555',
        'reason' => 'test — droit d\'opposition',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

function sireneConnector(?string $department = null): SireneStockConnector
{
    return new SireneStockConnector(
        new NameNormalizer,
        base_path('tests/Fixtures/sirene/StockUniteLegale_sample.csv'),
        base_path('tests/Fixtures/sirene/StockEtablissement_sample.csv'),
        $department,
    );
}

it('importe les unités légales avec statut, diffusion et exclusion RGPD', function (): void {
    $stats = app(IngestionService::class)->importCompanies(sireneConnector());

    expect($stats)->toBe(['created' => 4, 'updated' => 0, 'rejected' => 1]);

    // Dénomination classique, normalisée sans forme juridique.
    $garage = Company::where('siren', '444444444')->firstOrFail();
    expect($garage->legal_name)->toBe('GARAGE DU CENTRE SARL')
        ->and($garage->normalized_name)->toBe('garage du centre')
        ->and($garage->status)->toBe('ceased');

    // Entrepreneur individuel : nom + prénom.
    expect(Company::where('siren', '222222222')->value('legal_name'))->toBe('MARTIN JEAN');

    // Unité partiellement diffusible : importée mais marquée (note de cadrage §3).
    expect(Company::where('siren', '333333333')->value('is_diffusible'))->toBeFalse();

    // Unité exclue (RGPD) : jamais importée.
    expect(Company::where('siren', '555555555')->exists())->toBeFalse();
});

it('réimporte sans créer de doublon — CA-3 : 0 création, 100 % mises à jour', function (): void {
    $ingestion = app(IngestionService::class);

    $first = $ingestion->importCompanies(sireneConnector());
    $second = $ingestion->importCompanies(sireneConnector());

    expect($first['created'])->toBe(4)
        ->and($second)->toBe(['created' => 0, 'updated' => 4, 'rejected' => 1])
        ->and(Company::count())->toBe(4);
});

it('importe les établissements : rattachement, géolocalisation, rejets', function (): void {
    $ingestion = app(IngestionService::class);
    $ingestion->importCompanies(sireneConnector());

    $stats = $ingestion->importEstablishments(sireneConnector());

    // 4 valides ; rejetés : 55500017 (exclusion) + 99900019 (unité inconnue).
    expect($stats)->toBe(['created' => 4, 'updated' => 0, 'rejected' => 2, 'geocoded' => 3]);

    $bakery = Establishment::where('siret', '11111111100011')->firstOrFail();
    expect($bakery->company->siren)->toBe('111111111')
        ->and($bakery->is_headquarters)->toBeTrue()
        ->and($bakery->address_line)->toBe('12 RUE DE LA PAIX')
        ->and($bakery->department_code)->toBe('75')
        ->and($bakery->geo_source)->toBe('sirene');

    // Recherche par rayon sur les coordonnées importées (EF-01.3).
    expect(
        Establishment::withinRadius(48.8695, 2.3312, 1)->pluck('siret'),
    )->toContain('11111111100011');

    // Corse : code commune 2A004 → département 2A.
    expect(Establishment::where('siret', '44444444400012')->value('department_code'))->toBe('2A');

    // Coordonnées Lambert-93 du stock standard, reprojetées en WGS84 par
    // PostGIS : la position stockée doit coïncider avec la reprojection
    // de référence (à moins de 10 m).
    $expected = DB::selectOne(
        'SELECT ST_X(p) AS lon, ST_Y(p) AS lat FROM (
            SELECT ST_Transform(ST_SetSRID(ST_MakePoint(1176000, 6107000), 2154), 4326) AS p
        ) t',
    );
    expect(
        Establishment::withinRadius((float) $expected->lat, (float) $expected->lon, 0.01)
            ->pluck('siret'),
    )->toContain('44444444400012');

    // Sans coordonnées dans le stock : non géolocalisé (BAN prendra le relais).
    expect(Establishment::where('siret', '22222222200015')->firstOrFail()->location)->toBeNull();
});

it('préserve les champs d\'enrichissement au réimport — précédence par champ (correctif 15)', function (): void {
    $ingestion = app(IngestionService::class);
    $ingestion->importCompanies(sireneConnector());
    $ingestion->importEstablishments(sireneConnector());

    // Enrichissement postérieur à l'import (crawling simulé).
    Establishment::where('siret', '11111111100011')->update([
        'phone' => '+33142000000',
        'email' => 'contact@dupont.fr',
        'reputation_score' => 72,
    ]);

    $ingestion->importEstablishments(sireneConnector());

    $bakery = Establishment::where('siret', '11111111100011')->firstOrFail();
    expect($bakery->phone)->toBe('+33142000000')
        ->and($bakery->email)->toBe('contact@dupont.fr')
        ->and($bakery->reputation_score)->toBe(72);
});

it('filtre l\'import par département — démonstration « département test »', function (): void {
    $ingestion = app(IngestionService::class);
    $ingestion->importCompanies(sireneConnector());

    $stats = $ingestion->importEstablishments(sireneConnector('75'));

    expect($stats['created'])->toBe(1)
        ->and(Establishment::pluck('siret')->all())->toBe(['11111111100011']);
});

it('restreint les unités légales aux SIREN du département (pré-scan)', function (): void {
    $connector = sireneConnector('75');

    // Pré-scan : Paris (75) ne contient que 111111111 et 555555555.
    $sirens = $connector->collectSirens();
    expect($sirens)->toEqualCanonicalizing(['111111111', '555555555']);

    $connector->setSirenWhitelist($sirens);
    $stats = app(IngestionService::class)->importCompanies($connector);

    // 111111111 importée ; 555555555 rejetée (exclusion RGPD) ; les autres
    // unités du fichier national ne sont pas touchées.
    expect($stats)->toBe(['created' => 1, 'updated' => 0, 'rejected' => 1])
        ->and(Company::pluck('siren')->all())->toBe(['111111111']);
});

it('trace l\'import dans la table imports via la commande artisan (EF-04.5)', function (): void {
    $this->artisan('fbde:sirene:import', [
        '--unites' => base_path('tests/Fixtures/sirene/StockUniteLegale_sample.csv'),
        '--etablissements' => base_path('tests/Fixtures/sirene/StockEtablissement_sample.csv'),
    ])->assertSuccessful();

    $import = Import::latest('id')->firstOrFail();
    expect($import->source)->toBe('sirene')
        ->and($import->status)->toBe('completed')
        ->and($import->stats['companies']['created'])->toBe(4)
        ->and($import->stats['establishments']['created'])->toBe(4)
        ->and($import->started_at)->not->toBeNull()
        ->and($import->finished_at)->not->toBeNull();
});

it('normalise les dénominations : accents, ponctuation, formes juridiques', function (): void {
    $normalizer = new NameNormalizer;

    expect($normalizer->normalize('SARL Boulangerie Pâtisserie L\'Épi d\'Or'))
        ->toBe('boulangerie patisserie l epi d or')
        ->and($normalizer->normalize('GARAGE DU CENTRE SARL'))->toBe('garage du centre')
        ->and($normalizer->normalize('SAS'))->toBeNull()
        ->and($normalizer->normalize(''))->toBeNull()
        ->and($normalizer->normalize(null))->toBeNull();
});
