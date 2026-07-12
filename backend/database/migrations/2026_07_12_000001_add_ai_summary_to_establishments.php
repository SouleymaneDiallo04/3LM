<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Résumé IA (EF-08.2) mis en cache sur la fiche. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('establishments', function (Blueprint $table): void {
            $table->text('ai_summary')->nullable();
            $table->string('ai_summary_version', 20)->nullable();
            $table->timestampTz('ai_summary_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('establishments', function (Blueprint $table): void {
            $table->dropColumn(['ai_summary', 'ai_summary_version', 'ai_summary_at']);
        });
    }
};
