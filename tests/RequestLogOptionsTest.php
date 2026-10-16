<?php

use Iseldore\Observability\Jobs\SendLogsToOpenObserve;
use Iseldore\Observability\ObservabilityServiceProvider;
use Iseldore\Observability\Support\DbStats;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

/**
 * Options de RequestLogger : exclusion de /health par défaut, échantillonnage, route_uri et
 * statistiques SQL (specs PKG-04, PKG-05, PKG-11).
 */
function httpRequestRecords(): \Illuminate\Support\Collection
{
    return collect(Queue::pushed(SendLogsToOpenObserve::class))
        ->flatMap(function ($job) {
            return $job->batch;
        })
        ->where('message', 'http_request')
        ->values();
}

beforeEach(function () {
    config()->set('observability.request_log.enabled', true);
    DbStats::reset();

    Route::get('/items/{id}', function () {
        return response('ok');
    })->name('items.show');

    Route::get('/boom', function () {
        return response('ko', 500);
    });

    Route::get('/missing-thing', function () {
        return response('absent', 404);
    });
});

it('n’émet pas http_request pour /health et /health/deep par défaut', function () {
    Queue::fake();

    $this->get('/health')->assertOk();
    $this->get('/health/deep');

    expect(httpRequestRecords())->toHaveCount(0);
});

it('émet http_request pour /health quand log_health est actif', function () {
    Queue::fake();
    config()->set('observability.request_log.log_health', true);

    $this->get('/health')->assertOk();

    expect(httpRequestRecords()->pluck('path')->all())->toBe(['/health']);
});

it('exclut /health sous le préfixe configuré', function () {
    Queue::fake();
    config()->set('observability.health.prefix', 'monitoring');
    Route::get('/monitoring/health', function () {
        return response('ok');
    });

    $this->get('/monitoring/health')->assertOk();

    expect(httpRequestRecords())->toHaveCount(0);
});

it('ne garde aucune réponse réussie quand le taux d’échantillonnage est 0', function () {
    Queue::fake();
    config()->set('observability.request_log.sampling', ['items/*' => 0.0]);

    $this->get('/items/1')->assertOk();
    $this->get('/items/2')->assertOk();

    expect(httpRequestRecords())->toHaveCount(0);
});

it('conserve toujours les erreurs même avec un taux d’échantillonnage à 0', function () {
    Queue::fake();
    config()->set('observability.request_log.sampling', ['*' => 0.0]);

    $this->get('/boom');
    $this->get('/missing-thing');

    expect(httpRequestRecords()->pluck('status_code')->all())->toBe([500, 404]);
});

it('conserve les requêtes lentes malgré l’échantillonnage', function () {
    Queue::fake();
    config()->set('observability.request_log.sampling', ['items/*' => 0.0]);

    // Début de requête simulé il y a 2 s (cf. RequestId::startTime) : au-dessus du seuil de 1000 ms.
    app()->instance('observability.request_start', microtime(true) - 2);

    $this->get('/items/1')->assertOk();

    $records = httpRequestRecords();

    expect($records)->toHaveCount(1)
        ->and($records->first()['duration_ms'])->toBeGreaterThan(1000);
});

it('pose sample_rate sur les lignes conservées par l’échantillonnage', function () {
    Queue::fake();
    config()->set('observability.request_log.sampling', ['items/*' => 0.5]);

    mt_srand(42);
    for ($i = 1; $i <= 40; $i++) {
        $this->get('/items/'.$i);
    }

    $records = httpRequestRecords();

    expect($records->count())->toBeGreaterThan(0)->toBeLessThan(40)
        ->and($records->pluck('sample_rate')->unique()->all())->toBe([0.5]);
});

it('ne touche pas aux requêtes hors règle d’échantillonnage', function () {
    Queue::fake();
    config()->set('observability.request_log.sampling', ['other/*' => 0.0]);

    $this->get('/items/1')->assertOk();

    $records = httpRequestRecords();

    expect($records)->toHaveCount(1)
        ->and($records->first())->not->toHaveKey('sample_rate');
});

it('ajoute route_uri et route_action en conservant les valeurs dans path', function () {
    Queue::fake();

    $this->get('/items/5')->assertOk();

    $record = httpRequestRecords()->first();

    expect($record['path'])->toBe('/items/5')
        ->and($record['route'])->toBe('items.show')
        ->and($record['route_uri'])->toBe('items/{id}')
        ->and($record['route_action'])->toBe('Closure');
});

it('n’ajoute pas db_query_count ni db_time_ms par défaut', function () {
    Queue::fake();

    $this->get('/items/1');

    expect(httpRequestRecords()->first())->not->toHaveKey('db_query_count')
        ->and(httpRequestRecords()->first())->not->toHaveKey('db_time_ms');
});

it('ajoute db_query_count et db_time_ms quand db_stats est actif, puis repart de zéro', function () {
    Queue::fake();
    config()->set('observability.request_log.db_stats', true);

    DbStats::record(5.0);
    DbStats::record(7.5);
    $this->get('/items/1');
    $this->get('/items/2');

    $records = httpRequestRecords();

    expect($records[0]['db_query_count'])->toBe(2)
        ->and($records[0]['db_time_ms'])->toBe(12.5)
        ->and($records[1]['db_query_count'])->toBe(0);
});

it('alimente DbStats depuis QueryExecuted quand db_stats est actif au boot', function () {
    config()->set('observability.request_log.db_stats', true);

    $provider = new ObservabilityServiceProvider($this->app);
    $register = new ReflectionMethod($provider, 'registerDbStats');
    $register->setAccessible(true);
    $register->invoke($provider);

    app('events')->dispatch(new QueryExecuted('select 1', [], 3.0, app('db')->connection()));

    expect(DbStats::snapshot())->toBe(['db_query_count' => 1, 'db_time_ms' => 3.0]);
});
