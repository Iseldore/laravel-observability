<?php

namespace Gysc\Observability\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Protège /health/deep par un token partagé (config `observability.health.deep_token`).
 *
 * Accepte le token via `?token=` ou l'en-tête `X-Health-Token`. Si aucun token n'est
 * configuré, la route reste accessible (le throttle reste la seule protection).
 * Réponse d'échec volontairement opaque (404) pour ne pas révéler l'existence de la route.
 */
class VerifyHealthToken
{
    public function handle(Request $request, Closure $next)
    {
        $expected = config('observability.health.deep_token');

        if (! $expected) {
            return $next($request);
        }

        $provided = $request->query('token') ?? $request->header('X-Health-Token');

        if (! is_string($provided) || ! hash_equals((string) $expected, $provided)) {
            abort(404);
        }

        return $next($request);
    }
}
