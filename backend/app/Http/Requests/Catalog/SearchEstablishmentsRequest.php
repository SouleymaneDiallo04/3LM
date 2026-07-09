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

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'min:2', 'max:100'],
            'department' => ['sometimes', 'string', 'exists:departments,code'],
            'city' => ['sometimes', 'string', 'max:100'],
            'postal_code' => ['sometimes', 'string', 'max:5'],
            'naf' => ['sometimes', 'string', 'max:6'],
            'status' => ['sometimes', 'in:active,ceased,all'],
            'has_email' => ['sometimes', 'boolean'],
            'has_phone' => ['sometimes', 'boolean'],
            'has_website' => ['sometimes', 'boolean'],
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
