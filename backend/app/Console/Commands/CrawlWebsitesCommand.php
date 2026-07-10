<?php

namespace App\Console\Commands;

use App\Models\Establishment;
use App\Models\Import;
use App\Services\Crawl\WebsiteCrawler;
use Illuminate\Console\Command;
use Throwable;

/**
 * Crawl des sites d'entreprises (EF-05) : fiches actives avec site web,
 * jamais crawlées ou dont le crawl date de plus de --stale jours
 * (EF-05.5 : re-crawl à 90 jours). Tracé dans imports.
 */
class CrawlWebsitesCommand extends Command
{
    protected $signature = 'fbde:crawl
        {--department= : Limiter à un département (ex. 33)}
        {--limit=50 : Nombre maximal de fiches par exécution}
        {--stale=90 : Recrawler au-delà de N jours (EF-05.5)}';

    protected $description = 'Crawle les sites web des établissements (emails génériques, réseaux, note JSON-LD)';

    public function handle(WebsiteCrawler $crawler): int
    {
        $stale = (int) $this->option('stale');

        $establishments = Establishment::query()
            ->where('status', 'active')
            ->whereNotNull('website')
            ->where(fn ($q) => $q->whereNull('crawled_at')
                ->orWhere('crawled_at', '<', now()->subDays($stale)))
            ->when($this->option('department'), fn ($q, $d) => $q->where('department_code', $d))
            ->orderByRaw('crawled_at ASC NULLS FIRST')
            ->limit((int) $this->option('limit'))
            ->get();

        $import = Import::create([
            'source' => 'crawl',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $stats = ['crawled' => 0, 'disallowed' => 0, 'errors' => 0, 'fields' => 0];

        try {
            foreach ($establishments as $establishment) {
                $result = $crawler->crawl($establishment);

                match ($result['status']) {
                    'crawled' => $stats['crawled']++,
                    'disallowed' => $stats['disallowed']++,
                    default => $stats['errors']++,
                };
                $stats['fields'] += $result['fields'];
            }

            $this->table(
                ['crawlés', 'interdits (robots)', 'erreurs', 'champs complétés'],
                [[$stats['crawled'], $stats['disallowed'], $stats['errors'], $stats['fields']]],
            );

            $import->update([
                'status' => 'completed',
                'finished_at' => now(),
                'stats' => $stats,
            ]);
        } catch (Throwable $exception) {
            $import->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        return self::SUCCESS;
    }
}
