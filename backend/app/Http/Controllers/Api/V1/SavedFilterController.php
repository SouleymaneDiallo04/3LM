<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SavedFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Filtres sauvegardés et partagés (EF-03.4) : chacun voit les siens et
 * ceux partagés par l'équipe ; seul le propriétaire supprime.
 */
class SavedFilterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        return response()->json([
            'data' => SavedFilter::query()
                ->with('user:id,name')
                ->where(fn ($q) => $q->where('user_id', $userId)->orWhere('is_shared', true))
                ->orderByDesc('id')
                ->get()
                ->map(fn (SavedFilter $filter): array => [
                    'id' => $filter->id,
                    'name' => $filter->name,
                    'criteria' => $filter->criteria,
                    'is_shared' => $filter->is_shared,
                    'is_owner' => $filter->user_id === $userId,
                    'owner' => $filter->user->name,
                ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'criteria' => ['required', 'array'],
            'is_shared' => ['sometimes', 'boolean'],
        ]);

        $filter = SavedFilter::create([
            'user_id' => $request->user()->id,
            'name' => $validated['name'],
            'criteria' => $validated['criteria'],
            'is_shared' => $validated['is_shared'] ?? false,
        ]);

        return response()->json(['data' => ['id' => $filter->id]], 201);
    }

    public function destroy(Request $request, SavedFilter $savedFilter): Response
    {
        abort_unless($savedFilter->user_id === $request->user()->id, 403);

        $savedFilter->delete();

        return response()->noContent();
    }
}
