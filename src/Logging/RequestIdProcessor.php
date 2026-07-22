<?php

namespace Iseldore\Observability\Logging;

use Iseldore\Observability\Support\RequestId;

/**
 * Injecte un identifiant de requête (`request_id`) dans `extra` de chaque record.
 *
 * L'id est résolu À CHAQUE record sur la requête courante (pas figé au boot) : indispensable
 * sous Octane, où le worker — et donc le processor — persiste entre plusieurs requêtes.
 * Résolution déléguée à `RequestId::resolve()`, partagée avec `RequestLogger`/
 * `OutboundHttpLogger` (chemin direct hors Monolog) pour garantir le même id sur toute la
 * requête.
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
        $requestId = RequestId::resolve();

        if (is_array($record)) {
            // Monolog 2.x
            $record['extra']['request_id'] = $requestId;

            return $record;
        }

        // Monolog 3.x — LogRecord : la propriété `extra` est mutable (non readonly),
        // on l'affecte directement. On évite `with(extra: ...)` (argument nommé) qui
        // empêcherait le simple parse du fichier sous PHP 7.x.
        $extra = $record->extra;
        $extra['request_id'] = $requestId;
        $record->extra = $extra;

        return $record;
    }
}
