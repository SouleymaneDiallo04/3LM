<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\SearchEstablishmentsRequest;
use App\Jobs\GenerateExportJob;
use App\Models\Export;
use App\Services\Export\ExportGenerator;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Exports asynchrones (§7, EF-07) : création en tâche de fond, suivi,
 * téléchargement avec expiration à 7 jours (EF-07.4) ; chaque export
 * et chaque téléchargement sont journalisés (EF-07.5 — RGPD).
 */
class ExportController extends Controller
{
    /** Crée un export du résultat filtré courant (EF-07.1). */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'format' => ['required', 'in:csv,xlsx,json,xml,sql'], // EF-07.2
            'filters' => ['sometimes', 'array'],
            'columns' => ['sometimes', 'array', 'min:1'],
            'columns.*' => [Rule::in(ExportGenerator::availableColumns())],
        ]);

        // Les filtres suivent les mêmes règles que la recherche.
        $filters = validator(
            $validated['filters'] ?? [],
            (new SearchEstablishmentsRequest)->rules(),
        )->validate();

        $export = Export::create([
            'user_id' => $request->user()->id,
            'format' => $validated['format'],
            'filters' => $filters,
            'columns' => $validated['columns'] ?? null,
            'status' => 'pending',
        ]);

        GenerateExportJob::dispatch($export);

        // Traçabilité RGPD (EF-07.5).
        Audit::log('export.requested', $export, [
            'format' => $export->format,
            'filters' => $filters,
        ]);

        return response()->json(['data' => [
            'id' => $export->id,
            'status' => $export->status,
        ]], 202);
    }

    /** Statut et progression d'un export. */
    public function show(Request $request, Export $export): JsonResponse
    {
        $this->authorizeOwner($request, $export);

        return response()->json(['data' => [
            'id' => $export->id,
            'format' => $export->format,
            'status' => $export->status,
            'rows_count' => $export->rows_count,
            'expires_at' => $export->expires_at?->toIso8601String(),
            'error' => $export->error,
        ]]);
    }

    /** Téléchargement du fichier — lien expirant (EF-07.4). */
    public function download(Request $request, Export $export): BinaryFileResponse
    {
        $this->authorizeOwner($request, $export);

        abort_if($export->status !== 'completed', 409, 'Export non terminé.');
        abort_if($export->isExpired(), 410, 'Lien de téléchargement expiré (7 jours).');
        abort_if(
            $export->file_path === null || ! Storage::disk('local')->exists($export->file_path),
            404,
            'Fichier introuvable.',
        );

        Audit::log('export.downloaded', $export, ['rows' => $export->rows_count]);

        return response()->download(
            Storage::disk('local')->path($export->file_path),
            'fbde_export_'.$export->id.'.'.$export->format,
        );
    }

    /** Un export n'est visible que par son auteur. */
    private function authorizeOwner(Request $request, Export $export): void
    {
        abort_if($export->user_id !== $request->user()->id, 403);
    }
}
