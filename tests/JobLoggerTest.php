<?php

use Iseldore\Observability\Jobs\SendLogsToOpenObserve;
use Iseldore\Observability\ObservabilityServiceProvider;
use Iseldore\Observability\Support\DbStats;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * Corrélation et mesure des jobs (specs PKG-03, PKG-09 jobs, PKG-11 jobs) : job_uuid,
 * request_id partagé avec les logs applicatifs du job, duration_ms, job_log.except.
 */
function fakeQueueJob(string $name = 'App\\Jobs\\Faux'): object
{
    return new class($name) {
        /** @var string */
        public $name;

        public function __construct($name)
        {
            $this->name = $name;
        }

        public function resolveName()
        {
            return $this->name;
        }

        public function getQueue()
        {
            return 'integration';
        }

        public function attempts()
        {
            return 2;
        }

        public function uuid()
        {
            return 'uuid-'.spl_object_id($this);
        }
    };
}

/**
 * Enregistre JobLogger dans le même ordre qu'en production (avant le flush de request_id du
 * worker) : le TestCase le désactive au boot, on rebranche donc les deux dans le bon ordre.
 */
function bootJobLogger(): void
{
    config()->set('observability.job_log.enabled', true);

    $events = app('events');
    foreach ([JobProcessing::class, JobProcessed::class, JobFailed::class, JobTimedOut::class] as $event) {
        $events->forget($event);
    }

    $provider = new ObservabilityServiceProvider(app());
    foreach (['registerJobLogger', 'registerQueueWorkerFlush'] as $method) {
        $register = new ReflectionMethod($provider, $method);
        $register->setAccessible(true);
        $register->invoke($provider);
    }
}

function jobRecords(): \Illuminate\Support\Collection
{
    return collect(Queue::pushed(SendLogsToOpenObserve::class))->flatMap(function ($job) {
        return $job->batch;
    });
}

beforeEach(function () {
    DbStats::reset();
    bootJobLogger();
});

it('pose job_uuid, request_id, duration_ms et attempts sur job_processed', function () {
    Queue::fake();
    $job = fakeQueueJob();

    app('events')->dispatch(new JobProcessing('redis', $job));
    usleep(2000);
    app('events')->dispatch(new JobProcessed('redis', $job));

    $record = jobRecords()->firstWhere('message', 'job_processed');

    expect($record['job_uuid'])->toBe($job->uuid())
        ->and($record['request_id'])->not->toBeEmpty()
        ->and($record['duration_ms'])->toBeGreaterThan(0)
        ->and($record['attempts'])->toBe(2)
        ->and($record['queue'])->toBe('integration');
});

it('partage request_id et exception_hash entre l’exception du job et job_failed', function () {
    Queue::fake();
    config()->set('observability.exception_log.enabled', true);
    $job = fakeQueueJob();
    $e = new RuntimeException('échec intégration');

    app('events')->dispatch(new JobProcessing('redis', $job));
    Log::channel('openobserve')->error('échec dans le job', ['exception' => $e]);
    app('events')->dispatch(new JobFailed('redis', $job, $e));

    $records = jobRecords();
    $exception = $records->firstWhere('message', 'exception');
    $failed = $records->firstWhere('message', 'job_failed');

    expect($failed['request_id'])->toBe($exception['request_id'])
        ->and($failed['exception_hash'])->toBe($exception['exception_hash'])
        ->and($failed['duration_ms'])->not->toBeNull();
});

it('donne un request_id distinct à deux jobs successifs du même worker', function () {
    Queue::fake();

    foreach ([fakeQueueJob(), fakeQueueJob()] as $job) {
        app('events')->dispatch(new JobProcessing('redis', $job));
        app('events')->dispatch(new JobProcessed('redis', $job));
    }

    $ids = jobRecords()->where('message', 'job_processed')->pluck('request_id');

    expect($ids)->toHaveCount(2)
        ->and($ids[0])->not->toBe($ids[1]);
});

it('purge le départ après le job : aucune durée sans JobProcessing préalable', function () {
    Queue::fake();
    $job = fakeQueueJob();

    app('events')->dispatch(new JobProcessing('redis', $job));
    app('events')->dispatch(new JobProcessed('redis', $job));
    app('events')->dispatch(new JobProcessed('redis', $job));

    $records = jobRecords()->where('message', 'job_processed')->values();

    expect($records[0])->toHaveKey('duration_ms')
        ->and($records[1])->not->toHaveKey('duration_ms');
});

it('masque job_processed pour les classes de job_log.except mais jamais job_failed', function () {
    Queue::fake();
    config()->set('observability.job_log.except', ['App\\Jobs\\Bruyant*']);
    $noisy = fakeQueueJob('App\\Jobs\\BruyantToken');
    $other = fakeQueueJob('App\\Jobs\\Utile');

    app('events')->dispatch(new JobProcessing('redis', $noisy));
    app('events')->dispatch(new JobProcessed('redis', $noisy));
    app('events')->dispatch(new JobProcessing('redis', $other));
    app('events')->dispatch(new JobProcessed('redis', $other));
    app('events')->dispatch(new JobFailed('redis', $noisy, new RuntimeException('boom')));

    $records = jobRecords();

    expect($records->where('message', 'job_processed')->pluck('job_class')->all())->toBe(['App\\Jobs\\Utile'])
        ->and($records->where('message', 'job_failed')->pluck('job_class')->all())->toBe(['App\\Jobs\\BruyantToken']);
});

it('ajoute le contexte applicatif du résolveur', function () {
    Queue::fake();
    config()->set('observability.context.resolver', function () {
        return ['user_id' => 7];
    });
    $job = fakeQueueJob();

    app('events')->dispatch(new JobProcessing('redis', $job));
    app('events')->dispatch(new JobProcessed('redis', $job));

    expect(jobRecords()->firstWhere('message', 'job_processed')['user_id'])->toBe(7);
});

it('ajoute db_query_count et db_time_ms par job quand db_stats est actif', function () {
    Queue::fake();
    config()->set('observability.request_log.db_stats', true);
    $job = fakeQueueJob();

    DbStats::record(99.0); // requêtes d'avant le job : ne doivent pas être comptées
    app('events')->dispatch(new JobProcessing('redis', $job));
    DbStats::record(4.0);
    DbStats::record(6.0);
    app('events')->dispatch(new JobProcessed('redis', $job));

    $record = jobRecords()->firstWhere('message', 'job_processed');

    expect($record['db_query_count'])->toBe(2)
        ->and($record['db_time_ms'])->toBe(10.0);
});
