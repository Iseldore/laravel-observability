<?php

use Illuminate\Support\Facades\DB;

it('/health renvoie 200 sans toucher la base', function () {
    // Connexion DB volontairement cassée : la liveness ne doit PAS la solliciter.
    config()->set('database.default', 'broken');
    config()->set('database.connections.broken', [
        'driver' => 'mysql',
        'host' => '240.0.0.1', // non routable
        'database' => 'x', 'username' => 'x', 'password' => 'x',
    ]);

    $this->get('/health')
        ->assertStatus(200)
        ->assertJson(['status' => 'ok']);
});

it('/health/deep renvoie 200 quand la base répond', function () {
    config()->set('database.default', 'testing');
    config()->set('database.connections.testing', [
        'driver' => 'sqlite', 'database' => ':memory:',
    ]);
    config()->set('cache.default', 'array');
    config()->set('queue.default', 'sync');

    $this->get('/health/deep')
        ->assertStatus(200)
        ->assertJson(['status' => 'ok', 'db' => 'ok', 'cache' => 'skipped', 'queue' => 'skipped']);
});

it('/health/deep renvoie 503 quand la base est injoignable', function () {
    config()->set('database.default', 'broken');
    config()->set('database.connections.broken', [
        'driver' => 'mysql',
        'host' => '240.0.0.1',
        'database' => 'x', 'username' => 'x', 'password' => 'x',
        'options' => [PDO::ATTR_TIMEOUT => 1],
    ]);
    config()->set('cache.default', 'array');
    config()->set('queue.default', 'sync');

    $this->get('/health/deep')
        ->assertStatus(503)
        ->assertJson(['status' => 'fail', 'db' => 'fail']);
})->skip(fn () => getenv('CI') !== false, 'connexion réseau lente en CI');

it('/health/deep ne fuite jamais de détail d’exception', function () {
    config()->set('database.default', 'broken');
    config()->set('database.connections.broken', [
        'driver' => 'mysql', 'host' => '240.0.0.1',
        'database' => 'x', 'username' => 'x', 'password' => 'x',
        'options' => [PDO::ATTR_TIMEOUT => 1],
    ]);

    $body = $this->get('/health/deep')->getContent();

    expect($body)->not->toContain('SQLSTATE')
        ->and($body)->not->toContain('240.0.0.1');
})->skip(fn () => getenv('CI') !== false, 'connexion réseau lente en CI');

it('/health/deep bloque sans le bon token', function () {
    config()->set('observability.health.deep_token', 'secret');

    $this->get('/health/deep')->assertStatus(404);
    $this->get('/health/deep?token=mauvais')->assertStatus(404);
});

it('/health/deep laisse passer avec le bon token', function () {
    config()->set('observability.health.deep_token', 'secret');
    // DB en mémoire pour que le check ne fasse pas échouer la requête.
    config()->set('database.default', 'testing');
    config()->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:']);
    config()->set('cache.default', 'array');
    config()->set('queue.default', 'sync');

    $this->get('/health/deep?token=secret')->assertStatus(200);
    $this->get('/health/deep', ['X-Health-Token' => 'secret'])->assertStatus(200);
});
