<?php

use GuzzleHttp\Psr7\Request as PsrRequest;
use Iseldore\Observability\Jobs\SendLogsToOpenObserve;
use Iseldore\Observability\Support\SqlFingerprint;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * Empreinte SQL des slow_query (PKG-06), échecs de connexion sortants (PKG-07.1) et filtre de
 * messages de logs (PKG-09 : logs.except_messages).
 */
function noiseRecords(): \Illuminate\Support\Collection
{
    return collect(Queue::pushed(SendLogsToOpenObserve::class))->flatMap(function ($job) {
        return $job->batch;
    });
}

function connectionFailedEvent(string $url, string $message = 'cURL error 6: Could not resolve host'): ConnectionFailed
{
    return new ConnectionFailed(
        new ClientRequest(new PsrRequest('GET', $url)),
        new ConnectionException($message)
    );
}

it('donne la même empreinte à des IN de tailles différentes', function () {
    $short = 'select * from `people` where `id` in (?, ?, ?) and `deleted_at` is null limit 25 offset 0';
    $long = 'select * from `people` where `id` in (?, ?, ?, ?, ?, ?, ?, ?) and `deleted_at` is null limit 50 offset 100';

    expect(SqlFingerprint::make($short))->toBe(SqlFingerprint::make($long))
        ->and(SqlFingerprint::make($short))->toBe('select * from `people` where `id` in (?+) and `deleted_at` is null limit ? offset ?');
});

it('retire les littéraux sans toucher aux identifiants contenant des chiffres', function () {
    $fingerprint = SqlFingerprint::make("select `extra_1`, table2.col from table2 where name = 'O''Brien' and `age` > 18.5");

    expect($fingerprint)->toBe('select `extra_1`, table2.col from table2 where name = ? and `age` > ?');
});

it('réduit un INSERT multi-lignes à une seule ligne de valeurs', function () {
    $fingerprint = SqlFingerprint::make('insert into t (a, b) values (?, ?), (?, ?), (?, ?)');

    expect($fingerprint)->toBe('insert into t (a, b) values (?, ?)');
});

it('ajoute sql_fingerprint, sql_hash et bindings_count aux slow_query', function () {
    Queue::fake();
    config()->set('observability.slow_query.threshold_ms', 1);

    app('events')->dispatch(new QueryExecuted('select * from t where id in (?, ?)', [1, 2], 50, app('db')->connection()));
    app('events')->dispatch(new QueryExecuted('select * from t where id in (?, ?, ?, ?)', [1, 2, 3, 4], 50, app('db')->connection()));

    $records = noiseRecords()->where('message', 'slow_query')->values();

    expect($records)->toHaveCount(2)
        ->and($records[0]['sql_fingerprint'])->toBe('select * from t where id in (?+)')
        ->and($records[0]['sql_hash'])->toBe($records[1]['sql_hash'])
        ->and($records[0]['bindings_count'])->toBe(2)
        ->and($records[1]['bindings_count'])->toBe(4)
        ->and($records[0]['sql'])->toBe('select * from t where id in (?, ?)');
});

it('tronque sql selon max_sql_length sans changer l’empreinte', function () {
    Queue::fake();
    config()->set('observability.slow_query.threshold_ms', 1);
    config()->set('observability.slow_query.max_sql_length', 20);

    app('events')->dispatch(new QueryExecuted('select * from t where id in (?, ?, ?, ?)', [1, 2, 3, 4], 50, app('db')->connection()));

    $record = noiseRecords()->firstWhere('message', 'slow_query');

    expect($record['sql'])->toBe('select * from t wher…[truncated]')
        ->and($record['sql_hash'])->toBe(md5('select * from t where id in (?+)'));
});

it('n’applique aucune limite quand max_sql_length vaut 0', function () {
    Queue::fake();
    config()->set('observability.slow_query.threshold_ms', 1);
    config()->set('observability.slow_query.max_sql_length', 0);
    $sql = 'select '.str_repeat('a, ', 1000).'b from t';

    app('events')->dispatch(new QueryExecuted($sql, [], 50, app('db')->connection()));

    expect(noiseRecords()->firstWhere('message', 'slow_query')['sql'])->toBe($sql);
});

it('logge un échec de connexion sortant en erreur, sans query string', function () {
    Queue::fake();

    app('events')->dispatch(connectionFailedEvent(
        'https://api.partner.test/v1/cards?token=SECRET',
        'cURL error 6: Could not resolve host: api.partner.test for https://api.partner.test/v1/cards?token=SECRET'
    ));

    $record = noiseRecords()->firstWhere('message', 'http_outbound');

    expect($record['level'])->toBe('error')
        ->and($record['host'])->toBe('api.partner.test')
        ->and($record['path'])->toBe('/v1/cards')
        ->and($record['method'])->toBe('GET')
        ->and($record['error_class'])->toBe(ConnectionException::class)
        ->and($record['error_message'])->toContain('Could not resolve host')
        ->and($record['error_message'])->not->toContain('SECRET')
        ->and($record['request_id'])->not->toBeEmpty()
        ->and($record)->not->toHaveKey('status_code');
});

it('borne error_message à 300 caractères', function () {
    Queue::fake();

    app('events')->dispatch(connectionFailedEvent('https://api.partner.test/x', str_repeat('e', 1000)));

    expect(mb_strlen(noiseRecords()->firstWhere('message', 'http_outbound')['error_message']))->toBe(300);
});

it('ignore les hôtes de outbound_http_log.except_hosts (échec et réponse)', function () {
    Queue::fake();
    config()->set('observability.outbound_http_log.except_hosts', ['127.0.0.1', 'Render.Local']);
    Http::fake(['*' => Http::response('ok', 200)]);

    app('events')->dispatch(connectionFailedEvent('http://127.0.0.1:3000/render'));
    Http::get('http://render.local/render');
    Http::get('https://api.partner.test/ok');

    expect(noiseRecords()->where('message', 'http_outbound')->pluck('host')->all())->toBe(['api.partner.test']);
});

it('filtre les logs info dont le message matche logs.except_messages', function () {
    Queue::fake();
    config()->set('logging.default', 'openobserve');
    config()->set('observability.logs.except_messages', ['SOQL Query*', '[SalesForce] Success*']);

    Log::channel('openobserve')->info('SOQL Query SELECT Id FROM Lead');
    Log::channel('openobserve')->info('[SalesForce] Success request [query]');
    Log::channel('openobserve')->info('Autre message utile');
    Log::channel('openobserve')->getLogger()->close();

    expect(noiseRecords()->pluck('message')->all())->toBe(['Autre message utile']);
});

it('ne filtre jamais un log warning ou error même si son message matche', function () {
    Queue::fake();
    config()->set('observability.logs.except_messages', ['SOQL Query*']);

    Log::channel('openobserve')->warning('SOQL Query lente');
    Log::channel('openobserve')->error('SOQL Query en échec');
    Log::channel('openobserve')->getLogger()->close();

    expect(noiseRecords()->pluck('message')->all())->toBe(['SOQL Query lente', 'SOQL Query en échec']);
});
