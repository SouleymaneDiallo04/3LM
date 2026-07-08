<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unités légales SIRENE (§6.1 transposé France — ADR 0003).
 * Une ligne par SIREN ; clé de déduplication forte (EF-04.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();

            // Identifiant national unique — clé de déduplication forte.
            $table->char('siren', 9)->unique();

            $table->string('legal_name');
            $table->string('normalized_name'); // minuscules, sans accents ni forme juridique
            $table->string('legal_form')->nullable(); // catégorie juridique INSEE (libellé)
            $table->string('status', 10)->default('unknown')->index(); // active / ceased / unknown
            $table->string('naf_code', 6)->nullable()->index(); // activité principale (NAF rév. 2)
            $table->string('employee_range', 5)->nullable(); // tranche d'effectifs INSEE
            $table->date('incorporated_at')->nullable(); // date de création de l'unité légale

            // Statut de diffusion SIRENE : les unités partiellement diffusibles
            // (entrepreneurs individuels protégés) sont marquées ; les
            // non-diffusibles ne sont jamais importées (note de cadrage §3).
            $table->boolean('is_diffusible')->default(true);

            // Traçabilité des collectes (§6.1).
            $table->string('source', 20)->default('sirene');
            $table->timestampTz('imported_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();
        });

        // Similarité trigramme sur le nom normalisé (EF-04.2, EF-03.2).
        DB::statement(
            'CREATE INDEX companies_normalized_name_trgm
             ON companies USING gin (normalized_name gin_trgm_ops)',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
