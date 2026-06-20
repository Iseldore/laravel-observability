<?php

namespace Iseldore\Observability\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Routes health.
 *
 *  - live() : liveness pure pour l'ALB. Toujours 200, AUCUNE dépendance touchée.
 *             Si l'ALB interrogeait une route touchant la DB, une panne RDS tuerait
 *             toutes les tasks (cascade). D'où zéro dépendance ici.
 *  - deep() : readiness pour le monitoring. DB + cache + queue, 503 si un check échoue.
 *             Payload sobre (ok|fail|skipped) — JAMAIS de détail d'exception (reconnaissance).
 */
class HealthController
{
    public function live(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok'], 200);
    }

    public function deep(): JsonResponse
    {
        $checks = config('observability.health.checks', []);
        $results = [];

        if ($checks['db'] ?? true) {
            $results['db'] = $this->checkDatabase();
        }
        if ($checks['cache'] ?? true) {
            $results['cache'] = $this->checkCache();
        }
        if ($checks['queue'] ?? true) {
            $results['queue'] = $this->checkQueue();
        }

        $healthy = ! in_array('fail', $results, true);

        return new JsonResponse(
            ['status' => $healthy ? 'ok' : 'fail'] + $results,
            $healthy ? 200 : 503
        );
    }

    /** `SELECT 1` sur la connexion par défaut. */
    private function checkDatabase(): string
    {
        return $this->guard(function () {
            DB::connection()->select('select 1');

            return 'ok';
        });
    }

    /**
     * Teste le driver de cache réellement configuré. Les drivers non-significatifs
     * (array/null, typiques d'un environnement sans backend) → skipped, jamais fail.
     */
    private function checkCache(): string
    {
        $driver = config('cache.default');
        if (in_array($driver, ['array', 'null', null], true)) {
            return 'skipped';
        }

        return $this->guard(function () {
            $key = 'observability:health:'.bin2hex(random_bytes(4));
            Cache::put($key, 1, 5);
            $ok = Cache::get($key) == 1;
            Cache::forget($key);

            return $ok ? 'ok' : 'fail';
        });
    }

    /**
     * Vérifie la connexion de queue de façon adaptative et défensive :
     *  - sync (local) → skipped (pas de vraie queue à sonder) ;
     *  - sinon, tente Queue::size() si le driver le supporte, sinon skipped.
     */
    private function checkQueue(): string
    {
        $connection = config('queue.default');
        if ($connection === 'sync' || $connection === null) {
            return 'skipped';
        }

        return $this->guard(function () {
            try {
                \Illuminate\Support\Facades\Queue::size();

                return 'ok';
            } catch (\Throwable $e) {
                // Certains drivers ne supportent pas size() → ne pas considérer comme fail.
                return 'skipped';
            }
        });
    }

    /**
     * Exécute un check en avalant toute exception (renvoie 'fail').
     * Ne propage jamais le message d'erreur dans la réponse.
     *
     * @param  callable():string  $check
     */
    private function guard(callable $check): string
    {
        try {
            return $check();
        } catch (\Throwable $e) {
            error_log('[observability] health check failed: '.$e->getMessage());

            return 'fail';
        }
    }
}
