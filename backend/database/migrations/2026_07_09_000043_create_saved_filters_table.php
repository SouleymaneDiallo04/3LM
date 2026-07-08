<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Filtres nommés réutilisables et partageables (EF-03.4, §6.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_filters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->jsonb('criteria');
            $table->boolean('is_shared')->default(false);

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_filters');
    }
};
