<?php

namespace App\Services\Ingestion;

use Illuminate\Support\Str;

/**
 * Normalisation des dénominations (EF-04.2) : minuscules, sans accents,
 * sans forme juridique — base de la similarité trigramme.
 */
class NameNormalizer
{
    /** Formes juridiques et mentions retirées du nom normalisé. */
    private const LEGAL_FORMS = [
        'sarl', 'sas', 'sasu', 'eurl', 'sa', 'sci', 'scp', 'scm', 'snc',
        'selarl', 'selas', 'earl', 'gaec', 'gie', 'ei', 'eirl',
        'societe par actions simplifiee', 'societe a responsabilite limitee',
        'societe anonyme', 'societe civile immobiliere', 'auto-entrepreneur',
        'micro-entreprise',
    ];

    public function normalize(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        // Minuscules + translittération des accents.
        $normalized = Str::lower(Str::ascii(trim($name), 'fr'));

        // Ponctuation → espaces, espaces multiples réduits.
        $normalized = (string) preg_replace('/[^a-z0-9 ]+/', ' ', $normalized);
        $normalized = (string) preg_replace('/\s+/', ' ', $normalized);
        $normalized = trim($normalized);

        // Formes juridiques en tête ou en queue de dénomination.
        $pattern = '/^(?:'.implode('|', self::LEGAL_FORMS).')\s+|\s+(?:'
            .implode('|', self::LEGAL_FORMS).')$/';

        do {
            $before = $normalized;
            $normalized = trim((string) preg_replace($pattern, '', $normalized));
        } while ($normalized !== $before && $normalized !== '');

        // Un nom réduit à une forme juridique seule n'est pas exploitable.
        if ($normalized === '' || in_array($normalized, self::LEGAL_FORMS, true)) {
            return null;
        }

        return $normalized;
    }
}
