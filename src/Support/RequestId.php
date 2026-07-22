<?php

namespace Iseldore\Observability\Support;

/**
 * Résolution partagée de `request_id` (et du timestamp de début de requête), réutilisable
 * par `RequestIdProcessor` (Monolog) et par les event listeners directs (`RequestLogger`,
 * `OutboundHttpLogger`) qui n'émettent jamais via un channel Monolog.
 *
 * Mémorisée dans le container pour la durée de la requête courante (binding singleton) :
 * indispensable pour que TOUS les logs d'une même requête (Monolog ou direct) partagent le
 * même id quand aucun header de corrélation n'est fourni — sinon chaque appelant génère son
 * propre UUID. Sous Octane, le worker persiste entre requêtes : le binding est nettoyé à
 * chaque nouvelle requête (cf. `ObservabilityServiceProvider::registerOctaneFlush()`), donc
 * jamais figé comme `LARAVEL_START`.
 */
class RequestId
{
    private const BINDING = 'observability.request_id';
    private const START_BINDING = 'observability.request_start';

    /**
     * Retourne le request_id de la requête courante : header `X-Request-Id`/`X-Amzn-Trace-Id`
     * en priorité, sinon un UUID v4 généré une seule fois puis réutilisé pour toute la
     * requête. Ne lève jamais (contexte console / hors requête HTTP).
     */
    public static function resolve(): string
    {
        try {
            $app = function_exists('app') ? app() : null;
        } catch (\Throwable $e) {
            $app = null;
        }

        if ($app !== null && $app->bound(self::BINDING)) {
            return $app->make(self::BINDING);
        }

        $requestId = self::resolveFromHeader() ?? self::uuid4();

        if ($app !== null) {
            $app->instance(self::BINDING, $requestId);
        }

        return $requestId;
    }

    /**
     * Enregistre (une seule fois par requête) le timestamp `microtime(true)` de début de
     * requête, le plus tôt possible dans le cycle de vie. Appels suivants sans effet.
     */
    public static function markStart(): void
    {
        try {
            $app = function_exists('app') ? app() : null;
        } catch (\Throwable $e) {
            return;
        }

        if ($app === null || $app->bound(self::START_BINDING)) {
            return;
        }

        $app->instance(self::START_BINDING, microtime(true));
    }

    /**
     * Timestamp de début de la requête courante.
     *
     * Priorité au binding posé par `markStart()` (event Octane `RequestReceived` — voir
     * `ObservabilityServiceProvider::registerOctaneRequestStart()`), seul repère fiable sous
     * Octane où `LARAVEL_START` reste figée au boot du worker. Hors Octane, une seule requête
     * est traitée par process : `LARAVEL_START` (posée par `public/index.php`) reste correcte
     * et sert de fallback sans coût additionnel (pas de middleware à ajouter à la pile).
     *
     * Retourne `null` si aucun repère n'est disponible (contexte anormal) — l'appelant doit
     * alors omettre `duration_ms` plutôt que d'écrire une valeur aberrante.
     */
    public static function startTime(): ?float
    {
        try {
            $app = function_exists('app') ? app() : null;
        } catch (\Throwable $e) {
            $app = null;
        }

        if ($app !== null && $app->bound(self::START_BINDING)) {
            return $app->make(self::START_BINDING);
        }

        return defined('LARAVEL_START') ? LARAVEL_START : null;
    }

    /**
     * Réinitialise les bindings scope-requête. Indispensable sous Octane : sans ce flush,
     * request_id/timestamp de la requête précédente fuiraient dans la suivante (même bug
     * structurel que `LARAVEL_START`, mais pour ces nouvelles données).
     */
    public static function flush(): void
    {
        try {
            $app = function_exists('app') ? app() : null;
        } catch (\Throwable $e) {
            return;
        }

        if ($app === null) {
            return;
        }

        foreach ([self::BINDING, self::START_BINDING] as $binding) {
            if ($app->bound($binding)) {
                $app->forgetInstance($binding);
            }
        }
    }

    private static function resolveFromHeader(): ?string
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
            // hors contexte requête → id généré par l'appelant
        }

        return null;
    }

    private static function uuid4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
