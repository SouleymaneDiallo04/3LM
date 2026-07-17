<?php

namespace App\Services\Ai;

use App\Models\Establishment;
use App\Services\Ai\Contracts\EmbeddingClient;
use App\Services\Ai\Exceptions\AiGenerationDenied;
use Illuminate\Support\Facades\DB;

class CompanyEmbedder
{
    public function __construct(
        private readonly EmbeddingClient $client,
        private readonly Prompts $prompts,
        private readonly ProspectGuard $guard,
    ) {}

    /** Génère l'embedding (garde RGPD) et le stocke en base. */
    public function embed(Establishment $establishment): void
    {
        $this->guard->assertAllowed($establishment);

        $vector = $this->client->embed($this->prompts->embeddingText($establishment));
        $literal = '['.implode(',', $vector).']';

        DB::update(
            'UPDATE establishments SET embedding = ?::vector, embedded_at = now() WHERE id = ?',
            [$literal, $establishment->id],
        );
    }

    /**
     * Embedde un lot en UN SEUL appel API (backfill de masse). La garde RGPD
     * reste évaluée fiche par fiche : les refusées sont écartées du lot et
     * comptées « ignorées », jamais envoyées au fournisseur.
     *
     * @param  iterable<Establishment>  $establishments
     * @return array{generated: int, skipped: int}
     */
    public function embedMany(iterable $establishments): array
    {
        $allowed = [];
        $skipped = 0;

        foreach ($establishments as $establishment) {
            try {
                $this->guard->assertAllowed($establishment);
                $allowed[] = $establishment;
            } catch (AiGenerationDenied) {
                $skipped++;
            }
        }

        if ($allowed === []) {
            return ['generated' => 0, 'skipped' => $skipped];
        }

        $vectors = $this->client->embedMany(
            array_map(fn (Establishment $e) => $this->prompts->embeddingText($e), $allowed),
        );

        foreach ($allowed as $i => $establishment) {
            DB::update(
                'UPDATE establishments SET embedding = ?::vector, embedded_at = now() WHERE id = ?',
                ['['.implode(',', $vectors[$i]).']', $establishment->id],
            );
        }

        return ['generated' => count($allowed), 'skipped' => $skipped];
    }
}
