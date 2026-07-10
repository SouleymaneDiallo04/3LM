<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Formulaire de contact détecté au crawl (§8) : URL de la page contact
 * du site — alternative RGPD-propre quand aucun email générique n'existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('establishments', function (Blueprint $table): void {
            $table->string('contact_form_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('establishments', function (Blueprint $table): void {
            $table->dropColumn('contact_form_url');
        });
    }
};
