<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM — suivi d'activités (EF-09.3, §6.2). Schéma anticipé (correctif 22),
 * module réalisé en phase 6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('prospect_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('type', 10); // call / email / meeting / note
            $table->text('summary')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('next_action_at')->nullable();

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
