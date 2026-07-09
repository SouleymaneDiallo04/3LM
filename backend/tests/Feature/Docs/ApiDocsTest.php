<?php

/**
 * Documentation API (§7, §11) : spec OpenAPI 3.1 générée depuis le code
 * et interface interactive — livrable contractuel J3.
 */
it('sert la spécification OpenAPI 3.1 de l\'API v1', function (): void {
    $response = $this->getJson('/docs/api.json');

    $response->assertOk();
    expect($response->json('openapi'))->toStartWith('3.1')
        ->and($response->json('info.title'))->toBe('FBDE — France Business Data Extractor')
        ->and($response->json('paths'))->toHaveKeys([
            '/companies', '/companies/map', '/companies/facets',
            '/exports', '/statistics', '/auth/login', '/notifications',
        ]);
});

it('sert l\'interface interactive de la documentation', function (): void {
    $this->get('/docs/api')->assertOk();
});
