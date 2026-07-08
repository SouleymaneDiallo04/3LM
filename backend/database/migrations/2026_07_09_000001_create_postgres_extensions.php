<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extensions PostgreSQL requises par le modèle de données (§5.2, §6) :
 * - postgis : recherche par rayon (ST_DWithin + index GiST) ;
 * - pg_trgm : similarité trigramme des noms normalisés (EF-04.2, EF-03.2).
 *
 * pgvector (EF-08.3) sera ajouté en phase 5 (cf. ADR 0003).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
    }

    public function down(): void
    {
        // Les extensions sont partagées : on ne les supprime pas au rollback.
    }
};
