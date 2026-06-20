<?php

use Iseldore\Observability\Jobs\SendLogsToOpenObserve;
use Iseldore\Observability\Support\OpenObserveClient;
use Illuminate\Support\Facades\Http;

it('envoie le batch sur l’endpoint _json du stream configuré', function () {
    config()->set('observability.openobserve.url', 'https://observe.example');
    config()->set('observability.openobserve.org', 'default');
    config()->set('observability.openobserve.stream', 'mon-app');
    Http::fake(['*' => Http::response(['code' => 200], 200)]);

    (new SendLogsToOpenObserve([['message' => 'a']]))->handle();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://observe.example/api/default/mon-app/_json'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization')
            && $request[0]['message'] === 'a';
    });
});

it('reste silencieux quand l’envoi lève une exception', function () {
    // Isole le job du transport HTTP : on vérifie qu'il avale toute exception du client.
    $this->app->bind(OpenObserveClient::class, function () {
        return new class extends OpenObserveClient
        {
            public function ingest(array $batch): void
            {
                throw new RuntimeException('boom');
            }
        };
    });

    expect(function () { return (new SendLogsToOpenObserve([['message' => 'a']]))->handle(); })
        ->not->toThrow(Throwable::class);
});

it('relâche la garde anti-récursion même après un échec d’envoi', function () {
    $this->app->bind(OpenObserveClient::class, function () {
        return new class extends OpenObserveClient
        {
            public function ingest(array $batch): void
            {
                throw new RuntimeException('boom');
            }
        };
    });

    (new SendLogsToOpenObserve([['message' => 'a']]))->handle();

    expect(\Iseldore\Observability\Logging\OpenObserveHandler::$sending)->toBeFalse();
});

it('n’appelle pas le client si désactivé', function () {
    config()->set('observability.enabled', false);

    $called = false;
    $this->app->bind(OpenObserveClient::class, function () use (&$called) {
        return new class($called) extends OpenObserveClient
        {
            /** @var bool */
            public $flag;

            public function __construct(&$flag)
            {
                $this->flag = &$flag;
            }

            public function ingest(array $batch): void
            {
                $this->flag = true;
            }
        };
    });

    (new SendLogsToOpenObserve([['message' => 'a']]))->handle();

    expect($called)->toBeFalse();
});
