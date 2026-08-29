<?php

use Iseldore\Observability\Jobs\SendLogsToOpenObserve;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * Vérifie que request_id/user_id/user_email sont posés sur exception (ExceptionLogger)
 * et slow_query (QueryLogger) — parité avec http_request (RequestLogger), pour permettre
 * de filtrer les exceptions/requêtes lentes par requête ou par utilisateur.
 */
beforeEach(function () {
    config()->set('observability.exception_log.enabled', true);
    config()->set('observability.slow_query.enabled', true);
    config()->set('observability.slow_query.threshold_ms', 1);
});

it('pose request_id et le contexte utilisateur sur un log exception', function () {
    Queue::fake();

    config()->set('observability.context.resolver', function () {
        return ['user_id' => 7, 'user_email' => 'client@example.test'];
    });

    Log::error('échec traitement', ['exception' => new Exception('boom')]);

    Queue::assertPushed(SendLogsToOpenObserve::class, function ($job) {
        $exception = collect($job->batch)->firstWhere('message', 'exception');

        return $exception !== null
            && ! empty($exception['request_id'])
            && $exception['user_id'] === 7
            && $exception['user_email'] === 'client@example.test';
    });
});

it('pose request_id et le contexte utilisateur sur un log slow_query', function () {
    Queue::fake();

    config()->set('observability.context.resolver', function () {
        return ['user_id' => 7, 'user_email' => 'client@example.test'];
    });

    $event = new QueryExecuted('select 1', [], 50, app('db')->connection());
    app('events')->dispatch($event);

    Queue::assertPushed(SendLogsToOpenObserve::class, function ($job) {
        $slowQuery = collect($job->batch)->firstWhere('message', 'slow_query');

        return $slowQuery !== null
            && ! empty($slowQuery['request_id'])
            && $slowQuery['user_id'] === 7
            && $slowQuery['user_email'] === 'client@example.test';
    });
});
