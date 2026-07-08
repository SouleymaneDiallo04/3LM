<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historique des recherches (EF-01.6, §6.2). La recherche est une requête
 * locale synchrone (correctif 3) ; le statut sert aux relances planifiées
 * (EF-01.7, P2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('searches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('keyword')->nullable();
            $table->jsonb('filters')->nullable(); // critères combinés (EF-01.4)
            $table->string('city')->nullable();
            $table->string('department_code', 3)->nullable();
            $table->string('postal_code', 5)->nullable();
            $table->geography('center', subtype: 'point', srid: 4326)->nullable();
            $table->unsignedSmallInteger('radius_km')->nullable(); // 5–100 km (EF-01.3)

            $table->unsignedInteger('results_count')->nullable();
            $table->string('status', 15)->default('completed');
            $table->string('schedule', 15)->nullable(); // daily / weekly / monthly (EF-01.7)

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('searches');
    }
};
