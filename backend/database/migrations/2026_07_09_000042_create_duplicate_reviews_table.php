<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * File de revue manuelle des doublons présumés (EF-04.2, correctif 15) :
 * les paires détectées par similarité ne sont JAMAIS fusionnées
 * automatiquement — revue humaine exclusivement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duplicate_reviews', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('establishment_a_id')->constrained('establishments')->cascadeOnDelete();
            $table->foreignId('establishment_b_id')->constrained('establishments')->cascadeOnDelete();
            $table->decimal('similarity', 4, 3); // score trigramme 0–1
            $table->string('status', 10)->default('pending')->index(); // pending / merged / distinct
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();

            $table->timestampsTz();

            $table->unique(['establishment_a_id', 'establishment_b_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duplicate_reviews');
    }
};
