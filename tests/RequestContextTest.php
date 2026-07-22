<?php

use Iseldore\Observability\Jobs\SendLogsToOpenObserve;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

/**
 * Vérifie que request_id/duration_ms/user_id/user_email sont posés sur http_request
 * (RequestLogger) — parité avec le chemin Monolog (RequestIdProcessor/ContextProcessor) — et
 * que rien ne fuit d'une requête à l'autre sous un process/worker long-vécu (simulé ici par
 * deux requêtes handled successives dans le même test, comme le ferait un worker Octane).
 */
function defineTestRoute(): void
{
    Route::get('/observability-test-route', function () {
        Log::info('log-manuel-pendant-la-requete');

        return response('ok');
    });
}

function flushOpenObserveBuffer(): void
{
    Log::channel('openobserve')->getLogger()->close();
}

beforeEach(function () {
    config()->set('observability.request_log.enabled', true);
    config()->set('logging.channels.openobserve', ['driver' => 'custom', 'via' => \Iseldore\Observability\Logging\OpenObserveChannelFactory::class]);
    config()->set('logging.default', 'openobserve');
    defineTestRoute();
});

it('donne le même request_id à http_request et à un Log:: manuel émis pendant la même requête (header fourni)', function () {
    Queue::fake();

    $this->withHeaders(['X-Request-Id' => 'req-header-123'])
        ->get('/observability-test-route')
        ->assertOk();
    flushOpenObserveBuffer();

    Queue::assertPushed(SendLogsToOpenObserve::class, function ($job) {
        $httpRequest = collect($job->batch)->firstWhere('message', 'http_request');

        return $httpRequest !== null && $httpRequest['request_id'] === 'req-header-123';
    });
    Queue::assertPushed(SendLogsToOpenObserve::class, function ($job) {
        $manualLog = collect($job->batch)->firstWhere('message', 'log-manuel-pendant-la-requete');

        return $manualLog !== null && $manualLog['request_id'] === 'req-header-123';
    });
});

it('donne le même request_id à http_request et à un Log:: manuel émis pendant la même requête (aucun header, id généré)', function () {
    Queue::fake();

    $this->get('/observability-test-route')->assertOk();
    flushOpenObserveBuffer();

    $batch = collect(Queue::pushed(SendLogsToOpenObserve::class))
        ->flatMap(function ($job) {
            return $job->batch;
        });

    $httpRequest = $batch->firstWhere('message', 'http_request');
    $manualLog = $batch->firstWhere('message', 'log-manuel-pendant-la-requete');

    expect($httpRequest)->not->toBeNull()
        ->and($manualLog)->not->toBeNull()
        ->and($httpRequest['request_id'])->not->toBeEmpty()
        ->and($httpRequest['request_id'])->toBe($manualLog['request_id']);
});

it('génère un request_id différent entre deux requêtes successives dans le même process', function () {
    Queue::fake();

    $this->get('/observability-test-route')->assertOk();
    flushOpenObserveBuffer();
    $this->get('/observability-test-route')->assertOk();
    flushOpenObserveBuffer();

    $requestIds = collect(Queue::pushed(SendLogsToOpenObserve::class))
        ->flatMap(function ($job) {
            return $job->batch;
        })
        ->where('message', 'http_request')
        ->pluck('request_id')
        ->values();

    expect($requestIds)->toHaveCount(2)
        ->and($requestIds[0])->not->toBe($requestIds[1]);
});

it('duration_ms est présent et plausible sur http_request pour deux requêtes handled successives (worker long-vécu)', function () {
    // Simule l'événement Octane RequestReceived (absent en test sans le package Octane
    // installé) : c'est lui qui pose le timer de départ de CHAQUE requête sous un worker
    // long-vécu, remplaçant LARAVEL_START (figé au boot du worker, invalide dès la 2e
    // requête). Sans cet appel, ce test reproduirait exactement le bug de la spec :
    // duration_ms absent (pas de LARAVEL_START en environnement de test).
    Queue::fake();

    \Iseldore\Observability\Support\RequestId::markStart();
    $this->get('/observability-test-route')->assertOk();
    flushOpenObserveBuffer();

    \Iseldore\Observability\Support\RequestId::markStart();
    $this->get('/observability-test-route')->assertOk();
    flushOpenObserveBuffer();

    $durations = collect(Queue::pushed(SendLogsToOpenObserve::class))
        ->flatMap(function ($job) {
            return $job->batch;
        })
        ->where('message', 'http_request')
        ->pluck('duration_ms')
        ->values();

    expect($durations)->toHaveCount(2);
    foreach ($durations as $duration) {
        expect($duration)->not->toBeNull()
            ->and($duration)->toBeGreaterThan(0)
            ->and($duration)->toBeLessThan(5000);
    }
});

it('user_id/user_email sont présents sur http_request quand un utilisateur est authentifié', function () {
    Queue::fake();

    config()->set('observability.context.resolver', function () {
        return ['user_id' => 42, 'user_email' => 'user@example.test'];
    });

    $this->get('/observability-test-route')->assertOk();

    Queue::assertPushed(SendLogsToOpenObserve::class, function ($job) {
        $httpRequest = collect($job->batch)->firstWhere('message', 'http_request');

        return $httpRequest['user_id'] === 42
            && $httpRequest['user_email'] === 'user@example.test';
    });
});

it('user_id/user_email sont absents sur http_request hors contexte authentifié (pas d’erreur)', function () {
    Queue::fake();

    $this->get('/observability-test-route')->assertOk();

    Queue::assertPushed(SendLogsToOpenObserve::class, function ($job) {
        $httpRequest = collect($job->batch)->firstWhere('message', 'http_request');

        return ! array_key_exists('user_id', $httpRequest)
            && ! array_key_exists('user_email', $httpRequest);
    });
});
