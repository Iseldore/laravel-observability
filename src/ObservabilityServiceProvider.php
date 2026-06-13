<?php

namespace Gysc\Observability;

use Gysc\Observability\Auth\AuthLogger;
use Gysc\Observability\Console\ObservabilityTestCommand;
use Gysc\Observability\Database\QueryLogger;
use Gysc\Observability\Http\Middleware\VerifyHealthToken;
use Gysc\Observability\Http\OutboundHttpLogger;
use Gysc\Observability\Http\RequestLogger;
use Gysc\Observability\Logging\OpenObserveHandler;
use Gysc\Observability\Queue\JobLogger;
use Illuminate\Auth\Events\Failed as AuthFailed;
use Illuminate\Auth\Events\Login as AuthLogin;
use Illuminate\Auth\Events\Logout as AuthLogout;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Monolog\Handler\BufferHandler;

class ObservabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/observability.php', 'observability');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/observability.php' => $this->app->configPath('observability.php'),
        ], 'observability-config');

        if ($this->app->runningInConsole()) {
            $this->commands([ObservabilityTestCommand::class]);
        }

        $this->registerHealthRoutes();
        $this->registerSlowQueryLogger();
        $this->registerRequestLogger();
        $this->registerJobLogger();
        $this->registerAuthLogger();
        $this->registerOutboundHttpLogger();
        $this->registerOctaneFlush();
    }

    /**
     * Enregistre /health et /health/deep hors du groupe `web` (pas de session/CSRF/auth).
     */
    private function registerHealthRoutes(): void
    {
        if (! config('observability.health.enabled', true)) {
            return;
        }

        $router = $this->app['router'];
        $router->aliasMiddleware('observability.health.deep', VerifyHealthToken::class);

        $deepMiddleware = ['observability.health.deep'];
        if ($throttle = config('observability.health.deep_throttle')) {
            $deepMiddleware[] = 'throttle:'.$throttle;
        }

        Route::middleware([])
            ->prefix((string) config('observability.health.prefix', ''))
            ->group(function () use ($router, $deepMiddleware) {
                $router->get('/health', [\Gysc\Observability\Http\Controllers\HealthController::class, 'live']);
                $router->get('/health/deep', [\Gysc\Observability\Http\Controllers\HealthController::class, 'deep'])
                    ->middleware($deepMiddleware);
            });
    }

    private function registerSlowQueryLogger(): void
    {
        if (! config('observability.slow_query.enabled')) {
            return;
        }

        $this->app['events']->listen(QueryExecuted::class, QueryLogger::class);
    }

    private function registerRequestLogger(): void
    {
        if (! config('observability.request_log.enabled')) {
            return;
        }

        $this->app['events']->listen(RequestHandled::class, RequestLogger::class);
    }

    private function registerJobLogger(): void
    {
        if (! config('observability.job_log.enabled')) {
            return;
        }

        $events = $this->app['events'];
        $logger = app(JobLogger::class);

        $events->listen(JobProcessed::class, [$logger, 'handleProcessed']);
        $events->listen(JobFailed::class,    [$logger, 'handleFailed']);
        $events->listen(JobTimedOut::class,  [$logger, 'handleTimedOut']);
    }

    private function registerAuthLogger(): void
    {
        if (! config('observability.auth_log.enabled')) {
            return;
        }

        $events = $this->app['events'];
        $logger = app(AuthLogger::class);

        $events->listen(AuthLogin::class,   [$logger, 'handleLogin']);
        $events->listen(AuthLogout::class,  [$logger, 'handleLogout']);
        $events->listen(AuthFailed::class,  [$logger, 'handleFailed']);
    }

    private function registerOutboundHttpLogger(): void
    {
        if (! config('observability.outbound_http_log.enabled')) {
            return;
        }

        $this->app['events']->listen(ResponseReceived::class, OutboundHttpLogger::class);
    }

    /**
     * Sous Octane, le worker (et donc le BufferHandler du channel openobserve) persiste
     * entre requêtes. Sans flush explicite, les logs d'une requête fuient dans le batch
     * de la suivante — ou ne partent jamais (__destruct jamais appelé).
     *
     * On flush et on vide le buffer à la terminaison de chaque requête/tâche Octane.
     * Les classes d'événements Octane n'existent que si octane est installé → on s'abonne
     * de façon défensive par nom de classe (string), sans dépendance dure.
     */
    private function registerOctaneFlush(): void
    {
        $events = $this->app['events'];

        $flush = function () {
            $this->flushOpenObserveChannel();
        };

        foreach ([
            'Laravel\Octane\Events\RequestTerminated',
            'Laravel\Octane\Events\TaskTerminated',
            'Laravel\Octane\Events\TickTerminated',
        ] as $event) {
            $events->listen($event, $flush);
        }
    }

    /**
     * Flush défensif des BufferHandler du channel `openobserve`, s'il est instancié.
     * Ne réinstancie pas le channel (éviterait de créer un logger juste pour le vider).
     */
    private function flushOpenObserveChannel(): void
    {
        try {
            $logManager = $this->app['log'];

            // Accès aux channels déjà résolus uniquement (pas de création à la volée).
            $resolved = (function () {
                return $this->channels ?? [];
            })->call($logManager);

            if (! isset($resolved['openobserve'])) {
                return;
            }

            $logger = $resolved['openobserve']->getLogger();
            foreach ($logger->getHandlers() as $handler) {
                if ($handler instanceof BufferHandler) {
                    $handler->flush();
                }
            }
        } catch (\Throwable $e) {
            error_log('[observability] octane flush failed: '.$e->getMessage());
        } finally {
            // Quoi qu'il arrive, on relâche la garde anti-récursion.
            OpenObserveHandler::$sending = false;
        }
    }
}
