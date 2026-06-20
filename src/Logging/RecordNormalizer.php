<?php

namespace Iseldore\Observability\Logging;

/**
 * Normalise un record Monolog en tableau associatif prêt pour l'ingestion `_json`,
 * indépendamment de la version de Monolog.
 *
 * Monolog 2 : le record est un `array` (`$record['message']`, `$record['level']` int,
 *             `$record['level_name']`, `$record['datetime']`, `$record['context']`, `$record['extra']`).
 * Monolog 3 : le record est un objet `Monolog\LogRecord` (`$record->message`,
 *             `$record->level` enum `Level`, `$record->datetime`, `$record->context`, `$record->extra`).
 *
 * On bypass volontairement les Formatter de Monolog : leur API diffère entre 2.x et 3.x.
 * On construit ici directement le payload destiné à OpenObserve.
 */
class RecordNormalizer
{
    /**
     * @param  array|\Monolog\LogRecord  $record
     */
    public static function toArray($record, string $service, string $env): array
    {
        if (is_array($record)) {
            // Monolog 2.x
            $datetime = $record['datetime'] ?? null;
            $level = $record['level_name'] ?? (string) ($record['level'] ?? 'INFO');
            $message = $record['message'] ?? '';
            $context = $record['context'] ?? [];
            $extra = $record['extra'] ?? [];
        } else {
            // Monolog 3.x — LogRecord
            $datetime = $record->datetime ?? null;
            $level = self::levelName($record->level);
            $message = $record->message;
            $context = $record->context;
            $extra = $record->extra;
        }

        $payload = [
            '_timestamp' => self::timestamp($datetime),
            'level' => strtolower((string) $level),
            'message' => (string) $message,
            'service' => $service,
            'env' => $env,
        ];

        // request_id remonté par RequestIdProcessor (dans extra), promu au premier niveau.
        // Retiré de $extra pour éviter une colonne extra_request_id en doublon.
        if (isset($extra['request_id'])) {
            $payload['request_id'] = $extra['request_id'];
            unset($extra['request_id']);
        }

        if (! empty($context)) {
            $payload += self::flattenTop('context', $context);
        }
        if (! empty($extra)) {
            $payload += self::flattenTop('extra', $extra);
        }

        return $payload;
    }

    /**
     * Nom du niveau pour Monolog 3 (enum `Level`) — tolérant aux variations d'API.
     *
     * @param  mixed  $level
     */
    private static function levelName($level): string
    {
        if (is_object($level)) {
            if (method_exists($level, 'getName')) {
                return $level->getName();
            }
            if (property_exists($level, 'name')) {
                return $level->name;
            }
        }

        return (string) $level;
    }

    /**
     * Timestamp en microsecondes (OpenObserve interprète `_timestamp` en µs).
     *
     * @param  mixed  $datetime
     */
    private static function timestamp($datetime): int
    {
        if ($datetime instanceof \DateTimeInterface) {
            return (int) round(((float) $datetime->format('U.u')) * 1000000);
        }

        return (int) round(microtime(true) * 1000000);
    }

    /**
     * Aplatit le premier niveau de `context`/`extra` en colonnes préfixées (`context_<clé>`),
     * en sérialisant tout sous-objet/sous-tableau en JSON string.
     *
     * Pourquoi : OpenObserve aplatit nativement les objets imbriqués en colonnes dynamiques
     * (`context_user_id`, `context_user_roles`, …), ce qui fait exploser le schéma et rend
     * impossible toute requête/alerte fiable. En encodant les valeurs composites en JSON string,
     * on obtient un schéma stable : un scalaire → une colonne typée requêtable, un objet/tableau
     * → une seule colonne texte (toujours recherchable en plein-texte).
     *
     * @return array<string, scalar|null>
     */
    private static function flattenTop(string $prefix, array $data): array
    {
        $out = [];
        foreach (self::sanitize($data) as $key => $value) {
            $column = $prefix.'_'.$key;
            if (is_scalar($value) || $value === null) {
                $out[$column] = $value;
            } else {
                // Tableau ou objet JsonSerializable → une seule colonne texte stable.
                $out[$column] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        return $out;
    }

    /**
     * Garantit un payload JSON-sérialisable : remplace les valeurs non encodables
     * (ressources, objets non sérialisables) par leur représentation textuelle.
     */
    private static function sanitize(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $clean[$key] = self::sanitize($value);
            } elseif (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            } elseif ($value instanceof \JsonSerializable) {
                $clean[$key] = $value;
            } elseif (is_object($value) && method_exists($value, '__toString')) {
                $clean[$key] = (string) $value;
            } else {
                $clean[$key] = self::describe($value);
            }
        }

        return $clean;
    }

    /**
     * @param  mixed  $value
     */
    private static function describe($value): string
    {
        if (is_object($value)) {
            return '[object '.get_class($value).']';
        }

        return '['.gettype($value).']';
    }
}
