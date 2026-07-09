<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Search;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Historique des recherches de l'utilisateur (EF-01.6) : mot-clé, zone,
 * rayon, date, nombre de résultats.
 */
class SearchHistoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return JsonResource::collection(
            Search::query()
                ->where('user_id', $request->user()->id)
                ->latest('id')
                ->cursorPaginate(25),
        );
    }
}
