<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Establishment;
use App\Services\Ai\CompanySummarizer;
use App\Services\Ai\Exceptions\AiGenerationDenied;
use App\Services\Ai\Prompts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Générations IA (EF-08.2/08.4) : résumé (stocké) et argumentaire (à la
 * demande). Refus RGPD → 422 ; panne du fournisseur → 503.
 */
class AiController extends Controller
{
    public function summary(Request $request, Establishment $establishment, CompanySummarizer $summarizer): JsonResponse
    {
        // Cache : renvoyé tel quel sauf ?refresh.
        if ($establishment->ai_summary !== null && ! $request->boolean('refresh')) {
            return response()->json(['data' => [
                'summary' => $establishment->ai_summary,
                'version' => $establishment->ai_summary_version,
                'generated_at' => $establishment->ai_summary_at?->toIso8601String(),
            ]]);
        }

        try {
            $summary = $summarizer->summarize($establishment);
        } catch (AiGenerationDenied $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable) {
            return response()->json(['message' => 'Service IA momentanément indisponible.'], 503);
        }

        $establishment->update([
            'ai_summary' => $summary,
            'ai_summary_version' => Prompts::VERSION,
            'ai_summary_at' => now(),
        ]);

        return response()->json(['data' => [
            'summary' => $summary,
            'version' => Prompts::VERSION,
            'generated_at' => now()->toIso8601String(),
        ]]);
    }
}
