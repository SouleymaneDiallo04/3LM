<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM — prospects (EF-09, §6.2). Schéma anticipé dès les migrations
 * initiales conformément au correctif 22 ; le module CRM lui-même est
 * réalisé en phase 6 (hors périmètre des phases 1-3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospects', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('establishment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Pipeline EF-09.1 : new → contacted → qualified → client / lost.
            $table->string('stage', 15)->default('new')->index();
            $table->timestampTz('converted_at')->nullable();
            $table->string('lost_reason')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospects');
    }
};
