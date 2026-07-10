<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Tâches planifiées (§2.1, EF-07.4)
|--------------------------------------------------------------------------
| Le conteneur horizon exécute schedule:work ; les heures sont en UTC.
*/

// Liens d'export morts à 7 jours → fichiers purgés chaque nuit.
Schedule::command('fbde:exports:purge')->dailyAt('03:00');

// Stock SIRENE publié par l'INSEE en début de mois → re-import le 5 à 2 h
// (sans chevauchement : l'import national dure plusieurs heures).
Schedule::command('fbde:sirene:refresh')->monthlyOn(5, '02:00')->withoutOverlapping();

// Re-crawl à fenêtre 90 jours (EF-05.5) : chaque nuit, un lot de fiches
// jamais crawlées ou dont le crawl a plus de 90 jours.
Schedule::command('fbde:crawl --stale=90 --limit=500')->dailyAt('04:00')->withoutOverlapping();

// Rescoring quotidien après crawl/enrichissement : les scores suivent la donnée.
Schedule::command('fbde:score')->dailyAt('05:00')->withoutOverlapping();
