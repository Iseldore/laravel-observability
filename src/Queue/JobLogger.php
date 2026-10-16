<?php

namespace Iseldore\Observability\Queue;

use Iseldore\Observability\Exception\ExceptionLogger;
use Iseldore\Observability\Jobs\SendLogsToOpenObserve;
use Iseldore\Observability\Logging\ContextProcessor;
use Iseldore\Observability\Support\DbStats;
use Iseldore\Observability\Support\RequestId;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Support\Str;

/**
 * Logge les événements de cycle de vie des jobs queue.
 *
 * JobProcessed  → level info   (succès, duration_ms)
 * JobFailed     → level error  (exception message inclus)
 * JobTimedOut   → level error
 *
 * Chaque log porte job_uuid, request_id (partagé avec les logs applicatifs émis pendant le
 * job, dont l'`exception` associée) et duration_ms mesurée depuis JobProcessing.
 *
 * Exclut SendLogsToOpenObserve lui-même pour éviter la récursion.
 */
class JobLogger
{
    /**
     * Garde-fou : un job relâché (retry) ne déclenche ni Processed ni Failed pour sa tentative,
     * son horodatage de départ n'est donc jamais purgé. Au-delà de ce seuil on repart de zéro.
     */
    private const MAX_PENDING_STARTS = 500;

    /** @var array<string, float> microtime(true) de départ, par instance de job */
    private static $starts = [];

    public function handleProcessing(JobProcessing $event): void
    {
        try {
            if (count(self::$starts) >= self::MAX_PENDING_STARTS) {
                self::$starts = [];
            }

            self::$starts[spl_object_hash($event->job)] = microtime(true);

            if (DbStats::enabled()) {
                DbStats::reset();
            }
        } catch (\Throwable $e) {
            error_log('[observability] job_logger failed: '.$e->getMessage());
        }
    }

    public function handleProcessed(JobProcessed $event): void
    {
        $this->dispatch('job_processed', 'info', $event->job, $event->connectionName);
    }

    public function handleFailed(JobFailed $event): void
    {
        $this->dispatch('job_failed', 'error', $event->job, $event->connectionName, $event->exception);
    }

    public function handleTimedOut(JobTimedOut $event): void
    {
        $this->dispatch('job_timed_out', 'error', $event->job, $event->connectionName);
    }

    private function dispatch(string $message, string $level, $job, string $connection, ?\Throwable $exception = null): void
    {
        try {
            // Purge avant tout `return` : l'entrée ne doit jamais survivre au job.
            $durationMs = $this->pullDuration($job);

            $config = config('observability');

            if (! ($config['enabled'] ?? false)) {
                return;
            }

            $jobName = $job->resolveName();

            // Anti-récursion : ne pas se logguer soi-même
            if ($jobName === SendLogsToOpenObserve::class) {
                return;
            }

            // Seuls les succès peuvent être masqués : un échec/timeout est toujours loggé.
            if ($message === 'job_processed' && $this->isExcluded($jobName, (array) ($config['job_log']['except'] ?? []))) {
                return;
            }

            $payload = [
                '_timestamp' => (int) round(microtime(true) * 1000000),
                'level'      => $level,
                'message'    => $message,
                'service'    => (string) ($config['service'] ?? 'laravel'),
                'env'        => app()->environment(),
                'job_class'  => $jobName,
                'queue'      => $job->getQueue() ?? 'default',
                'connection' => $connection,
                'attempts'   => $job->attempts(),
                'request_id' => RequestId::resolve(),
            ];

            if (($uuid = $this->jobUuid($job)) !== null) {
                $payload['job_uuid'] = $uuid;
            }

            if ($durationMs !== null) {
                $payload['duration_ms'] = $durationMs;
            }

            if ($exception !== null) {
                $payload['exception_class']   = get_class($exception);
                $payload['exception_message'] = $exception->getMessage();
                $payload['exception_hash']    = ExceptionLogger::hash($exception);
            }

            $payload += DbStats::snapshot();

            // Contexte applicatif (user_id/user_email…) : parité avec RequestLogger. Ne surcharge
            // jamais une clé déjà posée ; vide hors contexte authentifié (cas courant en worker).
            $payload += ContextProcessor::resolveConfigured();

            SendLogsToOpenObserve::dispatchSafe([$payload]);
        } catch (\Throwable $e) {
            error_log('[observability] job_logger failed: '.$e->getMessage());
        }
    }

    /**
     * Durée en ms depuis JobProcessing, et suppression de l'entrée ; null si départ inconnu.
     *
     * @param  mixed  $job
     * @return float|null
     */
    private function pullDuration($job)
    {
        if (! is_object($job)) {
            return null;
        }

        $key = spl_object_hash($job);
        if (! isset(self::$starts[$key])) {
            return null;
        }

        $start = self::$starts[$key];
        unset(self::$starts[$key]);

        return round((microtime(true) - $start) * 1000, 2);
    }

    /**
     * @param  mixed  $job
     * @return string|null
     */
    private function jobUuid($job)
    {
        $uuid = method_exists($job, 'uuid') ? $job->uuid() : null;

        if (! $uuid && method_exists($job, 'getJobId')) {
            $uuid = $job->getJobId();
        }

        return $uuid ? (string) $uuid : null;
    }

    private function isExcluded(string $jobName, array $patterns): bool
    {
        return $patterns !== [] && Str::is($patterns, $jobName);
    }
}
