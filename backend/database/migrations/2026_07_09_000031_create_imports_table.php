<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suivi des jobs d'import et d'enrichissement (§6.2, EF-04.5).
 * source : sirene / osm / crawler (correctif 13.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table): void {
            $table->id();

            $table->string('source', 20)->index(); // sirene / osm / crawler
            $table->string('status', 15)->default('pending')->index(); // pending / running / completed / failed

            // Rapport de déduplication par import (EF-04.5) :
            // {created, updated, merged, rejected, geocoded…}
            $table->jsonb('stats')->nullable();

            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->text('error')->nullable();

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imports');
    }
};
