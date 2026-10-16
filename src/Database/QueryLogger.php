<?php

namespace Iseldore\Observability\Database;

use Iseldore\Observability\Jobs\SendLogsToOpenObserve;
use Iseldore\Observability\Logging\ContextProcessor;
use Iseldore\Observability\Support\RequestId;
use Iseldore\Observability\Support\SqlFingerprint;
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

            // Empreinte calculée sur le SQL complet, avant toute troncature.
            $fingerprint = SqlFingerprint::make($event->sql);

            $payload = [
                '_timestamp' => (int) round(microtime(true) * 1000000),
                'level'      => 'warning',
                'message'    => 'slow_query',
                'service'    => (string) ($config['service'] ?? 'laravel'),
                'env'        => app()->environment(),
                'duration_ms'=> round($event->time, 2),
                'sql'        => $this->truncate($event->sql, (int) ($config['slow_query']['max_sql_length'] ?? 2000)),
                'sql_fingerprint' => $this->truncate($fingerprint, (int) ($config['slow_query']['max_sql_length'] ?? 2000)),
                'sql_hash'   => SqlFingerprint::hash($fingerprint),
                'bindings_count' => count($event->bindings),
                'connection' => $event->connectionName,
                'request_id' => RequestId::resolve(),
            ];

            if ($config['slow_query']['log_bindings'] ?? false) {
                $payload['bindings'] = $event->bindings;
            }

            // Contexte applicatif (user_id/user_email…) : parité avec RequestLogger.
            // Ne surcharge jamais une clé déjà posée par ce payload.
            $payload += ContextProcessor::resolveConfigured();

            SendLogsToOpenObserve::dispatchSafe([$payload]);
        } catch (\Throwable $e) {
            error_log('[observability] query_logger failed: '.$e->getMessage());
        }
    }

    /**
     * Tronque à $max caractères (0 ou négatif = pas de limite), avec un marqueur explicite.
     */
    private function truncate(string $sql, int $max): string
    {
        if ($max <= 0 || mb_strlen($sql) <= $max) {
            return $sql;
        }

        return mb_substr($sql, 0, $max).'…[truncated]';
    }
}