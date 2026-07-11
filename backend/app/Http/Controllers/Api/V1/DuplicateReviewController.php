<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DuplicateReview;
use App\Models\Establishment;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Revue manuelle des doublons (EF-04.2) : lister les paires en attente,
 * fusionner (le survivant récupère les champs manquants, le doublon est
 * archivé) ou marquer distinctes. Réservé à duplicates.review.
 */
class DuplicateReviewController extends Controller
{
    /** Champs d'enrichissement transférables du doublon vers le survivant. */
    private const MERGEABLE = [
        'phone', 'website', 'email', 'opening_hours', 'social_links',
        'description', 'contact_form_url', 'technologies',
        'rating', 'reviews_count', 'rating_source',
    ];

    public function index(): JsonResponse
    {
        $pairs = DuplicateReview::query()
            ->where('status', 'pending')
            ->with(['establishmentA', 'establishmentB'])
            ->orderByDesc('similarity')
            ->limit(50)
            ->get()
            ->map(fn (DuplicateReview $r): array => [
                'id' => $r->id,
                'similarity' => $r->similarity,
                'a' => $this->summary($r->establishmentA),
                'b' => $this->summary($r->establishmentB),
            ]);

        return response()->json(['data' => $pairs]);
    }

    public function merge(Request $request, DuplicateReview $duplicateReview): JsonResponse
    {
        abort_unless($duplicateReview->status === 'pending', 409, 'Paire déjà traitée.');

        $keepId = (int) $request->input('keep_id');
        $pair = [$duplicateReview->establishment_a_id, $duplicateReview->establishment_b_id];

        if (! in_array($keepId, $pair, true)) {
            throw ValidationException::withMessages([
                'keep_id' => 'Le survivant doit être l\'un des deux établissements de la paire.',
            ]);
        }

        $loserId = $keepId === $pair[0] ? $pair[1] : $pair[0];

        DB::transaction(function () use ($keepId, $loserId, $duplicateReview, $request): void {
            $keep = Establishment::lockForUpdate()->findOrFail($keepId);
            $loser = Establishment::lockForUpdate()->findOrFail($loserId);

            // Précédence par champ : le survivant ne récupère que ses trous.
            $fill = [];
            foreach (self::MERGEABLE as $field) {
                if ($keep->{$field} === null && $loser->{$field} !== null) {
                    $fill[$field] = $loser->{$field};
                }
            }
            if ($fill !== []) {
                $keep->update($fill);
            }

            $loser->delete(); // soft-delete : réversible, traçable

            $duplicateReview->update([
                'status' => 'merged',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            Audit::log('duplicate.merged', $keep, [
                'review_id' => $duplicateReview->id,
                'merged_siret' => $loser->siret,
            ]);
        });

        return response()->json(['data' => ['status' => 'merged']]);
    }

    public function distinct(Request $request, DuplicateReview $duplicateReview): JsonResponse
    {
        abort_unless($duplicateReview->status === 'pending', 409, 'Paire déjà traitée.');

        $duplicateReview->update([
            'status' => 'distinct',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        Audit::log('duplicate.distinct', null, ['review_id' => $duplicateReview->id]);

        return response()->json(['data' => ['status' => 'distinct']]);
    }

    /** @return array<string, mixed> */
    private function summary(?Establishment $e): array
    {
        if ($e === null) {
            return ['deleted' => true];
        }

        return [
            'id' => $e->id,
            'siret' => $e->siret,
            'name' => $e->name,
            'address' => trim(($e->address_line ?? '').' '.($e->postal_code ?? '').' '.($e->city ?? '')),
            'naf_code' => $e->naf_code,
            'phone' => $e->phone,
            'email' => $e->email,
            'website' => $e->website,
            // Complétude : aide le relecteur à choisir le survivant.
            'filled_fields' => collect(self::MERGEABLE)->filter(fn ($f) => $e->{$f} !== null)->count(),
        ];
    }
}
