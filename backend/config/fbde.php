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
];
