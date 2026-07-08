<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exports asynchrones (EF-07, §6.2) : fichier généré en tâche de fond,
 * lien expirant à 7 jours (EF-07.4), journalisation RGPD (EF-07.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('format', 10); // xlsx / csv / json / xml / sql
            $table->jsonb('filters');
            $table->jsonb('columns')->nullable(); // sélection et ordre (EF-07.3)
            $table->string('status', 15)->default('pending')->index();
            $table->unsignedInteger('rows_count')->nullable();
            $table->string('file_path')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->text('error')->nullable();

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exports');
    }
};
