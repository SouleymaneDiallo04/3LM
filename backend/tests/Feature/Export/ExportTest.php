<?php

use App\Jobs\GenerateExportJob;
use App\Models\Company;
use App\Models\Establishment;
use App\Models\Export;
use App\Models\User;
use App\Services\Export\ExportGenerator;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use OpenSpout\Reader\XLSX\Reader;

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->user = User::factory()->create()->syncRoles('commercial');

    $company = Company::create([
        'siren' => '111111111', 'legal_name' => 'Dupont SAS',
        'normalized_name' => 'dupont', 'status' => 'active',
    ]);

    Establishment::create([
        'siret' => '11111111100011', 'company_id' => $company->id,
        'name' => 'Boulangerie Dupont', 'normalized_name' => 'boulangerie dupont',
        'status' => 'active', 'naf_code' => '10.71C', 'city' => 'PARIS',
        'postal_code' => '75002', 'email' => 'contact@dupont.fr',
    ]);
    Establishment::create([
        'siret' => '11111111100029', 'company_id' => $company->id,
        'name' => 'Garage Rhône', 'normalized_name' => 'garage rhone',
        'status' => 'ceased', 'naf_code' => '45.20A', 'city' => 'LYON',
    ]);
});

it('crée un export asynchrone et le journalise (EF-07.4, EF-07.5)', function (): void {
    Queue::fake();

    $response = $this->actingAs($this->user)->postJson('/api/v1/exports', [
        'format' => 'csv',
        'filters' => ['city' => 'PARIS'],
    ]);

    $response->assertStatus(202);
    Queue::assertPushed(GenerateExportJob::class);

    $export = Export::findOrFail($response->json('data.id'));
    expect($export->status)->toBe('pending')
        ->and($export->filters)->toBe(['city' => 'PARIS']);

    // Journalisé (EF-07.5).
    expect(
        DB::table('audit_logs')->where('action', 'export.requested')
            ->where('subject_id', $export->id)->exists(),
    )->toBeTrue();
});

it('refuse un export sans permission exports.create', function (): void {
    $noRole = User::factory()->create();

    $this->actingAs($noRole)
        ->postJson('/api/v1/exports', ['format' => 'csv'])
        ->assertForbidden();
});

it('génère un CSV conforme au résultat filtré (EF-07.1)', function (): void {
    $export = Export::create([
        'user_id' => $this->user->id, 'format' => 'csv',
        'filters' => [], 'columns' => ['siret', 'nom', 'ville', 'email'],
        'status' => 'pending',
    ]);

    app(ExportGenerator::class)->generate($export);

    $export->refresh();
    expect($export->status)->toBe('completed')
        ->and($export->rows_count)->toBe(1) // seuls les actifs par défaut
        ->and($export->expires_at)->not->toBeNull();

    $content = Storage::disk('local')->get($export->file_path);
    expect($content)->toContain('siret,nom,ville,email')
        ->toContain('11111111100011,"Boulangerie Dupont",PARIS,contact@dupont.fr')
        ->not->toContain('11111111100029'); // fermé, exclu par défaut
});

it('génère un XLSX lisible (EF-07.1)', function (): void {
    $export = Export::create([
        'user_id' => $this->user->id, 'format' => 'xlsx',
        'filters' => ['status' => 'all'], 'status' => 'pending',
    ]);

    app(ExportGenerator::class)->generate($export);

    $export->refresh();
    expect($export->rows_count)->toBe(2);

    // Le fichier est une archive XLSX valide contenant nos données.
    $reader = new Reader;
    $reader->open(Storage::disk('local')->path($export->file_path));
    $cells = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $cells[] = $row->toArray()[0];
        }
    }
    $reader->close();

    expect($cells)->toContain('siret', '11111111100011', '11111111100029');
});

it('sert le téléchargement au seul propriétaire, avec expiration (EF-07.4)', function (): void {
    $export = Export::create([
        'user_id' => $this->user->id, 'format' => 'csv',
        'filters' => [], 'status' => 'pending',
    ]);
    app(ExportGenerator::class)->generate($export);

    // Propriétaire : OK + journalisation du téléchargement.
    $this->actingAs($this->user)
        ->get("/api/v1/exports/{$export->id}/download")
        ->assertOk();
    expect(
        DB::table('audit_logs')->where('action', 'export.downloaded')->exists(),
    )->toBeTrue();

    // Autre utilisateur : refusé (changement d'acteur via le guard sanctum).
    $other = User::factory()->create()->syncRoles('commercial');
    Sanctum::actingAs($other);
    $this->get("/api/v1/exports/{$export->id}/download")
        ->assertForbidden();
});

it('refuse le téléchargement après expiration du lien — 7 jours (EF-07.4)', function (): void {
    $export = Export::create([
        'user_id' => $this->user->id, 'format' => 'csv',
        'filters' => [], 'status' => 'pending',
    ]);
    app(ExportGenerator::class)->generate($export);
    $export->update(['expires_at' => now()->subMinute()]);

    $this->actingAs($this->user)
        ->get("/api/v1/exports/{$export->id}/download")
        ->assertStatus(410);
});

it('rejette les colonnes inconnues et les formats non supportés', function (): void {
    $this->actingAs($this->user)->postJson('/api/v1/exports', [
        'format' => 'pdf',
    ])->assertUnprocessable();

    $this->actingAs($this->user)->postJson('/api/v1/exports', [
        'format' => 'csv',
        'columns' => ['siret', 'colonne_inconnue'],
    ])->assertUnprocessable();
});
