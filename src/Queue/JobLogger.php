<?php

namespace Iseldore\Observability\Queue;

use Iseldore\Observability\Jobs\SendLogsToOpenObserve;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobTimedOut;

/**
 * Logge les événements de cycle de vie des jobs queue.
 *
 * JobProcessed  → level info   (succès, duration_ms si disponible)
 * JobFailed     → level error  (exception message inclus)
 * JobTimedOut   → level error
 *
 * Exclut SendLogsToOpenObserve lui-même pour éviter la récursion.
 */
class JobLogger
{
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
            $config = config('observability');

            if (! ($config['enabled'] ?? false)) {
                return;
            }

            $jobName = $job->resolveName();

            // Anti-récursion : ne pas se logguer soi-même
            if ($jobName === SendLogsToOpenObserve::class) {
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
            ];

            if ($exception !== null) {
                $payload['exception_class']   = get_class($exception);
                $payload['exception_message'] = $exception->getMessage();
            }

            SendLogsToOpenObserve::dispatchSafe([$payload]);
        } catch (\Throwable $e) {
            error_log('[observability] job_logger failed: '.$e->getMessage());
        }
    }
}
