<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Journal d'audit immuable (EF-10.3, §8, EF-07.5) : insertion seule,
 * garantie par des règles PostgreSQL bloquant UPDATE et DELETE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('action', 50)->index(); // login / search / export / import…
            $table->nullableMorphs('subject'); // entité concernée
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->jsonb('payload')->nullable();

            $table->timestampTz('created_at')->useCurrent();
            $table->index('created_at');
        });

        // Insertion seule : toute tentative de modification ou de suppression
        // est neutralisée au niveau du moteur (§8 Journalisation).
        DB::statement('CREATE RULE audit_logs_no_update AS ON UPDATE TO audit_logs DO INSTEAD NOTHING');
        DB::statement('CREATE RULE audit_logs_no_delete AS ON DELETE TO audit_logs DO INSTEAD NOTHING');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
