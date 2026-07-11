<?php

namespace App\Services\Crawl;

use App\Models\Establishment;
use Illuminate\Http\Client\Response;
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

        // Garde SSRF : ne jamais laisser une valeur du champ « website »
        // (issue de SIRENE ou d'OSM, éditable par des tiers) diriger le
        // serveur vers une cible interne — métadonnées cloud, réseau privé.
        if ($this->hostIsBlocked($host)) {
            $establishment->update(['crawled_at' => now()]);

            return ['status' => 'blocked', 'fields' => 0];
        }

        $scheme = parse_url((string) $url, PHP_URL_SCHEME) ?: 'https';

        if (! $this->allowedByRobots($scheme, $host)) {
            // Marqué crawlé : un site interdit ne doit pas être retenté en boucle.
            $establishment->update(['crawled_at' => now()]);

            return ['status' => 'disallowed', 'fields' => 0];
        }

        $response = $this->safeGet((string) $url);

        if ($response === null || ! $response->successful()) {
            $establishment->update(['crawled_at' => now()]);

            return ['status' => $response === null ? 'blocked' : 'error', 'fields' => 0];
        }

        $html = substr($response->body(), 0, 800_000);
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

        if ($establishment->description === null
            && ($description = $this->extractDescription($html)) !== null) {
            $updates['description'] = $description;
        }

        if ($establishment->contact_form_url === null
            && ($contactUrl = $this->extractContactFormUrl($html, $scheme, $host)) !== null) {
            $updates['contact_form_url'] = $contactUrl;
        }

        if ($establishment->technologies === null
            && ($technologies = $this->detectTechnologies($html)) !== null) {
            $updates['technologies'] = $technologies;
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
            $response = $this->safeGet("{$scheme}://{$host}/robots.txt", timeout: 10);

            if ($response === null) {
                return false; // strict : injoignable / bloqué = on ne crawle pas
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
                    // Seule la racine est visitée : elle est interdite ssi une
                    // règle couvre « / » (Disallow: / ). (Correctif : les
                    // arguments de str_starts_with étaient inversés.)
                    if (trim($m[1]) === '/') {
                        return false;
                    }
                }
            }

            return true;
        });
    }

    /**
     * GET protégé (garde SSRF) : redirections désactivées puis suivies à la
     * main (≤ 3 sauts), chaque hôte revalidé avant connexion — une
     * redirection vers une cible interne n'est jamais suivie. Renvoie null
     * si l'hôte est bloqué ou la requête échoue.
     */
    private function safeGet(string $url, int $maxRedirects = 3, int $timeout = 20): ?Response
    {
        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $host = parse_url($url, PHP_URL_HOST);
            if ($host === null || $host === false || $this->hostIsBlocked($host)) {
                return null;
            }

            try {
                $this->throttle($host);
                $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                    ->timeout($timeout)
                    ->withOptions(['allow_redirects' => false])
                    ->get($url);
            } catch (Throwable) {
                return null;
            }

            if (! $response->redirect()) {
                return $response;
            }

            $location = $response->header('Location');
            if ($location === '') {
                return $response;
            }
            // Résolution d'une éventuelle URL relative contre l'URL courante.
            $url = str_contains($location, '://')
                ? $location
                : rtrim($url, '/').'/'.ltrim($location, '/');
        }

        return null; // trop de redirections
    }

    /**
     * Un hôte est bloqué s'il pointe (littéralement ou après résolution DNS)
     * vers une adresse privée ou réservée. Empêche les requêtes vers
     * localhost, réseaux privés et endpoints de métadonnées cloud.
     */
    private function hostIsBlocked(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return ! $this->isPublicIp($host);
        }

        // Nom d'hôte : résolution IPv4 ; injoignable → laissé passer (le
        // client HTTP échouera de lui-même), résolu privé → bloqué.
        $ips = gethostbynamel($host);
        if ($ips === false) {
            return false;
        }

        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                return true;
            }
        }

        return false;
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
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

            if (in_array($local, self::GENERIC_PREFIXES, true) && $this->isDeliverable($email)) {
                return mb_strtolower($email);
            }
        }

        return null;
    }

    /**
     * Validation email (EF-05.6) : syntaxe stricte, puis existence d'un
     * enregistrement MX (ou A en repli) du domaine — désactivable en test
     * via fbde.crawl.validate_mx pour ne pas dépendre du DNS.
     */
    private function isDeliverable(string $email): bool
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        if (config('fbde.crawl.validate_mx') !== true) {
            return true;
        }

        $domain = substr((string) strrchr($email, '@'), 1);

        return $domain !== '' && (checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'A'));
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

    /** Description du site : meta description, sinon Open Graph (§8). */
    private function extractDescription(string $html): ?string
    {
        foreach ([
            '#<meta[^>]*name=["\']description["\'][^>]*content=["\']([^"\']+)["\']#i',
            '#<meta[^>]*content=["\']([^"\']+)["\'][^>]*name=["\']description["\']#i',
            '#<meta[^>]*property=["\']og:description["\'][^>]*content=["\']([^"\']+)["\']#i',
        ] as $pattern) {
            if (preg_match($pattern, $html, $m) === 1) {
                $text = trim(html_entity_decode($m[1]));

                return $text !== '' ? mb_substr($text, 0, 500) : null;
            }
        }

        return null;
    }

    /** Lien vers la page contact (§8) — résolu en URL absolue. */
    private function extractContactFormUrl(string $html, string $scheme, string $host): ?string
    {
        if (preg_match('#href=["\']([^"\']*contact[^"\']*)["\']#i', $html, $m) !== 1) {
            return null;
        }

        $href = html_entity_decode($m[1]);

        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }

        return "{$scheme}://{$host}/".ltrim($href, '/');
    }

    /**
     * CMS et bibliothèques par signatures HTML (§8) — détection best-effort,
     * volontairement conservatrice : mieux vaut null qu'une fausse techno.
     *
     * @return array{cms: string|null, libs: list<string>}|null
     */
    private function detectTechnologies(string $html): ?array
    {
        $cmsSignatures = [
            'wordpress' => '#wp-content/|wp-includes/|generator["\'][^>]*wordpress#i',
            'shopify' => '#cdn\.shopify\.com|myshopify\.com#i',
            'prestashop' => '#prestashop#i',
            'drupal' => '#/sites/default/files|generator["\'][^>]*drupal#i',
            'joomla' => '#/media/jui/|generator["\'][^>]*joomla#i',
            'wix' => '#static\.wixstatic\.com|wix\.com#i',
            'squarespace' => '#squarespace\.com|static1\.squarespace#i',
        ];

        $cms = null;
        foreach ($cmsSignatures as $name => $pattern) {
            if (preg_match($pattern, $html) === 1) {
                $cms = $name;
                break;
            }
        }

        $libSignatures = [
            'jquery' => '#jquery[.\-]#i',
            'react' => '#react(\.production|\.development|-dom)|data-reactroot|__NEXT_DATA__#i',
            'vue' => '#vue(\.global|\.runtime|\.min)\.js|data-v-app#i',
            'angular' => '#ng-version=#i',
            'bootstrap' => '#bootstrap(\.bundle|\.min)?\.(css|js)#i',
        ];

        $libs = [];
        foreach ($libSignatures as $name => $pattern) {
            if (preg_match($pattern, $html) === 1) {
                $libs[] = $name;
            }
        }

        if ($cms === null && $libs === []) {
            return null;
        }

        return ['cms' => $cms, 'libs' => $libs];
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
