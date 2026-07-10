<?php

use App\Jobs\GenerateExportJob;
use App\Models\Company;
use App\Models\Establishment;
use App\Models\Export;
use App\Models\User;
use App\Notifications\ExportFailed;
use App\Notifications\ExportReady;
use App\Notifications\SireneImportFailed;
use App\Notifications\SireneImportFinished;
use App\Services\Export\ExportGenerator;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RegionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, RegionSeeder::class, DepartmentSeeder::class]);
    $this->user = User::factory()->create()->syncRoles('commercial');

    $company = Company::create([
        'siren' => '111111111', 'legal_name' => 'Dupont SAS',
        'normalized_name' => 'dupont', 'status' => 'active',
    ]);
    Establishment::create([
        'siret' => '11111111100011', 'company_id' => $company->id,
        'name' => 'Boulangerie Dupont', 'normalized_name' => 'boulangerie dupont',
        'status' => 'active', 'naf_code' => '10.71C', 'city' => 'PARIS',
    ]);
});

it('notifie le propriétaire en base quand son export est prêt (EF-10.4)', function (): void {
    $export = Export::create([
        'user_id' => $this->user->id, 'format' => 'csv',
        'filters' => [], 'status' => 'pending',
    ]);

    (new GenerateExportJob($export))->handle(app(ExportGenerator::class));

    $notifications = $this->user->fresh()->notifications;
    expect($notifications)->toHaveCount(1)
        ->and($notifications->first()->type)->toBe(ExportReady::class)
        ->and($notifications->first()->data['export_id'])->toBe($export->id)
        ->and($notifications->first()->data['rows_count'])->toBe(1)
        ->and($notifications->first()->read_at)->toBeNull();
});

it('expédie la notification d\'export sur les canaux base + email', function (): void {
    Notification::fake();

    $export = Export::create([
        'user_id' => $this->user->id, 'format' => 'csv',
        'filters' => [], 'status' => 'pending',
    ]);

    (new GenerateExportJob($export))->handle(app(ExportGenerator::class));

    Notification::assertSentTo(
        $this->user,
        ExportReady::class,
        fn ($notification, array $channels): bool => in_array('database', $channels, true)
            && in_array('mail', $channels, true),
    );
});

it('notifie les administrateurs en fin d\'import SIRENE (EF-10.4)', function (): void {
    $admin = User::factory()->create()->syncRoles('administrateur');

    $this->artisan('fbde:sirene:import', [
        '--unites' => base_path('tests/Fixtures/sirene/StockUniteLegale_sample.csv'),
        '--etablissements' => base_path('tests/Fixtures/sirene/StockEtablissement_sample.csv'),
    ])->assertSuccessful();

    $notifications = $admin->fresh()->notifications;
    expect($notifications)->toHaveCount(1)
        ->and($notifications->first()->type)->toBe(SireneImportFinished::class)
        ->and($notifications->first()->data['source'])->toBe('sirene');

    // Le commercial, lui, n'est pas concerné par les imports.
    expect($this->user->fresh()->notifications)->toHaveCount(0);
});

it('notifie le propriétaire quand son export échoue (EF-10.4)', function (): void {
    $export = Export::create([
        'user_id' => $this->user->id, 'format' => 'csv',
        'filters' => [], 'status' => 'pending',
    ]);

    (new GenerateExportJob($export))->failed(new RuntimeException('disque plein'));

    $notifications = $this->user->fresh()->notifications;
    expect($notifications)->toHaveCount(1)
        ->and($notifications->first()->type)->toBe(ExportFailed::class)
        ->and($notifications->first()->data['export_id'])->toBe($export->id)
        ->and($export->fresh()->status)->toBe('failed');
});

it('notifie les administrateurs quand l\'import SIRENE échoue (EF-10.4)', function (): void {
    $admin = User::factory()->create()->syncRoles('administrateur');

    // Fichier établissements illisible en cours de route : unités OK, puis échec.
    $this->artisan('fbde:sirene:import', [
        '--unites' => base_path('tests/Fixtures/sirene/StockUniteLegale_sample.csv'),
        '--etablissements' => base_path('tests/Fixtures/sirene/inexistant.csv'),
    ])->assertFailed();

    // Échec avant même la création de la ligne d'import : pas de notification.
    expect($admin->fresh()->notifications)->toHaveCount(0);

    // Échec pendant l'import (fichier corrompu après validation initiale).
    $corrupt = tempnam(sys_get_temp_dir(), 'sirene');
    file_put_contents($corrupt, "colonnes,inconnues\n1,2\n");

    try {
        $this->artisan('fbde:sirene:import', [
            '--unites' => $corrupt,
            '--etablissements' => base_path('tests/Fixtures/sirene/StockEtablissement_sample.csv'),
        ]);
    } catch (Throwable) {
        // La commande relance l'exception après avoir tracé l'échec.
    }

    $notifications = $admin->fresh()->notifications;
    expect($notifications)->toHaveCount(1)
        ->and($notifications->first()->type)->toBe(SireneImportFailed::class)
        ->and($notifications->first()->data['status'])->toBe('failed');
});

it('liste les notifications et les marque lues via l\'API', function (): void {
    $export = Export::create([
        'user_id' => $this->user->id, 'format' => 'csv',
        'filters' => [], 'status' => 'completed', 'rows_count' => 1,
    ]);
    $this->user->notify(new ExportReady($export));

    $list = $this->actingAs($this->user)->getJson('/api/v1/notifications');
    $list->assertOk();
    expect($list->json('data'))->toHaveCount(1)
        ->and($list->json('meta.unread_count'))->toBe(1)
        ->and($list->json('data.0.data.export_id'))->toBe($export->id);

    $this->actingAs($this->user)->postJson('/api/v1/notifications/read')->assertOk();

    $after = $this->actingAs($this->user)->getJson('/api/v1/notifications');
    expect($after->json('meta.unread_count'))->toBe(0);

    // Les notifications d'un autre utilisateur restent invisibles
    // (Sanctum::actingAs — le seul moyen de changer d'acteur en cours de test).
    $other = User::factory()->create()->syncRoles('commercial');
    Sanctum::actingAs($other, ['*']);
    $otherList = $this->getJson('/api/v1/notifications');
    expect($otherList->json('data'))->toHaveCount(0);
});
