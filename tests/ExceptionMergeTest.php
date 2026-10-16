<?php

use Iseldore\Observability\Jobs\SendLogsToOpenObserve;
use Iseldore\Observability\Queue\JobLogger;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * Un Log::error() porteur d'un Throwable ne doit produire qu'un seul document (`exception`) en
 * mode `merge` : le record Monolog brut en serait un doublon (cf. specs PKG-01).
 */
function flushMergeBuffer(): void
{
    Log::channel('openobserve')->getLogger()->close();
}

function pushedRecords(): \Illuminate\Support\Collection
{
    return collect(Queue::pushed(SendLogsToOpenObserve::class))->flatMap(function ($job) {
        return $job->batch;
    });
}

beforeEach(function () {
    config()->set('observability.exception_log.enabled', true);
    config()->set('observability.exception_log.mode', 'merge');
    config()->set('logging.default', 'openobserve');
});

it('n’émet qu’un document exception, avec message humain et contexte, en mode merge', function () {
    Queue::fake();

    Log::channel('openobserve')->error('échec synchronisation', [
        'exception' => new RuntimeException('boom'),
        'order_id' => 42,
    ]);
    flushMergeBuffer();

    $records = pushedRecords();
    $exception = $records->firstWhere('message', 'exception');

    expect($records->firstWhere('message', 'échec synchronisation'))->toBeNull()
        ->and($exception)->not->toBeNull()
        ->and($exception['log_message'])->toBe('échec synchronisation')
        ->and($exception['context_order_id'])->toBe(42)
        ->and($exception['exception_message'])->toBe('boom')
        ->and($exception)->not->toHaveKey('context_exception');
});

it('garde le record brut et le document exception en mode legacy', function () {
    Queue::fake();
    config()->set('observability.exception_log.mode', 'legacy');

    Log::channel('openobserve')->error('échec synchronisation', ['exception' => new RuntimeException('boom')]);
    flushMergeBuffer();

    $records = pushedRecords();

    expect($records->firstWhere('message', 'échec synchronisation'))->not->toBeNull()
        ->and($records->firstWhere('message', 'exception'))->not->toBeNull()
        ->and($records->firstWhere('message', 'exception'))->not->toHaveKey('log_message');
});

it('garde le record brut quand exception_log est désactivé', function () {
    Queue::fake();
    config()->set('observability.exception_log.enabled', false);

    Log::channel('openobserve')->error('échec synchronisation', ['exception' => new RuntimeException('boom')]);
    flushMergeBuffer();

    expect(pushedRecords()->firstWhere('message', 'échec synchronisation'))->not->toBeNull();
});

it('n’écarte pas un log d’erreur sans exception ni un log sous le niveau error', function () {
    Queue::fake();

    Log::channel('openobserve')->error('erreur sans exception');
    Log::channel('openobserve')->warning('warning avec exception', ['exception' => new RuntimeException('boom')]);
    flushMergeBuffer();

    $records = pushedRecords();

    expect($records->firstWhere('message', 'erreur sans exception'))->not->toBeNull()
        ->and($records->firstWhere('message', 'warning avec exception'))->not->toBeNull()
        ->and($records->firstWhere('message', 'exception'))->toBeNull();
});

it('promeut request_id et user_id du contexte explicite sur le document exception', function () {
    Queue::fake();

    Log::channel('openobserve')->error('échec', [
        'exception' => new RuntimeException('boom'),
        'user_id' => 99,
        'request_id' => 'req-explicite',
    ]);
    flushMergeBuffer();

    $exception = pushedRecords()->firstWhere('message', 'exception');

    expect($exception['user_id'])->toBe(99)
        ->and($exception['request_id'])->toBe('req-explicite')
        ->and($exception)->not->toHaveKey('context_user_id');
});

it('exception_app_trace ne contient aucune frame vendor et conserve le code applicatif', function () {
    Queue::fake();

    try {
        // Collection::map() est dans vendor/ : la closure y est appelée, mais l'appel à map()
        // vient de ce fichier de test.
        collect([1])->map(function () {
            throw new RuntimeException('depuis une closure');
        });
    } catch (RuntimeException $e) {
        Log::channel('openobserve')->error('échec', ['exception' => $e]);
    }
    flushMergeBuffer();

    $trace = pushedRecords()->firstWhere('message', 'exception')['exception_app_trace'];

    expect($trace)->not->toBeEmpty();
    foreach ($trace as $frame) {
        expect($frame)->not->toContain('/vendor/');
    }
    expect(implode("\n", $trace))->toContain('ExceptionMergeTest.php');
});

function throwFromAppLevel2(): void
{
    throw new RuntimeException('niveau 2');
}

function throwFromAppLevel1(): void
{
    throwFromAppLevel2();
}

it('respecte exception_log.app_frames', function () {
    Queue::fake();
    config()->set('observability.exception_log.app_frames', 1);

    try {
        throwFromAppLevel1();
    } catch (RuntimeException $e) {
        Log::channel('openobserve')->error('échec', ['exception' => $e]);
    }
    flushMergeBuffer();

    expect(pushedRecords()->firstWhere('message', 'exception')['exception_app_trace'])->toHaveCount(1);

    Queue::fake();
    config()->set('observability.exception_log.app_frames', 5);

    try {
        throwFromAppLevel1();
    } catch (RuntimeException $e) {
        Log::channel('openobserve')->error('échec', ['exception' => $e]);
    }
    flushMergeBuffer();

    expect(pushedRecords()->firstWhere('message', 'exception')['exception_app_trace'])->toHaveCount(2);
});

it('donne le même exception_hash à exception et à job_failed', function () {
    Queue::fake();

    $e = new RuntimeException('boom');
    $job = new class {
        public function resolveName() { return 'App\\Jobs\\Faux'; }
        public function getQueue() { return 'default'; }
        public function attempts() { return 3; }
    };

    Log::channel('openobserve')->error('échec', ['exception' => $e]);
    flushMergeBuffer();
    app(JobLogger::class)->handleFailed(new JobFailed('redis', $job, $e));

    $records = pushedRecords();

    expect($records->firstWhere('message', 'job_failed')['exception_hash'])
        ->toBe($records->firstWhere('message', 'exception')['exception_hash']);
});
