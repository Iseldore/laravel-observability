<?php

use Gysc\Observability\Jobs\SendLogsToOpenObserve;
use Gysc\Observability\Logging\OpenObserveChannelFactory;
use Gysc\Observability\Logging\OpenObserveHandler;
use Illuminate\Support\Facades\Queue;

it('bufferise plusieurs logs et dispatche un seul job au flush', function () {
    Queue::fake();

    $logger = (new OpenObserveChannelFactory())(['level' => 'debug']);
    $logger->info('un');
    $logger->error('deux');
    $logger->close(); // flush du BufferHandler → handleBatch → dispatch

    Queue::assertPushed(SendLogsToOpenObserve::class, 1);
    Queue::assertPushed(SendLogsToOpenObserve::class, function ($job) {
        return count($job->batch) === 2
            && $job->batch[0]['message'] === 'un'
            && $job->batch[1]['message'] === 'deux';
    });
});

it('ne dispatche aucun job quand l’envoi est désactivé', function () {
    config()->set('observability.enabled', false);
    Queue::fake();

    $logger = (new OpenObserveChannelFactory())(['level' => 'debug']);
    $logger->info('x');
    $logger->close();

    Queue::assertNothingPushed();
});

it('n’empile pas de second job si un log survient pendant l’envoi (anti-récursion)', function () {
    Queue::fake();

    // Simule la fenêtre d'envoi : tout flush pendant $sending=true est ignoré.
    OpenObserveHandler::$sending = true;

    $logger = (new OpenObserveChannelFactory())(['level' => 'debug']);
    $logger->info('pendant-envoi');
    $logger->close();

    OpenObserveHandler::$sending = false;

    Queue::assertNothingPushed();
});

it('le flush ne lève jamais, même si le dispatch échoue', function () {
    // queue_connection invalide → dispatch lèvera, mais dispatchSafe avale.
    config()->set('observability.logs.queue_connection', 'connexion-inexistante');

    $logger = (new OpenObserveChannelFactory())(['level' => 'debug']);
    $logger->error('boom');

    expect(fn () => $logger->close())->not->toThrow(Throwable::class);
});
