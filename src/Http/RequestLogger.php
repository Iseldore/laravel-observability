<?php

namespace Gysc\Observability\Http;

use Gysc\Observability\Jobs\SendLogsToOpenObserve;
use Illuminate\Foundation\Http\Events\RequestHandled;

/**
 * Logge chaque requête HTTP entrante avec method, path, status et duration.
 *
 * Écoute RequestHandled — déclenché après que le kernel a produit la réponse,
 * donc duration_ms couvre le cycle complet (middleware + contrôleur).
 *
 * Les assets statiques (js, css, images, fonts) sont filtrés pour éviter le bruit.
 */
class RequestLogger
{
    public function handle(RequestHandled $event): void
    {
        try {
            $config = config('observability');

            if (! ($config['enabled'] ?? false)) {
                return;
            }

            $request  = $event->request;
            $response = $event->response;

            // Filtrer les assets statiques
            $path = $request->path();
            if (preg_match('/\.(js|css|png|jpg|jpeg|gif|svg|ico|woff2?|ttf|map)$/i', $path)) {
                return;
            }

            $startTime = defined('LARAVEL_START') ? LARAVEL_START : null;
            $durationMs = $startTime ? round((microtime(true) - $startTime) * 1000, 2) : null;

            $statusCode = $response->getStatusCode();
            $level = $statusCode >= 500 ? 'error' : ($statusCode >= 400 ? 'warning' : 'info');

            $payload = [
                '_timestamp'  => (int) round(microtime(true) * 1_000_000),
                'level'       => $level,
                'message'     => 'http_request',
                'service'     => (string) ($config['service'] ?? 'laravel'),
                'env'         => app()->environment(),
                'method'      => $request->method(),
                'path'        => '/'.ltrim($path, '/'),
                'status_code' => $statusCode,
            ];

            if ($durationMs !== null) {
                $payload['duration_ms'] = $durationMs;
            }

            if ($routeName = $request->route()?->getName()) {
                $payload['route'] = $routeName;
            }

            SendLogsToOpenObserve::dispatchSafe([$payload]);
        } catch (\Throwable $e) {
            error_log('[observability] request_logger failed: '.$e->getMessage());
        }
    }
}
