<?php

namespace App\Services\Crawl;

use App\Models\Establishment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Crawler de sites d'entreprises (EF-05) : page d'accueil uniquement,
 * robots.txt strict, au plus une requête par seconde et par domaine.
 * Extraction : emails GÉNÉRIQUES seulement (RGPD par défaut — les
 * nominatifs sont écartés), réseaux sociaux, note JSON-LD
 * (aggregateRating du site : la source de notes actée avec 3LM).
 */
class WebsiteCrawler
{
    private const USER_AGENT = 'FBDEBot/1.0 (+prospection B2B ; respecte robots.txt)';

    /** Préfixes d'emails considérés génériques (RGPD : jamais de nominatif). */
    private const GENERIC_PREFIXES = [
        'contact', 'info', 'infos', 'hello', 'bonjour', 'accueil', 'commercial',
        'ventes', 'vente', 'sales', 'support', 'service', 'services', 'direction',
        'secretariat', 'administration', 'admin', 'agence', 'bureau', 'boutique',
        'magasin', 'reservation', 'reservations', 'commande', 'commandes', 'devis',
        'rh', 'recrutement', 'compta', 'comptabilite', 'facturation', 'courrier',
        'mail', 'email', 'contactez-nous', 'entreprise', 'societe', 'office',
    ];

    /** Dernière requête par domaine (1 req/s/domaine, EF-05). */
    private array $lastHitAt = [];

    /**
     * @return array{status: string, fields: int}
     */
    public function crawl(Establishment $establishment): array
    {
        $url = $establishment->website;
        $host = parse_url((string) $url, PHP_URL_HOST);

        if ($host === null || $host === false) {
            $establishment->update(['crawled_at' => now()]);

            return ['status' => 'invalid_url', 'fields' => 0];
        }

        $scheme = parse_url((string) $url, PHP_URL_SCHEME) ?: 'https';

        if (! $this->allowedByRobots($scheme, $host)) {
            // Marqué crawlé : un site interdit ne doit pas être retenté en boucle.
            $establishment->update(['crawled_at' => now()]);

            return ['status' => 'disallowed', 'fields' => 0];
        }

        try {
            $this->throttle($host);
            $html = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(20)
                ->get($url)
                ->throw()
                ->body();
        } catch (Throwable) {
            $establishment->update(['crawled_at' => now()]);

            return ['status' => 'error', 'fields' => 0];
        }

        $html = substr($html, 0, 800_000);
        $updates = [];

        // Précédence par champ (correctif 15) : compléter, jamais écraser.
        if ($establishment->email === null
            && ($email = $this->extractGenericEmail($html)) !== null) {
            $updates['email'] = $email;
        }

        if ($establishment->social_links === null
            && ($social = $this->extractSocialLinks($html)) !== []) {
            $updates['social_links'] = $social;
        }

        // La note vient exclusivement du JSON-LD du site : rafraîchie à chaque
        // crawl (même source, valeur la plus fraîche).
        if (($rating = $this->extractJsonLdRating($html)) !== null) {
            $updates['rating'] = $rating['value'];
            $updates['reviews_count'] = $rating['count'];
            $updates['rating_source'] = 'site_jsonld';
        }

        $establishment->update($updates + ['crawled_at' => now()]);

        return ['status' => 'crawled', 'fields' => count($updates)];
    }

    /**
     * robots.txt strict : 404 = autorisé, 5xx ou injoignable = interdit ;
     * les règles Disallow des groupes « * » et « fbdebot » s'appliquent à
     * la racine (seule page visitée). Décision mise en cache une heure.
     */
    private function allowedByRobots(string $scheme, string $host): bool
    {
        return Cache::remember("crawl:robots:{$host}", now()->addHour(), function () use ($scheme, $host): bool {
            try {
                $this->throttle($host);
                $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                    ->timeout(10)
                    ->get("{$scheme}://{$host}/robots.txt");
            } catch (Throwable) {
                return false; // strict : injoignable = on ne crawle pas
            }

            if ($response->status() === 404 || $response->status() === 403) {
                return true;
            }
            if (! $response->successful()) {
                return false;
            }

            $applies = false;

            foreach (preg_split('/\r?\n/', $response->body()) as $line) {
                $line = trim(preg_replace('/#.*$/', '', $line) ?? '');
                if ($line === '') {
                    continue;
                }

                if (preg_match('/^user-agent\s*:\s*(.+)$/i', $line, $m) === 1) {
                    $agent = mb_strtolower(trim($m[1]));
                    $applies = $agent === '*' || str_contains('fbdebot', $agent);

                    continue;
                }

                if ($applies && preg_match('/^disallow\s*:\s*(.*)$/i', $line, $m) === 1) {
                    $rule = trim($m[1]);
                    if ($rule !== '' && str_starts_with('/', $rule)) {
                        return false; // la racine est couverte par la règle
                    }
                }
            }

            return true;
        });
    }

    /** Au plus une requête par seconde et par domaine (EF-05). */
    private function throttle(string $host): void
    {
        $wait = ($this->lastHitAt[$host] ?? 0.0) + 1.0 - microtime(true);

        if ($wait > 0) {
            usleep((int) ceil($wait * 1_000_000));
        }

        $this->lastHitAt[$host] = microtime(true);
    }

    private function extractGenericEmail(string $html): ?string
    {
        preg_match_all('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $html, $matches);

        foreach (array_unique($matches[0]) as $email) {
            $local = mb_strtolower(explode('@', $email)[0]);
            $local = preg_replace('/\+.*$/', '', $local);

            if (in_array($local, self::GENERIC_PREFIXES, true)) {
                return mb_strtolower($email);
            }
        }

        return null;
    }

    /** @return array<string, string> */
    private function extractSocialLinks(string $html): array
    {
        $patterns = [
            'facebook' => '#https?://(?:www\.)?facebook\.com/[\w.\-/]+#i',
            'linkedin' => '#https?://(?:www\.)?linkedin\.com/(?:company|in)/[\w\-]+#i',
            'instagram' => '#https?://(?:www\.)?instagram\.com/[\w.\-]+#i',
            'twitter' => '#https?://(?:www\.)?(?:twitter|x)\.com/[\w]+#i',
        ];

        $links = [];

        foreach ($patterns as $network => $pattern) {
            if (preg_match($pattern, $html, $m) === 1) {
                $links[$network] = $m[0];
            }
        }

        return $links;
    }

    /** @return array{value: float, count: int}|null */
    private function extractJsonLdRating(string $html): ?array
    {
        preg_match_all(
            '#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#si',
            $html,
            $matches,
        );

        foreach ($matches[1] as $block) {
            $decoded = json_decode(html_entity_decode($block), true);
            if (! is_array($decoded)) {
                continue;
            }

            $node = $this->findAggregateRating($decoded);
            if ($node === null) {
                continue;
            }

            $value = (float) ($node['ratingValue'] ?? 0);
            $count = (int) ($node['reviewCount'] ?? $node['ratingCount'] ?? 0);

            if ($value > 0 && $value <= 5) {
                return ['value' => $value, 'count' => $count];
            }
        }

        return null;
    }

    private function findAggregateRating(array $node): ?array
    {
        if (isset($node['aggregateRating']) && is_array($node['aggregateRating'])) {
            return $node['aggregateRating'];
        }

        foreach ($node as $value) {
            if (is_array($value) && ($found = $this->findAggregateRating($value)) !== null) {
                return $found;
            }
        }

        return null;
    }
}
