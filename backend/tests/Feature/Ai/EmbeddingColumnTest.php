<?php

use App\Models\Company;
use App\Models\Establishment;
use Illuminate\Support\Facades\DB;

it('stocke et relit un vecteur d\'embedding', function (): void {
    $c = Company::create(['siren' => '111111111', 'legal_name' => 'X', 'normalized_name' => 'x', 'status' => 'active']);
    $e = Establishment::create(['siret' => '11111111100011', 'company_id' => $c->id, 'name' => 'X', 'normalized_name' => 'x', 'status' => 'active']);

    $vec = '[' . implode(',', array_fill(0, 1024, 0.01)) . ']';
    DB::update('UPDATE establishments SET embedding = ?::vector, embedded_at = now() WHERE id = ?', [$vec, $e->id]);

    $row = DB::selectOne('SELECT embedded_at, embedding IS NOT NULL AS has_vec FROM establishments WHERE id = ?', [$e->id]);
    expect($row->has_vec)->toBeTrue()->and($row->embedded_at)->not->toBeNull();
});
