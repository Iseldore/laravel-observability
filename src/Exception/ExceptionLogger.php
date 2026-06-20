<?php

namespace Iseldore\Observability\Exception;

use Iseldore\Observability\Jobs\SendLogsToOpenObserve;
use Iseldore\Observability\Logging\OpenObserveHandler;
use Illuminate\Log\Events\MessageLogged;

class ExceptionLogger
{
    private static $errorLevels = ['error', 'critical', 'alert', 'emergency'];

    public function handle(MessageLogged $event): void
    {
        try {
            if (OpenObserveHandler::$sending) {
                return;
            }

            $config = config('observability');

            if (! ($config['enabled'] ?? false)) {
                return;
            }

            if (! in_array($event->level, self::$errorLevels, true)) {
                return;
            }

            $exception = isset($event->context['exception']) && $event->context['exception'] instanceof \Throwable
                ? $event->context['exception']
                : null;

            if ($exception === null) {
                return;
            }

            if (strpos($event->message, '[observability]') === 0) {
                return;
            }

            $payload = [
                '_timestamp'        => (int) round(microtime(true) * 1000000),
                'level'             => $event->level,
                'message'           => 'exception',
                'service'           => (string) ($config['service'] ?? 'laravel'),
                'env'               => app()->environment(),
                'exception_class'   => get_class($exception),
                'exception_message' => $exception->getMessage(),
                'exception_file'    => $exception->getFile(),
                'exception_line'    => $exception->getLine(),
                'exception_trace'   => $this->formatTrace($exception),
            ];

            SendLogsToOpenObserve::dispatchSafe([$payload]);
        } catch (\Throwable $e) {
            error_log('[observability] exception_logger failed: '.$e->getMessage());
        }
    }

    private function formatTrace(\Throwable $e): array
    {
        $frames = [];
        $trace = $e->getTrace();

        $limit = min(5, count($trace));
        for ($i = 0; $i < $limit; $i++) {
            $frame = $trace[$i];
            $class = isset($frame['class']) ? $frame['class'].$frame['type'] : '';
            $func = $frame['function'] ?? '';
            $file = isset($frame['file']) ? $frame['file'].':'.$frame['line'] : 'unknown';

            $frames[] = $class.$func.'() at '.$file;
        }

        return $frames;
    }
}