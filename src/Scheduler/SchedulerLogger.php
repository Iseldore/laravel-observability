<?php

namespace Iseldore\Observability\Scheduler;

use Iseldore\Observability\Jobs\SendLogsToOpenObserve;

class SchedulerLogger
{
    public function handleFinished($event): void
    {
        $this->dispatch('scheduled_task_finished', 'info', $event);
    }

    public function handleFailed($event): void
    {
        $this->dispatch('scheduled_task_failed', 'error', $event, $event->exception ?? null);
    }

    private function dispatch(string $message, string $level, $event, $exception = null): void
    {
        try {
            $config = config('observability');

            if (! ($config['enabled'] ?? false)) {
                return;
            }

            $task = $event->task;

            $payload = [
                '_timestamp' => (int) round(microtime(true) * 1000000),
                'level'      => $level,
                'message'    => $message,
                'service'    => (string) ($config['service'] ?? 'laravel'),
                'env'        => app()->environment(),
                'task'       => $task->command ?? $task->description ?? 'unknown',
                'expression' => $task->expression,
            ];

            if (property_exists($event, 'runtime')) {
                $payload['duration_s'] = round($event->runtime, 2);
            }

            if (property_exists($event, 'exitCode')) {
                $payload['exit_code'] = $event->exitCode;
            }

            if ($exception !== null) {
                $payload['exception_class']   = get_class($exception);
                $payload['exception_message'] = $exception->getMessage();
            }

            SendLogsToOpenObserve::dispatchSafe([$payload]);
        } catch (\Throwable $e) {
            error_log('[observability] scheduler_logger failed: '.$e->getMessage());
        }
    }
}