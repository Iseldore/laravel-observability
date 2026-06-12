<?php

namespace Gysc\Observability\Logging;

/**
 * Injecte un identifiant de requête (`request_id`) dans `extra` de chaque record.
 *
 * L'id est résolu À CHAQUE record sur la requête courante (pas figé au boot) : indispensable
 * sous Octane, où le worker — et donc le processor — persiste entre plusieurs requêtes.
 *
 * Compat Monolog 2 (`array`) et 3 (`LogRecord` immuable via `->with(...)`).
 */
class RequestIdProcessor
{
    /**
     * @param  array|\Monolog\LogRecord  $record
     * @return array|\Monolog\LogRecord
     */
    public function __invoke($record)
    {
        $requestId = $this->resolveRequestId();

        if (is_array($record)) {
            // Monolog 2.x
            $record['extra']['request_id'] = $requestId;

            return $record;
        }

        // Monolog 3.x — LogRecord est immuable : `with()` renvoie une nouvelle instance.
        $extra = $record->extra;
        $extra['request_id'] = $requestId;

        return $record->with(extra: $extra);
    }

    /**
     * Récupère l'id depuis l'en-tête de corrélation de la requête courante, sinon en génère un.
     * Ne lève jamais (contexte console / hors requête HTTP).
     */
    private function resolveRequestId(): string
    {
        try {
            if (function_exists('request') && ($request = request()) !== null) {
                $header = $request->headers->get('X-Request-Id')
                    ?? $request->headers->get('X-Amzn-Trace-Id');
                if ($header) {
                    return $header;
                }
            }
        } catch (\Throwable $e) {
            // hors contexte requête → id généré ci-dessous
        }

        return self::uuid4();
    }

    private static function uuid4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
