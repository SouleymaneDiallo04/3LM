<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Validation\Validator;

/**
 * Carte par emprise (EF-06.3, correctif 16) : mêmes filtres que la
 * recherche, plus une emprise obligatoire « minLon,minLat,maxLon,maxLat »
 * — la carte ne charge jamais toute la base.
 */
class MapSearchRequest extends SearchEstablishmentsRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'bbox' => ['required', 'string', 'regex:/^-?\d{1,3}(\.\d+)?(,-?\d{1,3}(\.\d+)?){3}$/'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('bbox')) {
                    return;
                }

                [$minLon, $minLat, $maxLon, $maxLat] = $this->bbox();

                $valid = $minLon >= -180 && $maxLon <= 180
                    && $minLat >= -90 && $maxLat <= 90
                    && $minLon < $maxLon && $minLat < $maxLat;

                if (! $valid) {
                    $validator->errors()->add(
                        'bbox',
                        'Emprise invalide : attendu minLon,minLat,maxLon,maxLat avec min < max.',
                    );
                }
            },
        ];
    }

    /**
     * Emprise décodée : [minLon, minLat, maxLon, maxLat].
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function bbox(): array
    {
        return array_map(floatval(...), explode(',', (string) $this->input('bbox')));
    }
}
