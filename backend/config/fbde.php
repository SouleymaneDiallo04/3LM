<?php

/*
|--------------------------------------------------------------------------
| Réglages métier FBDE
|--------------------------------------------------------------------------
*/

return [
    // Carte (EF-06.3, correctif 16) : nombre maximal de points servis pour
    // une emprise ; au-delà, l'API bascule sur des agrégats par cellule.
    'map_points_limit' => (int) env('FBDE_MAP_POINTS_LIMIT', 2000),

    // Re-import mensuel SIRENE (§2.1) : les URLs des stocks sont résolues
    // via l'API data.gouv, jamais en dur (liens directs non fiables).
    'sirene_dataset_api' => env(
        'FBDE_SIRENE_DATASET_API',
        'https://www.data.gouv.fr/api/1/datasets/base-sirene-des-entreprises-et-de-leurs-etablissements-siren-siret/',
    ),

    // Périmètre de l'import planifié : un département (ex. « 33 ») en
    // environnement contraint, null = France entière.
    'import_department' => env('FBDE_IMPORT_DEPARTMENT'),

    // Enrichissement OSM (§5.4) : instance Overpass — auto-héberger en
    // production (politique d'usage des instances publiques).
    'overpass_api' => env('FBDE_OVERPASS_API', 'https://overpass-api.de/api/interpreter'),

    // Crawler (EF-05) : validation MX de l'email extrait (EF-05.6) —
    // désactivée en test pour ne pas dépendre du DNS.
    'crawl' => [
        'validate_mx' => (bool) env('FBDE_CRAWL_VALIDATE_MX', true),
    ],

    // Scoring déterministe (EF-08.1, note de cadrage) : pondérations
    // VERSIONNÉES — toute modification incrémente la version, qui est
    // tracée dans score_factors de chaque fiche scorée.
    'scoring' => [
        'version' => '1.0.0',
        // Classement automatique (CDC §14) : paliers déterministes dérivés du
        // score, seuil minimal par lettre — versionné avec le reste du scoring.
        'tiers' => [
            'A' => 70,
            'B' => 40,
            'C' => 20,
            'D' => 0,
        ],
        'reputation' => [
            'rating_max_points' => 70,   // note JSON-LD ramenée sur 70
            'volume_max_points' => 30,   // volume d'avis, échelle log
            'reference_reviews' => 200,  // volume atteignant le plafond
        ],
        'commercial' => [
            'email' => 15,
            'phone' => 15,
            'website' => 10,
            'geolocated' => 10,
            'employee_range' => 10,
            'opening_hours' => 10,
            'reputation_ratio' => 0.30,  // indice réputation × 0,30 (max 30)
        ],
    ],
];
