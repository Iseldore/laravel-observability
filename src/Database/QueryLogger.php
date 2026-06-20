<?php

namespace Iseldore\Observability\Database;

use Iseldore\Observability\Jobs\SendLogsToOpenObserve;
use Illuminate\Database\Events\QueryExecuted;

/**
 * Écoute l'event QueryExecuted de Laravel et envoie les requêtes lentes vers OpenObserve.
 *
 * Le payload suit le même schéma que les logs Monolog du package :
 * _timestamp (µs), level, message, service, env, + champs SQL spécifiques.
 *
 * Fail-silent : toute erreur est avalée — le logging SQL ne doit jamais impacter l'app.
 */
class QueryLogger
{
    public function handle(QueryExecuted $event): void
    {
        try {
            $config = config('observability');

            if (! ($config['enabled'] ?? false)) {
                return;
            }

            $threshold = (float) ($config['slow_query']['threshold_ms'] ?? 0);
            if ($threshold <= 0 || $event->time < $threshold) {
                return;
            }

            $payload = [
                '_timestamp' => (int) round(microtime(true) * 1000000),
                'level'      => 'warning',
                'message'    => 'slow_query',
                'service'    => (string) ($config['service'] ?? 'laravel'),
                'env'        => app()->environment(),
                'duration_ms'=> round($event->time, 2),
                'sql'        => $event->sql,
                'connection' => $event->connectionName,
            ];

            if ($config['slow_query']['log_bindings'] ?? false) {
                $payload['bindings'] = $event->bindings;
            }

            SendLogsToOpenObserve::dispatchSafe([$payload]);
        } catch (\Throwable $e) {
            error_log('[observability] query_logger failed: '.$e->getMessage());
        }
    }
}