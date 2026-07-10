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
