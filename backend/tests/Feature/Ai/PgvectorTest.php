<?php

use Illuminate\Support\Facades\DB;

it('a l\'extension pgvector et calcule une distance cosinus', function (): void {
    $row = DB::selectOne("SELECT ('[1,0,0]'::vector(3) <=> '[1,0,0]'::vector(3)) AS same,
                                 ('[1,0,0]'::vector(3) <=> '[0,1,0]'::vector(3)) AS diff");

    expect((float) $row->same)->toBe(0.0)
        ->and((float) $row->diff)->toBeGreaterThan(0.9);
});
