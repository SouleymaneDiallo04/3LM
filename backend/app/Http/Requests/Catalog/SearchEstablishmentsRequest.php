<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Recherche multicritères (EF-01, EF-03.1a) : combinaison libre des
 * critères ; le rayon (EF-01.3) exige un point central complet.
 */
class SearchEstablishmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // l'autorisation est portée par la permission companies.view
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'department.exists' => 'Département inconnu — utilisez le code INSEE (ex. 33, 75, 2A).',
            'region.exists' => 'Région inconnue.',
            'min_rating.between' => 'La note minimale doit être comprise entre 1 et 5.',
            'radius_km.between' => 'Le rayon doit être compris entre 1 et 100 km.',
        ];
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'min:2', 'max:100'],
            'department' => ['sometimes', 'string', 'exists:departments,code'],
            'region' => ['sometimes', 'string', 'exists:regions,code'], // EF-01.2

            'city' => ['sometimes', 'string', 'max:100'],
            'postal_code' => ['sometimes', 'string', 'max:5'],
            'naf' => ['sometimes', 'string', 'max:6'],
            'status' => ['sometimes', 'in:active,ceased,all'],
            'has_email' => ['sometimes', 'boolean'],
            'has_phone' => ['sometimes', 'boolean'],
            'has_website' => ['sometimes', 'boolean'],
            // Recherche avancée (CDC) : note, volume d'avis, taille.
            'min_rating' => ['sometimes', 'numeric', 'between:1,5'],
            'min_reviews' => ['sometimes', 'integer', 'min:1'],
            // Tranches d'effectif SIRENE — l'encodage INSEE est ordonné.
            'min_employees' => ['sometimes', 'in:01,02,03,11,12,21,22,31,32,41,42,51,52,53'],
            // Rayon géographique 5-100 km (EF-01.3).
            'lat' => ['required_with:radius_km', 'numeric', 'between:-90,90'],
            'lng' => ['required_with:radius_km', 'numeric', 'between:-180,180'],
            'radius_km' => ['sometimes', 'numeric', 'between:1,100'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'string'],
            // Tri (EF-03.6) : nom, date d'ajout, score commercial, note.
            'sort' => ['sometimes', 'in:name,imported_at,commercial_score,rating'],
            'direction' => ['sometimes', 'in:asc,desc'],
        ];
    }
}
