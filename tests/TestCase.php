<?php

namespace Iseldore\Observability\Tests;

use Iseldore\Observability\ObservabilityServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ObservabilityServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];
        $config->set('observability.enabled', true);
        $config->set('observability.service', 'test-service');
        $config->set('observability.openobserve.user', 'u');
        $config->set('observability.openobserve.token', 't');
        $config->set('observability.health.deep_token', null);
        // Pas de throttle en test (évite la dépendance cache/rate-limiter).
        $config->set('observability.health.deep_throttle', null);
        $config->set('observability.request_log.enabled', true);
        // Désactivé par défaut : JobLogger attend $event->connectionName, absent des events
        // simulés sans instance de Job réelle dans QueueWorkerFlushTest. Les tests dédiés à
        // job_log l'activent explicitement.
        $config->set('observability.job_log.enabled', false);

        // Le package ne s'auto-enregistre pas dans logging.channels (fait côté app
        // consommatrice) : on le déclare ici pour que Log::channel('openobserve') résolve
        // réellement OpenObserveChannelFactory dans les tests, comme en conditions réelles.
        $config->set('logging.channels.openobserve', [
            'driver' => 'custom',
            'via' => \Iseldore\Observability\Logging\OpenObserveChannelFactory::class,
            'level' => 'debug',
        ]);
    }
}
