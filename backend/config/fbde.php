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
];
