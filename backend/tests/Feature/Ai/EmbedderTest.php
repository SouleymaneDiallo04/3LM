<?php

use App\Models\Company;
use App\Models\Establishment;
use App\Services\Ai\CompanyEmbedder;
use App\Services\Ai\Exceptions\AiGenerationDenied;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->c = Company::create(['siren' => '111111111', 'legal_name' => 'Dupont', 'normalized_name' => 'dupont', 'status' => 'active']);
    $this->e = Establishment::create(['siret' => '11111111100011', 'company_id' => $this->c->id, 'name' => 'Boulangerie Dupont', 'normalized_name' => 'boulangerie dupont', 'status' => 'active', 'naf_code' => '10.71C', 'city' => 'BORDEAUX']);
});

it('génère et stocke un embedding', function (): void {
    app(CompanyEmbedder::class)->embed($this->e);
    $row = DB::selectOne('SELECT embedded_at, embedding IS NOT NULL AS has FROM establishments WHERE id = ?', [$this->e->id]);
    expect($row->has)->toBeTrue()->and($row->embedded_at)->not->toBeNull();
});

it('refuse d\'embedder une fiche non-diffusible (RGPD)', function (): void {
    $this->e->company->update(['is_diffusible' => false]);
    expect(fn () => app(CompanyEmbedder::class)->embed($this->e))->toThrow(AiGenerationDenied::class);
});
