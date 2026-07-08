<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiel officiel des régions françaises (COG INSEE) — EF-01.2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $table): void {
            $table->smallIncrements('id');
            $table->string('code', 2)->unique(); // Code région INSEE
            $table->string('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regions');
    }
};
