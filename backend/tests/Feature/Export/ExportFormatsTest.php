<?php

use App\Models\Company;
use App\Models\Establishment;
use App\Models\Export;
use App\Models\User;
use App\Services\Export\ExportGenerator;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->user = User::factory()->create()->syncRoles('commercial');

    $company = Company::create([
        'siren' => '111111111', 'legal_name' => 'Dupont SAS',
        'normalized_name' => 'dupont', 'status' => 'active',
    ]);
    Establishment::create([
        'siret' => '11111111100011', 'company_id' => $company->id,
        'name' => "Boulangerie l'Épi <d'Or>", 'normalized_name' => 'boulangerie epi or',
        'status' => 'active', 'naf_code' => '10.71C', 'city' => 'PARIS',
        'email' => 'contact@epi.fr',
    ]);
});

function generateExport(string $format, User $user): string
{
    $export = Export::create([
        'user_id' => $user->id, 'format' => $format,
        'filters' => [], 'columns' => ['siret', 'nom', 'ville', 'email'],
        'status' => 'pending',
    ]);

    app(ExportGenerator::class)->generate($export);
    $export->refresh();

    expect($export->status)->toBe('completed')->and($export->rows_count)->toBe(1);

    return Storage::disk('local')->get($export->file_path);
}

it('accepte les formats JSON, XML et SQL à la création (EF-07.2)', function (string $format): void {
    Sanctum::actingAs($this->user, ['*']);

    $this->postJson('/api/v1/exports', ['format' => $format, 'filters' => []])
        ->assertStatus(202);
})->with(['json', 'xml', 'sql']);

it('génère un export JSON : tableau d\'objets aux clés des colonnes', function (): void {
    $content = generateExport('json', $this->user);

    $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
    expect($decoded)->toHaveCount(1)
        ->and($decoded[0]['siret'])->toBe('11111111100011')
        ->and($decoded[0]['nom'])->toBe("Boulangerie l'Épi <d'Or>")
        ->and($decoded[0]['email'])->toBe('contact@epi.fr');
});

it('génère un export XML valide, caractères spéciaux échappés', function (): void {
    $content = generateExport('xml', $this->user);

    $xml = simplexml_load_string($content);
    expect($xml)->not->toBeFalse()
        ->and($xml->etablissement)->toHaveCount(1)
        ->and((string) $xml->etablissement[0]->siret)->toBe('11111111100011')
        ->and((string) $xml->etablissement[0]->nom)->toBe("Boulangerie l'Épi <d'Or>");
});

it('génère un export SQL rejouable : CREATE TABLE + INSERT échappés', function (): void {
    $content = generateExport('sql', $this->user);

    expect($content)->toContain('CREATE TABLE')
        ->toContain('INSERT INTO etablissements')
        ->toContain('11111111100011')
        // L'apostrophe du nom est doublée (échappement SQL standard).
        ->toContain("Boulangerie l''Épi");
});
