<?php

namespace Iseldore\Observability\Support;

/**
 * Compteurs de requêtes SQL du scope courant (requête HTTP ou job), pour exposer
 * `db_query_count` / `db_time_ms` sur http_request et job_* (détection des N+1 que le seuil de
 * slow_query ne voit pas). Alimenté uniquement si `request_log.db_stats` est actif.
 *
 * État statique remis à zéro par les flush de fin de requête/job (cf. le provider) : un worker
 * long-vécu (Octane, queue:work) ne doit jamais cumuler d'un scope à l'autre.
 */
class DbStats
{
    /** @var int */
    private static $count = 0;

    /** @var float */
    private static $timeMs = 0.0;

    public static function enabled(): bool
    {
        return (bool) config('observability.request_log.db_stats', false);
    }

    public static function record(float $timeMs): void
    {
        self::$count++;
        self::$timeMs += $timeMs;
    }

    public static function reset(): void
    {
        self::$count = 0;
        self::$timeMs = 0.0;
    }

    /**
     * Champs à fusionner dans un payload ; vide si la fonctionnalité est désactivée.
     *
     * @return array<string, int|float>
     */
    public static function snapshot(): array
    {
        if (! self::enabled()) {
            return [];
        }

        return [
            'db_query_count' => self::$count,
            'db_time_ms' => round(self::$timeMs, 2),
        ];
    }
}
