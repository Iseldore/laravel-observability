<?php

namespace Iseldore\Observability\Http;

use Iseldore\Observability\Jobs\SendLogsToOpenObserve;
use Iseldore\Observability\Logging\ContextProcessor;
use Iseldore\Observability\Support\RequestId;
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

            // Timer posé au plus tôt dans le cycle de requête courante (cf. RequestId::markStart) —
            // remplace LARAVEL_START, figé au boot du worker et donc invalide sous Octane dès la
            // deuxième requête traitée par le même process.
            $startTime = RequestId::startTime();
            $durationMs = $startTime !== null ? round((microtime(true) - $startTime) * 1000, 2) : null;

            $statusCode = $response->getStatusCode();
            $level = $statusCode >= 500 ? 'error' : ($statusCode >= 400 ? 'warning' : 'info');

            $payload = [
                '_timestamp'  => (int) round(microtime(true) * 1000000),
                'level'       => $level,
                'message'     => 'http_request',
                'service'     => (string) ($config['service'] ?? 'laravel'),
                'env'         => app()->environment(),
                'method'      => $request->method(),
                'path'        => '/'.ltrim($path, '/'),
                'status_code' => $statusCode,
                'request_id'  => RequestId::resolve(),
            ];

            if ($durationMs !== null) {
                $payload['duration_ms'] = $durationMs;
            }

            // Contexte applicatif (user_id/user_email…) : parité avec ContextProcessor sur le
            // chemin Monolog. Ne surcharge jamais une clé déjà posée par ce payload.
            $payload += ContextProcessor::resolveConfigured();

            $payload['memory_peak_kb'] = (int) round(memory_get_peak_usage(true) / 1024);

            // Taille de réponse : on privilégie l'en-tête Content-Length (gratuit).
            // getContent() matérialiserait tout le corps en mémoire — coûteux sur une
            // grosse réponse — donc on n'y recourt qu'en dernier ressort.
            $contentLength = $response->headers->get('Content-Length');
            if ($contentLength !== null && is_numeric($contentLength)) {
                $payload['response_size'] = (int) $contentLength;
            } else {
                try {
                    $payload['response_size'] = strlen($response->getContent());
                } catch (\Throwable $ignored) {
                    // StreamedResponse n'a pas de getContent — on omet le champ.
                }
            }

            $route = $request->route();
            if ($route !== null && ($routeName = $route->getName())) {
                $payload['route'] = $routeName;
            }

            SendLogsToOpenObserve::dispatchSafe([$payload]);
        } catch (\Throwable $e) {
            error_log('[observability] request_logger failed: '.$e->getMessage());
        }
    }
}
