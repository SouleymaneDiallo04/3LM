<?php

namespace App\Services\Geocoding\Contracts;

/**
 * Géocodeur d'adresses (§5.3 : implémentations substituables).
 * Stratégie France (note de cadrage) : BAN en source primaire,
 * Nominatim auto-hébergé en repli.
 */
interface Geocoder
{
    /**
     * Géocode un lot d'adresses.
     *
     * @param  list<array{id: string, address: ?string, postcode: ?string, city: ?string, city_code: ?string}>  $addresses
     * @return array<string, array{longitude: float, latitude: float, score: float}> résultats indexés par id (absents si non appariés)
     */
    public function geocodeBatch(array $addresses): array;
}
