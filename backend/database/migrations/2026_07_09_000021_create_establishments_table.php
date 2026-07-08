<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Établissements SIRENE (§4.2, §6.1 transposés France — ADR 0003).
 * Une ligne par SIRET. C'est l'entité prospectée : adresse physique,
 * géolocalisation PostGIS, enrichissements OSM/crawling, scores.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('establishments', function (Blueprint $table): void {
            $table->id();

            // Identifiant national unique — clé de déduplication forte (EF-04.1).
            $table->char('siret', 14)->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('name')->nullable(); // enseigne / dénomination usuelle
            $table->string('normalized_name')->nullable();
            $table->boolean('is_headquarters')->default(false); // siège social
            $table->string('status', 10)->default('unknown')->index(); // active / ceased / unknown
            $table->string('naf_code', 6)->nullable()->index();
            $table->string('employee_range', 5)->nullable();

            // Adresse normalisée (§4.2).
            $table->string('address_line')->nullable();
            $table->string('postal_code', 5)->nullable()->index();
            $table->string('city')->nullable()->index();
            $table->string('city_code', 5)->nullable()->index(); // code commune INSEE
            $table->string('department_code', 3)->nullable()->index();

            // Géolocalisation — recherche par rayon (EF-01.3, §9.2).
            $table->geography('location', subtype: 'point', srid: 4326)->nullable();
            $table->string('geo_source', 20)->nullable(); // sirene / ban / nominatim
            $table->decimal('geo_score', 4, 3)->nullable(); // score d'appariement BAN

            // Contact (§4.2) — sources : SIRENE, OSM, crawling.
            $table->string('phone', 20)->nullable(); // format E.164
            $table->string('website')->nullable();
            $table->string('email')->nullable(); // validé syntaxe + MX (EF-05.6)

            // Enrichissements (§4.2) — OSM et crawling.
            $table->jsonb('opening_hours')->nullable();
            $table->jsonb('social_links')->nullable(); // {linkedin, facebook, instagram}
            $table->jsonb('technologies')->nullable(); // {cms, libs[]}
            $table->text('description')->nullable(); // meta / Open Graph du site

            // Note et avis : champ conservé, alimentation JSON-LD au crawl,
            // source explicite (note de cadrage France — pas de Google).
            $table->decimal('rating', 2, 1)->nullable();
            $table->unsignedInteger('reviews_count')->nullable();
            $table->string('rating_source', 20)->nullable(); // json-ld / (v2 : API dédiée)

            // Indice de réputation déterministe 0-100 (note de cadrage France).
            $table->unsignedSmallInteger('reputation_score')->nullable();

            // Score commercial déterministe 0-100 + facteurs explicables (EF-08.1).
            $table->unsignedSmallInteger('commercial_score')->nullable();
            $table->jsonb('score_factors')->nullable();

            // Traçabilité complète des collectes (§6.1).
            $table->string('source', 20)->default('sirene');
            $table->timestampTz('imported_at')->nullable();
            $table->timestampTz('enriched_at')->nullable();
            $table->timestampTz('crawled_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('department_code')->references('code')->on('departments');
        });

        // Index GiST — ST_DWithin sur la recherche par rayon (§9.2).
        DB::statement('CREATE INDEX establishments_location_gist ON establishments USING gist (location)');

        // Similarité trigramme (EF-04.2 : détection de doublons de second niveau).
        DB::statement(
            'CREATE INDEX establishments_normalized_name_trgm
             ON establishments USING gin (normalized_name gin_trgm_ops)',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('establishments');
    }
};
