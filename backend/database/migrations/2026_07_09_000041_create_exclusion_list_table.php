<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liste d'exclusion RGPD (§2.4, §6.2) : identifiants et domaines à ne
 * jamais (re)collecter — support technique du droit d'opposition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exclusion_list', function (Blueprint $table): void {
            $table->id();

            $table->string('identifier_type', 10); // siren / siret / domain / email
            $table->string('identifier_value');
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampsTz();

            $table->unique(['identifier_type', 'identifier_value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exclusion_list');
    }
};
