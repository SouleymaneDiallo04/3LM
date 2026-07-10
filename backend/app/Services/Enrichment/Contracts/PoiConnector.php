<?php

namespace App\Services\Enrichment\Contracts;

/**
 * Connecteur de points d'intérêt (§5.4, phase 4) : chaque source
 * d'enrichissement (OSM aujourd'hui, autre demain) est substituable et
 * testable par doublure — même isolation que le registre (§5.3).
 *
 * Les tableaux produits sont normalisés au vocabulaire interne :
 * name, normalized_name, latitude, longitude, phone, website,
 * opening_hours (chaîne brute ou null).
 */
interface PoiConnector
{
    /** @return iterable<int, array<string, mixed>> */
    public function pois(): iterable;
}
