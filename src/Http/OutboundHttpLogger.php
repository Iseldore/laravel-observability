<?php

namespace Iseldore\Observability\Http;

use Iseldore\Observability\Jobs\SendLogsToOpenObserve;
use Iseldore\Observability\Logging\ContextProcessor;
use Iseldore\Observability\Support\RequestId;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;

/**
 * Logge les appels HTTP sortants via la facade Http:: de Laravel.
 *
 * Écoute ResponseReceived — disponible depuis Laravel 8.45+.
 * Les hôtes OpenObserve sont exclus pour éviter la récursion.
 *
 * Payload : method, host, path, status_code, duration_ms.
 * Jamais d'URL complète avec query string (peut contenir des tokens).
 */
class OutboundHttpLogger
{
    public function handle(ResponseReceived $event): void
    {
        try {
            $config = config('observability');

            if (! ($config['enabled'] ?? false)) {
                return;
            }

            $request  = $event->request;
            $response = $event->response;

            $url  = $request->url();
            $host = parse_url($url, PHP_URL_HOST) ?? '';
            $path = parse_url($url, PHP_URL_PATH) ?? '/';

            if ($this->isIgnoredHost($host, $config)) {
                return;
            }

            $statusCode = $response->status();
            $level = $statusCode >= 500 ? 'error' : ($statusCode >= 400 ? 'warning' : 'info');

            // Pas d'opérateur nullsafe (?->) : indisponible en PHP 7.x.
            $transferStats = $response->transferStats;
            $transferTime = $transferStats ? $transferStats->getTransferTime() : null;

            $payload = [
                '_timestamp'  => (int) round(microtime(true) * 1000000),
                'level'       => $level,
                'message'     => 'http_outbound',
                'service'     => (string) ($config['service'] ?? 'laravel'),
                'env'         => app()->environment(),
                'method'      => strtoupper($request->method()),
                'host'        => $host,
                'path'        => $path,
                'status_code' => $statusCode,
                'request_id'  => RequestId::resolve(),
            ];

            if ($transferTime !== null) {
                $payload['duration_ms'] = round($transferTime * 1000, 2);
            }

            // Contexte applicatif (user_id/user_email…) : parité avec ContextProcessor sur le
            // chemin Monolog. Ne surcharge jamais une clé déjà posée par ce payload.
            $payload += ContextProcessor::resolveConfigured();

            SendLogsToOpenObserve::dispatchSafe([$payload]);
        } catch (\Throwable $e) {
            error_log('[observability] outbound_http_logger failed: '.$e->getMessage());
        }
    }

    /**
     * Échec avant toute réponse (DNS, timeout, connexion refusée) : sans cet événement, ces
     * appels n'avaient aucun log structuré. Disponible depuis Laravel 8.46 ; la propriété
     * `exception` n'existe pas sur toutes les versions, d'où le garde-fou.
     */
    public function handleConnectionFailed(ConnectionFailed $event): void
    {
        try {
            $config = config('observability');

            if (! ($config['enabled'] ?? false)) {
                return;
            }

            $url  = $event->request->url();
            $host = parse_url($url, PHP_URL_HOST) ?? '';
            $path = parse_url($url, PHP_URL_PATH) ?? '/';

            if ($this->isIgnoredHost($host, $config)) {
                return;
            }

            $payload = [
                '_timestamp' => (int) round(microtime(true) * 1000000),
                'level'      => 'error',
                'message'    => 'http_outbound',
                'service'    => (string) ($config['service'] ?? 'laravel'),
                'env'        => app()->environment(),
                'method'     => strtoupper($event->request->method()),
                'host'       => $host,
                'path'       => $path,
                'request_id' => RequestId::resolve(),
            ];

            $exception = property_exists($event, 'exception') ? $event->exception : null;
            if ($exception instanceof \Throwable) {
                $cause = $exception->getPrevious() ?: $exception;
                $payload['error_class']   = get_class($cause);
                $payload['error_message'] = $this->sanitizeError($exception->getMessage());
            }

            $payload += ContextProcessor::resolveConfigured();

            SendLogsToOpenObserve::dispatchSafe([$payload]);
        } catch (\Throwable $e) {
            error_log('[observability] outbound_http_logger failed: '.$e->getMessage());
        }
    }

    /**
     * Hôtes jamais loggés : OpenObserve lui-même (anti-récursion) et `outbound_http_log.except_hosts`.
     */
    private function isIgnoredHost(string $host, array $config): bool
    {
        $ooHost = parse_url((string) ($config['openobserve']['url'] ?? ''), PHP_URL_HOST) ?? '';
        if ($host === $ooHost) {
            return true;
        }

        $except = array_map('strtolower', (array) ($config['outbound_http_log']['except_hosts'] ?? []));

        return in_array(strtolower($host), $except, true);
    }

    /**
     * Les messages cURL se terminent par l'URL complète : on retire la query string (tokens
     * possibles) puis on borne la taille.
     */
    private function sanitizeError(string $message): string
    {
        $message = (string) preg_replace('~(https?://[^\s?#]+)[?#]\S*~i', '$1', $message);

        return mb_substr($message, 0, 300);
    }
}
