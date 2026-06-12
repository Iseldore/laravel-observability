<?php

namespace Gysc\Observability\Tests;

use Gysc\Observability\ObservabilityServiceProvider;
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
    }
}
