<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiel officiel des départements français (COG INSEE) — EF-01.2.
 * Le code est alphanumérique : 2A / 2B (Corse), 971–976 (outre-mer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table): void {
            $table->smallIncrements('id');
            $table->string('code', 3)->unique(); // Code département INSEE
            $table->string('name');
            $table->string('region_code', 2)->index();

            $table->foreign('region_code')->references('code')->on('regions');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
