<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Embeddings des établissements (EF-08.3) : vecteur mistral-embed (1024 dims)
 * + horodatage, avec index HNSW cosinus pour les plus-proches-voisins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('establishments', function (Blueprint $table): void {
            $table->timestampTz('embedded_at')->nullable();
        });

        // Type pgvector : hors Blueprint natif → SQL brut.
        DB::statement('ALTER TABLE establishments ADD COLUMN embedding vector(1024)');
        DB::statement('CREATE INDEX establishments_embedding_hnsw ON establishments USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS establishments_embedding_hnsw');
        DB::statement('ALTER TABLE establishments DROP COLUMN IF EXISTS embedding');
        Schema::table('establishments', function (Blueprint $table): void {
            $table->dropColumn('embedded_at');
        });
    }
};
