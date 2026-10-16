<?php

namespace Iseldore\Observability\Exception;

use Iseldore\Observability\Jobs\SendLogsToOpenObserve;
use Iseldore\Observability\Logging\ContextProcessor;
use Iseldore\Observability\Logging\OpenObserveHandler;
use Iseldore\Observability\Logging\RecordNormalizer;
use Iseldore\Observability\Support\RequestId;
use Illuminate\Log\Events\MessageLogged;

class ExceptionLogger
{
    private static $errorLevels = ['error', 'critical', 'alert', 'emergency'];

    /**
     * Un log produit un document `exception` s'il est de niveau error+ et porte un Throwable
     * dans `context.exception` (hors messages internes du package). Partagé avec
     * OpenObserveHandler pour qu'il écarte exactement les records déjà couverts.
     *
     * @param  string  $level
     * @param  string  $message
     * @param  array  $context
     */
    public static function shouldCapture($level, $message, array $context): bool
    {
        if (! in_array(strtolower((string) $level), self::$errorLevels, true)) {
            return false;
        }

        if (! isset($context['exception']) || ! $context['exception'] instanceof \Throwable) {
            return false;
        }

        return strpos((string) $message, '[observability]') !== 0;
    }

    /**
     * Empreinte stable d'une exception (classe + fichier + ligne), partagée avec JobLogger pour
     * relier `exception` et `job_failed` et compter des erreurs distinctes.
     */
    public static function hash(\Throwable $exception): string
    {
        return md5(get_class($exception).'|'.$exception->getFile().'|'.$exception->getLine());
    }

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

            if (! self::shouldCapture($event->level, $event->message, $event->context)) {
                return;
            }

            $exception = $event->context['exception'];

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
                'exception_hash'    => self::hash($exception),
                'exception_trace'   => $this->formatTrace($exception),
                'exception_app_trace' => $this->formatAppTrace($exception, (int) ($config['exception_log']['app_frames'] ?? 5)),
                'request_id'        => RequestId::resolve(),
            ];

            if (($config['exception_log']['mode'] ?? 'merge') === 'merge') {
                $payload = $this->mergeLogRecord($payload, $event);
            }

            // Contexte applicatif (user_id/user_email…) : parité avec RequestLogger.
            // Ne surcharge jamais une clé déjà posée par ce payload.
            $payload += ContextProcessor::resolveConfigured();

            SendLogsToOpenObserve::dispatchSafe([$payload]);
        } catch (\Throwable $e) {
            error_log('[observability] exception_logger failed: '.$e->getMessage());
        }
    }

    /**
     * Mode `merge` : le record Monolog brut n'est plus envoyé (cf. OpenObserveHandler), le
     * document `exception` doit donc porter son message humain et son contexte.
     */
    private function mergeLogRecord(array $payload, MessageLogged $event): array
    {
        $payload['log_message'] = (string) $event->message;

        $context = $event->context;
        unset($context['exception']);

        // Mêmes champs de corrélation promus que RecordNormalizer (le contexte explicite gagne).
        foreach (['request_id', 'user_id', 'user_email'] as $promoted) {
            if (isset($context[$promoted])) {
                $payload[$promoted] = $context[$promoted];
                unset($context[$promoted]);
            }
        }

        if (! empty($context)) {
            $payload += RecordNormalizer::flattenTop('context', $context);
        }

        return $payload;
    }

    private function formatTrace(\Throwable $e): array
    {
        $frames = [];
        $trace = $e->getTrace();

        $limit = min(5, count($trace));
        for ($i = 0; $i < $limit; $i++) {
            $frames[] = $this->formatFrame($trace[$i]);
        }

        return $frames;
    }

    /**
     * Premières frames dont le site d'appel est dans le code applicatif (hors vendor/) : pour
     * une exception levée dans une librairie (Guzzle, PDO…), indique quel code de l'app l'a
     * déclenchée — ce que les frames brutes, entièrement dans vendor/, ne montrent pas.
     */
    private function formatAppTrace(\Throwable $e, int $limit): array
    {
        $frames = [];

        foreach ($e->getTrace() as $frame) {
            if (count($frames) >= $limit) {
                break;
            }

            if (! isset($frame['file']) || strpos(str_replace('\\', '/', $frame['file']), '/vendor/') !== false) {
                continue;
            }

            $frames[] = $this->formatFrame($frame);
        }

        return $frames;
    }

    private function formatFrame(array $frame): string
    {
        $class = isset($frame['class']) ? $frame['class'].$frame['type'] : '';
        $func = $frame['function'] ?? '';
        $file = isset($frame['file']) ? $frame['file'].':'.$frame['line'] : 'unknown';

        return $class.$func.'() at '.$file;
    }
}
