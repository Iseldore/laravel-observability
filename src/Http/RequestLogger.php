<?php

namespace Iseldore\Observability\Http;

use Iseldore\Observability\Jobs\SendLogsToOpenObserve;
use Iseldore\Observability\Logging\ContextProcessor;
use Iseldore\Observability\Support\DbStats;
use Iseldore\Observability\Support\RequestId;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Str;

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

            // Filtrer les routes explicitement exclues (path pattern via Str::is, ou nom de route).
            $except = array_merge((array) ($config['request_log']['except'] ?? []), $this->healthPatterns($config));
            if ($except && $this->matchesAny($request, $except)) {
                return;
            }

            // Timer posé au plus tôt dans le cycle de requête courante (cf. RequestId::markStart) —
            // remplace LARAVEL_START, figé au boot du worker et donc invalide sous Octane dès la
            // deuxième requête traitée par le même process.
            $startTime = RequestId::startTime();
            $durationMs = $startTime !== null ? round((microtime(true) - $startTime) * 1000, 2) : null;

            $statusCode = $response->getStatusCode();
            $level = $statusCode >= 500 ? 'error' : ($statusCode >= 400 ? 'warning' : 'info');

            // Échantillonnage des seules réponses réussies et rapides : une erreur ou une
            // requête lente est toujours conservée.
            $sampleRate = $this->sampleRate($request, $config, $statusCode, $durationMs);
            if ($sampleRate !== null && ! $this->keepSample($sampleRate)) {
                return;
            }

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

            if ($sampleRate !== null) {
                $payload['sample_rate'] = $sampleRate;
            }

            // Contexte applicatif (user_id/user_email…) : parité avec ContextProcessor sur le
            // chemin Monolog. Ne surcharge jamais une clé déjà posée par ce payload.
            $payload += ContextProcessor::resolveConfigured();

            $payload['memory_peak_kb'] = (int) round(memory_get_peak_usage(true) / 1024);

            $payload += DbStats::snapshot();

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
            if ($route !== null) {
                if ($routeName = $route->getName()) {
                    $payload['route'] = $routeName;
                }

                // Pattern de la route (api/v1/groups/{group}/templates) : `path` garde les valeurs
                // pour le diagnostic, `route_uri` permet de regrouper par endpoint.
                $payload['route_uri'] = $route->uri();
                $payload['route_action'] = mb_substr((string) $route->getActionName(), 0, 120);
            }

            SendLogsToOpenObserve::dispatchSafe([$payload]);
        } catch (\Throwable $e) {
            error_log('[observability] request_logger failed: '.$e->getMessage());
        }
    }

    /**
     * Routes health du package, exclues par défaut : liveness appelée en continu par l'ALB,
     * sans valeur diagnostique. `request_log.log_health` les réactive.
     *
     * @return array<int, string>
     */
    private function healthPatterns(array $config): array
    {
        if (($config['request_log']['log_health'] ?? false) || ! ($config['health']['enabled'] ?? true)) {
            return [];
        }

        $prefix = trim((string) ($config['health']['prefix'] ?? ''), '/');
        $base = ($prefix === '' ? '' : $prefix.'/').'health';

        return [$base, $base.'/deep'];
    }

    /**
     * Taux d'échantillonnage applicable (0 ≤ taux < 1) ou null si la requête doit toujours être
     * conservée : aucune règle, statut ≥ 400, ou durée ≥ sampling_always_above_ms.
     *
     * @return float|null
     */
    private function sampleRate($request, array $config, int $statusCode, $durationMs)
    {
        $rules = (array) ($config['request_log']['sampling'] ?? []);
        if (! $rules || $statusCode >= 400) {
            return null;
        }

        $alwaysAbove = (float) ($config['request_log']['sampling_always_above_ms'] ?? 1000);
        if ($durationMs !== null && $durationMs >= $alwaysAbove) {
            return null;
        }

        foreach ($rules as $pattern => $rate) {
            if ($this->matchesAny($request, [(string) $pattern])) {
                $rate = max(0.0, (float) $rate);

                return $rate >= 1 ? null : $rate;
            }
        }

        return null;
    }

    private function keepSample(float $rate): bool
    {
        return $rate > 0 && (mt_rand() / mt_getrandmax()) < $rate;
    }

    /**
     * Compare le path (sans slash initial) et le nom de route de $request aux patterns
     * fournis via Str::is (wildcards "*" supportés, ex: "salto/*", "salto.*").
     */
    private function matchesAny($request, array $patterns): bool
    {
        $path = $request->path();
        $routeName = null;
        $route = $request->route();
        if ($route !== null) {
            $routeName = $route->getName();
        }

        foreach ($patterns as $pattern) {
            if (Str::is($pattern, $path)) {
                return true;
            }

            if ($routeName !== null && Str::is($pattern, $routeName)) {
                return true;
            }
        }

        return false;
    }
}
