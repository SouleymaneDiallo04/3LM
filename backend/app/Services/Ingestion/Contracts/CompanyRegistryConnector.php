<?php

namespace App\Services\Ingestion\Contracts;

/**
 * Connecteur de registre d'entreprises (§5.3) : chaque source (SIRENE
 * aujourd'hui, autre registre demain) est substituable et testable par
 * doublure — c'est cette isolation qui a permis le pivot Belgique → France.
 *
 * Les tableaux produits sont déjà normalisés au vocabulaire interne
 * (colonnes des tables companies / establishments).
 */
interface CompanyRegistryConnector
{
    /**
     * Unités légales normalisées, par lots.
     *
     * @return iterable<int, array<string, mixed>> lignes prêtes pour l'upsert companies
     */
    public function companies(): iterable;

    /**
     * Établissements normalisés, par lots.
     *
     * @return iterable<int, array<string, mixed>> lignes prêtes pour l'upsert establishments
     */
    public function establishments(): iterable;
}
