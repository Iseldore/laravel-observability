<?php

namespace Iseldore\Observability\Http;

use Iseldore\Observability\Jobs\SendLogsToOpenObserve;
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

            // Anti-récursion : exclure les appels vers OpenObserve lui-même
            $ooHost = parse_url((string) config('observability.openobserve.url', ''), PHP_URL_HOST) ?? '';
            if ($host === $ooHost) {
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
            ];

            if ($transferTime !== null) {
                $payload['duration_ms'] = round($transferTime * 1000, 2);
            }

            SendLogsToOpenObserve::dispatchSafe([$payload]);
        } catch (\Throwable $e) {
            error_log('[observability] outbound_http_logger failed: '.$e->getMessage());
        }
    }
}
