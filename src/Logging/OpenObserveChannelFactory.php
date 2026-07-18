<?php

namespace Iseldore\Observability\Logging;

use Monolog\Handler\BufferHandler;
use Monolog\Logger;

/**
 * Factory du channel de log `openobserve` (driver `custom` de Laravel).
 *
 * Assemble : OpenObserveHandler (dispatch en queue) enveloppé dans un BufferHandler
 * (groupe les logs d'une requête), avec RequestIdProcessor.
 *
 * Le BufferHandler flush en fin de requête (close()/__destruct), ou via les listeners
 * du ServiceProvider sous Octane. C'est ce flush qui déclenche `handleBatch()`.
 */
class OpenObserveChannelFactory
{
    /**
     * @param  array<string, mixed>  $config  config du channel (clé `level` optionnelle)
     */
    public function __invoke(array $config): Logger
    {
        $level = $config['level'] ?? 'debug';
        $service = (string) config('observability.service', 'laravel');
        $env = (string) config('app.env', 'production');
        $bufferLimit = (int) config('observability.logs.buffer_limit', 0);

        $handler = new OpenObserveHandler($service, $env, $this->parseLevel($level));

        $buffer = new BufferHandler(
            $handler,
            $bufferLimit,        // 0 = illimité dans la requête
            $this->parseLevel($level),
            true,                // bubble
            true                 // flushOnOverflow : protège la mémoire (worker Octane long-vécu)
        );

        return new Logger('openobserve', [$buffer], [
            new RequestIdProcessor(),
            new ContextProcessor($this->resolveContextResolver()),
        ]);
    }

    /**
     * Résolveur de contexte applicatif injecté sur chaque record. `null` → défaut du
     * ContextProcessor (`auth()->user()`). Une app avec un modèle User atypique ou un
     * tenant peut fournir son propre callable via `observability.context.resolver`.
     *
     * @return callable|null
     */
    private function resolveContextResolver(): ?callable
    {
        $resolver = config('observability.context.resolver');

        return is_callable($resolver) ? $resolver : null;
    }

    /**
     * Convertit un niveau (string|int|Level) vers la valeur attendue par le constructeur
     * de handler, en restant compatible Monolog 2 et 3.
     *
     * @param  mixed  $level
     * @return mixed
     */
    private function parseLevel($level)
    {
        if (is_int($level)) {
            return $level;
        }

        // Monolog\Logger::toMonologLevel existe en 2.x et 3.x et accepte string|int|Level.
        if (is_string($level) && method_exists(Logger::class, 'toMonologLevel')) {
            return Logger::toMonologLevel($level);
        }

        return $level;
    }
}
