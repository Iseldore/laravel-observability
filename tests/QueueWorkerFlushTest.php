<?php

use Iseldore\Observability\Jobs\SendLogsToOpenObserve;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * Un worker de queue (Horizon/queue:work) persiste entre jobs sans jamais émettre
 * RequestHandled ni les events Octane : sans flush explicite sur les events de cycle de
 * vie du job, le BufferHandler du channel `openobserve` accumule les logs émis pendant
 * handle() et ne les envoie jamais. Repro du bug : un client d'intégration qui logue via
 * Log::channel('openobserve') pendant l'exécution d'un job voyait ses logs disparaître.
 */
function fireJobEventWithoutJobInstance(string $eventClass): void
{
    // Les events réels portent une instance de Job (queue driver) inutile ici : le
    // listener ne lit que l'occurrence de l'event pour déclencher le flush.
    $reflection = new ReflectionClass($eventClass);
    $event = $reflection->newInstanceWithoutConstructor();

    app('events')->dispatch($event);
}

it('flush le channel openobserve quand un job se termine (JobProcessed)', function () {
    Queue::fake();

    Log::channel('openobserve')->info('log émis pendant handle() du job');

    fireJobEventWithoutJobInstance(JobProcessed::class);

    Queue::assertPushed(SendLogsToOpenObserve::class, function ($job) {
        return collect($job->batch)->contains(fn ($record) => $record['message'] === 'log émis pendant handle() du job');
    });
});

it('flush le channel openobserve quand un job échoue (JobFailed)', function () {
    Queue::fake();

    Log::channel('openobserve')->error('erreur pendant handle() du job');

    fireJobEventWithoutJobInstance(JobFailed::class);

    Queue::assertPushed(SendLogsToOpenObserve::class, function ($job) {
        return collect($job->batch)->contains(fn ($record) => $record['message'] === 'erreur pendant handle() du job');
    });
});

it('flush le channel openobserve quand un job time out (JobTimedOut)', function () {
    if (! class_exists(JobTimedOut::class)) {
        test()->markTestSkipped('JobTimedOut n\'existe pas avant Laravel 9.');
    }

    Queue::fake();

    Log::channel('openobserve')->error('log avant timeout');

    fireJobEventWithoutJobInstance(JobTimedOut::class);

    Queue::assertPushed(SendLogsToOpenObserve::class, function ($job) {
        return collect($job->batch)->contains(fn ($record) => $record['message'] === 'log avant timeout');
    });
});

it('flush même si job_log.enabled est désactivé', function () {
    // job_log.enabled ne contrôle que le log job_processed lui-même (JobLogger), pas le
    // flush des logs applicatifs émis par le code métier du job — les deux sont indépendants.
    config()->set('observability.job_log.enabled', false);
    Queue::fake();

    Log::channel('openobserve')->info('log applicatif indépendant de job_log');

    fireJobEventWithoutJobInstance(JobProcessed::class);

    Queue::assertPushed(SendLogsToOpenObserve::class, function ($job) {
        return collect($job->batch)->contains(fn ($record) => $record['message'] === 'log applicatif indépendant de job_log');
    });
});

it('ne pousse rien si aucun log n’a été émis avant la fin du job', function () {
    Queue::fake();

    fireJobEventWithoutJobInstance(JobProcessed::class);

    Queue::assertNothingPushed();
});
