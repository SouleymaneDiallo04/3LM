<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Establishment;
use App\Services\Ai\CompanySummarizer;
use App\Services\Ai\Exceptions\AiGenerationDenied;
use App\Services\Ai\PitchGenerator;
use App\Services\Ai\Prompts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Générations IA (EF-08.2) : résumé d'entreprise stocké. Refus RGPD → 422 ;
 * panne du fournisseur → 503.
 */
class AiController extends Controller
{
    public function summary(Request $request, Establishment $establishment, CompanySummarizer $summarizer): JsonResponse
    {
        // Garde RGPD évaluée AVANT le cache : une opposition postérieure à la
        // génération (fiche non-diffusible ou entrée en liste d'exclusion) doit
        // stopper la diffusion du résumé mémorisé, refresh ou non.
        try {
            $summarizer->assertAllowed($establishment);
        } catch (AiGenerationDenied $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

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
        } catch (Throwable $e) {
            // Message client neutre ; on trace sans exposer d'identifiants ni de clé.
            Log::warning('Échec de génération du résumé IA', [
                'establishment_id' => $establishment->id,
                'exception' => $e::class,
            ]);

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

    public function pitch(Request $request, Establishment $establishment, PitchGenerator $generator): JsonResponse
    {
        $validated = $request->validate([
            'channel' => ['required', 'in:email,call'],
        ]);

        try {
            $pitch = $generator->generate($establishment, $validated['channel']);
        } catch (AiGenerationDenied $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            // Message client neutre ; on trace sans exposer d'identifiants ni de clé.
            Log::warning('Échec de génération de l\'argumentaire IA', [
                'establishment_id' => $establishment->id,
                'exception' => $e::class,
            ]);

            return response()->json(['message' => 'Service IA momentanément indisponible.'], 503);
        }

        return response()->json(['data' => [
            'pitch' => $pitch,
            'channel' => $validated['channel'],
            'version' => Prompts::VERSION,
        ]]);
    }
}
