<?php

use App\Models\Export;
use App\Models\Import;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RegionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, RegionSeeder::class, DepartmentSeeder::class]);
    $this->user = User::factory()->create()->syncRoles('commercial');
});

it('purge les fichiers des exports expirés (EF-07.4)', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('exports/vieux.csv', 'a;b');
    Storage::disk('local')->put('exports/recent.csv', 'a;b');

    $expired = Export::create([
        'user_id' => $this->user->id, 'format' => 'csv', 'filters' => [],
        'status' => 'completed', 'file_path' => 'exports/vieux.csv',
        'expires_at' => now()->subDay(),
    ]);
    $fresh = Export::create([
        'user_id' => $this->user->id, 'format' => 'csv', 'filters' => [],
        'status' => 'completed', 'file_path' => 'exports/recent.csv',
        'expires_at' => now()->addDays(6),
    ]);

    $this->artisan('fbde:exports:purge')->assertSuccessful();

    Storage::disk('local')->assertMissing('exports/vieux.csv');
    Storage::disk('local')->assertExists('exports/recent.csv');
    expect($expired->fresh()->file_path)->toBeNull()
        ->and($fresh->fresh()->file_path)->toBe('exports/recent.csv')
        ->and(DB::table('audit_logs')->where('action', 'export.purged')
            ->where('subject_id', $expired->id)->exists())->toBeTrue();
});

it('rafraîchit le stock SIRENE : résolution data.gouv, téléchargement, import (§2.1)', function (): void {
    config(['fbde.import_department' => null]);

    Http::fake([
        'www.data.gouv.fr/api/1/datasets/*' => Http::response([
            'resources' => [
                [
                    'title' => 'StockUniteLegale_utf8.csv',
                    'url' => 'https://files.example/StockUniteLegale_utf8.csv',
                ],
                [
                    'title' => 'StockEtablissement_utf8.csv',
                    'url' => 'https://files.example/StockEtablissement_utf8.csv',
                ],
            ],
        ]),
        'files.example/StockUniteLegale_utf8.csv' => Http::response(
            file_get_contents(base_path('tests/Fixtures/sirene/StockUniteLegale_sample.csv')),
        ),
        'files.example/StockEtablissement_utf8.csv' => Http::response(
            file_get_contents(base_path('tests/Fixtures/sirene/StockEtablissement_sample.csv')),
        ),
    ]);

    $this->artisan('fbde:sirene:refresh')->assertSuccessful();

    $import = Import::latest('id')->firstOrFail();
    expect($import->source)->toBe('sirene')
        ->and($import->status)->toBe('completed')
        ->and((int) $import->stats['establishments']['created'])->toBeGreaterThan(0);
});

it('planifie la purge quotidienne et le re-import mensuel', function (): void {
    $events = collect(app(Schedule::class)->events());

    $purge = $events->first(fn ($e): bool => str_contains((string) $e->command, 'fbde:exports:purge'));
    $refresh = $events->first(fn ($e): bool => str_contains((string) $e->command, 'fbde:sirene:refresh'));

    expect($purge)->not->toBeNull()
        ->and($purge->expression)->toBe('0 3 * * *') // tous les jours à 3 h
        ->and($refresh)->not->toBeNull()
        ->and($refresh->expression)->toBe('0 2 5 * *'); // le 5 de chaque mois à 2 h
});

it('planifie le re-crawl quotidien à fenêtre 90 jours et le rescoring (EF-05.5)', function (): void {
    $events = collect(app(Schedule::class)->events());

    $crawl = $events->first(fn ($e): bool => str_contains((string) $e->command, 'fbde:crawl'));
    $score = $events->first(fn ($e): bool => str_contains((string) $e->command, 'fbde:score'));

    expect($crawl)->not->toBeNull()
        ->and($crawl->expression)->toBe('0 4 * * *') // chaque nuit à 4 h
        ->and((string) $crawl->command)->toContain('--stale=90') // EF-05.5
        ->and($score)->not->toBeNull()
        ->and($score->expression)->toBe('0 5 * * *'); // après crawl et enrichissements
});
