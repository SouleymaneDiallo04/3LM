<?php

use App\Models\Company;
use App\Models\Establishment;
use App\Models\Import;
use App\Services\Crawl\WebsiteCrawler;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RegionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, RegionSeeder::class, DepartmentSeeder::class]);

    $company = Company::create([
        'siren' => '111111111', 'legal_name' => 'Dupont SAS',
        'normalized_name' => 'dupont', 'status' => 'active',
    ]);

    $this->bakery = Establishment::create([
        'siret' => '11111111100011', 'company_id' => $company->id,
        'name' => 'Boulangerie Dupont', 'normalized_name' => 'boulangerie dupont',
        'status' => 'active', 'naf_code' => '10.71C', 'city' => 'BORDEAUX',
        'department_code' => '33', 'website' => 'https://boulangerie-dupont.fr',
    ]);
});

it('extrait emails génériques, réseaux sociaux et note JSON-LD (EF-05)', function (): void {
    Http::fake([
        'boulangerie-dupont.fr/robots.txt' => Http::response("User-agent: *\nDisallow: /admin\n"),
        'boulangerie-dupont.fr' => Http::response(<<<'HTML'
            <html><body>
              <a href="mailto:contact@boulangerie-dupont.fr">Écrivez-nous</a>
              <p>Direct : jean.dupont@boulangerie-dupont.fr</p>
              <a href="https://www.facebook.com/boulangeriedupont">Facebook</a>
              <a href="https://www.linkedin.com/company/boulangerie-dupont">LinkedIn</a>
              <script type="application/ld+json">
                {"@type":"Bakery","aggregateRating":{"@type":"AggregateRating","ratingValue":"4.6","reviewCount":"87"}}
              </script>
            </body></html>
            HTML),
    ]);

    $result = app(WebsiteCrawler::class)->crawl($this->bakery);

    $this->bakery->refresh();
    expect($result['status'])->toBe('crawled')
        // RGPD : l'email générique est retenu, le nominatif jamais.
        ->and($this->bakery->email)->toBe('contact@boulangerie-dupont.fr')
        ->and($this->bakery->social_links['facebook'])->toBe('https://www.facebook.com/boulangeriedupont')
        ->and($this->bakery->social_links['linkedin'])->toBe('https://www.linkedin.com/company/boulangerie-dupont')
        // Notes/avis : JSON-LD du site — la source actée avec 3LM.
        ->and((float) $this->bakery->rating)->toBe(4.6)
        ->and($this->bakery->reviews_count)->toBe(87)
        ->and($this->bakery->rating_source)->toBe('site_jsonld')
        ->and($this->bakery->crawled_at)->not->toBeNull();
});

it('extrait description, formulaire de contact, technologies et CMS (§8)', function (): void {
    Http::fake([
        'boulangerie-dupont.fr/robots.txt' => Http::response('', 404),
        'boulangerie-dupont.fr' => Http::response(<<<'HTML'
            <html><head>
              <meta name="description" content="Boulangerie artisanale à Bordeaux depuis 1987.">
              <link rel="stylesheet" href="/wp-content/themes/dupont/style.css">
              <script src="/wp-includes/js/jquery/jquery.min.js"></script>
              <script src="https://cdn.example/react.production.min.js"></script>
            </head><body>
              <a href="/contact">Contactez-nous</a>
            </body></html>
            HTML),
    ]);

    app(WebsiteCrawler::class)->crawl($this->bakery);

    $this->bakery->refresh();
    expect($this->bakery->description)->toBe('Boulangerie artisanale à Bordeaux depuis 1987.')
        ->and($this->bakery->contact_form_url)->toBe('https://boulangerie-dupont.fr/contact')
        ->and($this->bakery->technologies['cms'])->toBe('wordpress')
        ->and($this->bakery->technologies['libs'])->toContain('jquery')
        ->and($this->bakery->technologies['libs'])->toContain('react');
});

it('refuse de crawler une cible interne (protection SSRF)', function (string $url): void {
    $this->bakery->update(['website' => $url]);
    Http::fake(); // aucune réponse simulée : rien ne doit partir

    $result = app(WebsiteCrawler::class)->crawl($this->bakery);

    expect($result['status'])->toBe('blocked')
        ->and($this->bakery->fresh()->email)->toBeNull()
        ->and($this->bakery->fresh()->crawled_at)->not->toBeNull();

    Http::assertNothingSent();
})->with([
    'localhost' => 'http://127.0.0.1/',
    'métadonnées cloud' => 'http://169.254.169.254/latest/meta-data/',
    'réseau privé' => 'http://10.0.0.5/',
    'réseau privé 192' => 'http://192.168.1.1/admin',
]);

it('ne suit pas une redirection vers une cible interne (SSRF via 302)', function (): void {
    $this->bakery->update(['website' => 'https://boulangerie-dupont.fr']);
    Http::fake([
        'boulangerie-dupont.fr/robots.txt' => Http::response('', 404),
        'boulangerie-dupont.fr' => Http::response('', 302, [
            'Location' => 'http://169.254.169.254/latest/meta-data/iam/',
        ]),
        '169.254.169.254/*' => Http::response('AWS_SECRET_KEY=leaked'),
    ]);

    $result = app(WebsiteCrawler::class)->crawl($this->bakery);

    expect($result['status'])->not->toBe('crawled')
        ->and($this->bakery->fresh()->description)->toBeNull();

    // La cible interne n'a jamais été appelée.
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '169.254.169.254'));
});

it('ne conserve qu\'un email au domaine délivrable (validation EF-05.6)', function (): void {
    // Syntaxe invalide écartée même si le préfixe est générique.
    Http::fake([
        'boulangerie-dupont.fr/robots.txt' => Http::response('', 404),
        'boulangerie-dupont.fr' => Http::response('Nous écrire : contact@@cassé..fr'),
    ]);

    app(WebsiteCrawler::class)->crawl($this->bakery);
    expect($this->bakery->fresh()->email)->toBeNull();
});

it('ignore les liens de partage et tronque les URL démesurées (robustesse données réelles)', function (): void {
    $longContact = 'https://exemple.fr/contact?'.str_repeat('a', 400); // > 255
    Http::fake([
        'boulangerie-dupont.fr/robots.txt' => Http::response('', 404),
        'boulangerie-dupont.fr' => Http::response(<<<HTML
            <a href="https://www.facebook.com/sharer/sharer.php?u=x">Partager</a>
            <a href="https://twitter.com/intent/tweet?url=x">Tweeter</a>
            <a href="https://www.facebook.com/boulangeriedupont">Notre page</a>
            <a href="{$longContact}">Contact</a>
            HTML),
    ]);

    $result = app(WebsiteCrawler::class)->crawl($this->bakery);
    $this->bakery->refresh();

    // Le vrai profil est retenu, les liens de partage écartés.
    expect($result['status'])->toBe('crawled')
        ->and($this->bakery->social_links)->toBe(['facebook' => 'https://www.facebook.com/boulangeriedupont'])
        // Une URL de contact > 255 caractères n'est pas stockée (jamais de crash).
        ->and($this->bakery->contact_form_url)->toBeNull();
});

it('respecte strictement robots.txt : Disallow racine = aucun crawl', function (): void {
    Http::fake([
        'boulangerie-dupont.fr/robots.txt' => Http::response("User-agent: *\nDisallow: /\n"),
        'boulangerie-dupont.fr' => Http::response('<a href="mailto:contact@boulangerie-dupont.fr">x</a>'),
    ]);

    $result = app(WebsiteCrawler::class)->crawl($this->bakery);

    expect($result['status'])->toBe('disallowed')
        ->and($this->bakery->fresh()->email)->toBeNull()
        // La page d'accueil n'a jamais été appelée.
        ->and(collect(Http::recorded())->filter(
            fn ($pair) => ! str_contains($pair[0]->url(), 'robots.txt'),
        ))->toHaveCount(0);

    // Marqué crawlé quand même : on ne réessaie pas en boucle un site interdit.
    expect($this->bakery->fresh()->crawled_at)->not->toBeNull();
});

it('ne conserve jamais un email nominatif seul (RGPD par défaut)', function (): void {
    Http::fake([
        'boulangerie-dupont.fr/robots.txt' => Http::response('', 404),
        'boulangerie-dupont.fr' => Http::response('Contact : jean.dupont@boulangerie-dupont.fr'),
    ]);

    app(WebsiteCrawler::class)->crawl($this->bakery);

    expect($this->bakery->fresh()->email)->toBeNull();
});

it('respecte la précédence par champ : un email existant est préservé (correctif 15)', function (): void {
    $this->bakery->update(['email' => 'deja@dupont.fr']);

    Http::fake([
        'boulangerie-dupont.fr/robots.txt' => Http::response('', 404),
        'boulangerie-dupont.fr' => Http::response('mailto:info@boulangerie-dupont.fr'),
    ]);

    app(WebsiteCrawler::class)->crawl($this->bakery);

    expect($this->bakery->fresh()->email)->toBe('deja@dupont.fr');
});

it('espace les requêtes d\'un même domaine d\'au moins une seconde (EF-05)', function (): void {
    Http::fake([
        'boulangerie-dupont.fr/*' => Http::response('', 404),
        'boulangerie-dupont.fr' => Http::response('ok'),
    ]);

    $crawler = app(WebsiteCrawler::class);
    $start = microtime(true);
    $crawler->crawl($this->bakery);
    $crawler->crawl($this->bakery);
    $elapsed = microtime(true) - $start;

    expect($elapsed)->toBeGreaterThan(1.0);
});

it('sélectionne les fiches à crawler et trace l\'exécution (commande)', function (): void {
    // Sans site web : jamais sélectionné.
    Establishment::create([
        'siret' => '11111111100029', 'company_id' => $this->bakery->company_id,
        'name' => 'Sans Site', 'normalized_name' => 'sans site',
        'status' => 'active', 'city' => 'BORDEAUX', 'department_code' => '33',
    ]);

    Http::fake([
        'boulangerie-dupont.fr/robots.txt' => Http::response('', 404),
        'boulangerie-dupont.fr' => Http::response('mailto:contact@boulangerie-dupont.fr'),
    ]);

    $this->artisan('fbde:crawl', ['--department' => '33', '--limit' => 10])
        ->assertSuccessful();

    $import = Import::latest('id')->firstOrFail();
    expect($import->source)->toBe('crawl')
        ->and($import->status)->toBe('completed')
        ->and((int) $import->stats['crawled'])->toBe(1);

    // Déjà crawlé récemment : plus sélectionné au second passage.
    $this->artisan('fbde:crawl', ['--department' => '33', '--limit' => 10])->assertSuccessful();
    expect((int) Import::latest('id')->firstOrFail()->stats['crawled'])->toBe(0);
});
