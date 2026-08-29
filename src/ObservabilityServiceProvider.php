<?php

namespace Iseldore\Observability;

use Iseldore\Observability\Auth\AuthLogger;
use Iseldore\Observability\Cache\CacheLogger;
use Iseldore\Observability\Console\DeployMarkerCommand;
use Iseldore\Observability\Console\HealthHeartbeatCommand;
use Iseldore\Observability\Console\ObservabilityTestCommand;
use Iseldore\Observability\Database\QueryLogger;
use Iseldore\Observability\Exception\ExceptionLogger;
use Iseldore\Observability\Http\Middleware\VerifyHealthToken;
use Iseldore\Observability\Http\OutboundHttpLogger;
use Iseldore\Observability\Http\RequestLogger;
use Iseldore\Observability\Logging\OpenObserveHandler;
use Iseldore\Observability\Queue\JobLogger;
use Iseldore\Observability\Scheduler\SchedulerLogger;
use Iseldore\Observability\Support\RequestId;
use Illuminate\Auth\Events\Failed as AuthFailed;
use Illuminate\Auth\Events\Login as AuthLogin;
use Illuminate\Auth\Events\Logout as AuthLogout;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Log\Events\MessageLogged;
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
            $this->commands([
                ObservabilityTestCommand::class,
                DeployMarkerCommand::class,
                HealthHeartbeatCommand::class,
            ]);
        }

        $this->registerHealthRoutes();
        $this->registerHeartbeatSchedule();
        $this->registerSlowQueryLogger();
        $this->registerRequestLogger();
        $this->registerJobLogger();
        $this->registerAuthLogger();
        $this->registerOutboundHttpLogger();
        $this->registerSchedulerLogger();
        $this->registerExceptionLogger();
        $this->registerCacheLogger();
        $this->registerOctaneRequestStart();
        $this->registerOctaneFlush();
        $this->registerQueueWorkerFlush();
        $this->registerRequestIdFlush();
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
                $router->get('/health', [\Iseldore\Observability\Http\Controllers\HealthController::class, 'live']);
                $router->get('/health/deep', [\Iseldore\Observability\Http\Controllers\HealthController::class, 'deep'])
                    ->middleware($deepMiddleware);
            });
    }

    private function registerHeartbeatSchedule(): void
    {
        if (! config('observability.heartbeat.enabled')) {
            return;
        }

        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            $method = config('observability.heartbeat.schedule', 'everyMinute');
            $schedule->command('observability:heartbeat')->withoutOverlapping()->$method();
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

    /**
     * Nettoie les bindings scope-requête (request_id + timestamp de départ) en fin de
     * requête, y compris hors Octane : un process qui traite plusieurs requêtes handled
     * (ex. tests Pest qui simulent plusieurs requêtes successives dans le même process, ou
     * tout autre contexte où le container persiste entre deux `RequestHandled`) ne doit
     * jamais faire fuiter le request_id/timer de l'une vers l'autre. Indépendant de
     * `request_log.enabled` : RequestIdProcessor (Monolog) et OutboundHttpLogger utilisent
     * aussi ce binding et doivent bénéficier du même nettoyage.
     */
    private function registerRequestIdFlush(): void
    {
        $this->app['events']->listen(RequestHandled::class, function () {
            RequestId::flush();
        });
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

    private function registerSchedulerLogger(): void
    {
        if (! config('observability.scheduler_log.enabled')) {
            return;
        }

        $events = $this->app['events'];
        $logger = app(SchedulerLogger::class);

        $finished = 'Illuminate\Console\Events\ScheduledTaskFinished';
        $failed   = 'Illuminate\Console\Events\ScheduledTaskFailed';

        if (class_exists($finished)) {
            $events->listen($finished, [$logger, 'handleFinished']);
        }
        if (class_exists($failed)) {
            $events->listen($failed, [$logger, 'handleFailed']);
        }
    }

    private function registerExceptionLogger(): void
    {
        if (! config('observability.exception_log.enabled')) {
            return;
        }

        $this->app['events']->listen(MessageLogged::class, ExceptionLogger::class);
    }

    private function registerCacheLogger(): void
    {
        if (! config('observability.cache_log.enabled')) {
            return;
        }

        $events = $this->app['events'];
        $logger = app(CacheLogger::class);

        $hit    = 'Illuminate\Cache\Events\CacheHit';
        $missed = 'Illuminate\Cache\Events\CacheMissed';

        if (class_exists($hit)) {
            $events->listen($hit, [$logger, 'handleHit']);
        }
        if (class_exists($missed)) {
            $events->listen($missed, [$logger, 'handleMissed']);
        }

        $cacheFlush = function () use ($logger) {
            $logger->flush();
        };

        // Un worker de queue/Octane persiste entre jobs/requêtes sans jamais émettre
        // RequestHandled : sans ce flush, les compteurs statiques hits/misses s'accumulent
        // indéfiniment (fuite mémoire) et ne sont jamais logués.
        $events->listen(RequestHandled::class, $cacheFlush);
        $events->listen(JobProcessed::class, $cacheFlush);
        $events->listen(JobFailed::class, $cacheFlush);
        $events->listen(JobTimedOut::class, $cacheFlush);
    }

    /**
     * Sous Octane, le worker traite des centaines/milliers de requêtes sans jamais
     * réexécuter `public/index.php` : `LARAVEL_START` reste figée au boot du worker et ne
     * peut donc pas servir de départ pour `duration_ms` au-delà de la première requête.
     *
     * `RequestReceived` est déclenché par Octane au tout début de CHAQUE requête traitée
     * par le worker — on y pose le timestamp de départ dans un binding scope-requête
     * (`RequestId::markStart()`), lu ensuite par `RequestLogger` via `RequestId::startTime()`.
     * Hors Octane, ce binding n'est jamais posé et `RequestLogger` retombe sur
     * `LARAVEL_START` (correct : une seule requête par process) — coût nul, aucun
     * middleware à ajouter à la pile globale.
     *
     * Classe d'événement Octane non chargée si le package n'est pas installé → abonnement
     * défensif par nom de classe (string), sans dépendance dure.
     */
    private function registerOctaneRequestStart(): void
    {
        $this->app['events']->listen('Laravel\Octane\Events\RequestReceived', function () {
            RequestId::markStart();
        });
    }

    /**
     * Sous Octane, le worker (et donc le BufferHandler du channel openobserve) persiste
     * entre requêtes. Sans flush explicite, les logs d'une requête fuient dans le batch
     * de la suivante — ou ne partent jamais (__destruct jamais appelé).
     *
     * On flush et on vide le buffer à la terminaison de chaque requête/tâche Octane, et on
     * réinitialise les bindings scope-requête de `RequestId` (request_id + timestamp de
     * départ) pour qu'ils ne fuient jamais vers la requête suivante traitée par le worker.
     * Les classes d'événements Octane n'existent que si octane est installé → on s'abonne
     * de façon défensive par nom de classe (string), sans dépendance dure.
     */
    private function registerOctaneFlush(): void
    {
        $events = $this->app['events'];

        $flush = function () {
            $this->flushOpenObserveChannel();
            RequestId::flush();
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
     * Un worker de queue (`queue:work`/`horizon`) persiste entre jobs comme Octane persiste
     * entre requêtes, mais n'émet ni `RequestHandled` ni les events Octane : sans ce flush,
     * le BufferHandler du channel `openobserve` accumule les logs émis pendant `handle()`
     * (ex. un client d'intégration qui logue chaque appel HTTP) et ne les envoie jamais —
     * `__destruct` n'est pas fiable sur un worker tué par SIGTERM/SIGKILL au redéploiement.
     *
     * Indépendant de `job_log.enabled` (qui ne contrôle que le log job_processed/job_failed
     * lui-même) : désactiver ce flag ne doit pas casser le flush des logs applicatifs émis
     * par le code métier du job. Couvre JobProcessed ET JobFailed/JobTimedOut pour ne jamais
     * perdre les logs d'un job en échec, souvent les plus utiles au diagnostic.
     *
     * Réinitialise aussi `RequestId` : sans ce flush, le request_id résolu pour le premier
     * job traité par le worker resterait posé comme binding singleton et fuiterait vers tous
     * les jobs suivants, rendant la corrélation de logs par job totalement fausse.
     */
    private function registerQueueWorkerFlush(): void
    {
        $events = $this->app['events'];

        $flush = function () {
            $this->flushOpenObserveChannel();
            RequestId::flush();
        };

        $events->listen(JobProcessed::class, $flush);
        $events->listen(JobFailed::class, $flush);
        $events->listen(JobTimedOut::class, $flush);
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
