<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Activation globale
    |--------------------------------------------------------------------------
    | Mettre à false en local/test pour ne rien envoyer à OpenObserve.
    | Le channel reste fonctionnel (fail-silent), mais le job n'envoie rien.
    */

    'enabled' => env('OPENOBSERVE_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Nom du service
    |--------------------------------------------------------------------------
    | Ajouté à chaque log (champ "service") pour filtrer dans OpenObserve.
    */

    'service' => env('OBSERVABILITY_SERVICE', env('APP_NAME', 'laravel')),

    /*
    |--------------------------------------------------------------------------
    | Connexion OpenObserve
    |--------------------------------------------------------------------------
    */

    'openobserve' => [
        'url' => env('OPENOBSERVE_URL', 'https://observe.iseldore.fr'),
        'org' => env('OPENOBSERVE_ORG', 'default'),
        'stream' => env('OPENOBSERVE_STREAM', 'default'),
        'user' => env('OPENOBSERVE_USER'),
        'token' => env('OPENOBSERVE_TOKEN'),
        // Timeouts HTTP courts : l'envoi ne doit jamais bloquer un worker.
        'timeout' => (float) env('OPENOBSERVE_TIMEOUT', 2),
        'connect_timeout' => (float) env('OPENOBSERVE_CONNECT_TIMEOUT', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logs
    |--------------------------------------------------------------------------
    */

    'logs' => [
        // Connexion de queue utilisée pour dispatcher l'envoi (null = défaut de l'app).
        'queue_connection' => env('OPENOBSERVE_QUEUE_CONNECTION'),
        'queue' => env('OPENOBSERVE_QUEUE'),
        // 0 = pas de limite de buffer dans la requête (flush en fin de requête).
        'buffer_limit' => (int) env('OPENOBSERVE_BUFFER_LIMIT', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP request logging (requêtes entrantes)
    |--------------------------------------------------------------------------
    | Logge chaque requête avec method, path, status_code, duration_ms.
    | Les assets statiques (js/css/images) sont filtrés automatiquement.
    */

    'request_log' => [
        'enabled' => env('REQUEST_LOG', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Job queue logging
    |--------------------------------------------------------------------------
    | Logge JobProcessed (info), JobFailed (error), JobTimedOut (error).
    | SendLogsToOpenObserve est toujours exclu (anti-récursion).
    */

    'job_log' => [
        'enabled' => env('JOB_LOG', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Auth logging
    |--------------------------------------------------------------------------
    | Logge Login (info), Logout (info), Failed (warning).
    | N'envoie jamais de mot de passe — uniquement email/user_id si disponible.
    */

    'auth_log' => [
        'enabled' => env('AUTH_LOG', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP sortant logging
    |--------------------------------------------------------------------------
    | Logge les appels Http:: sortants : method, host, path, status_code, duration_ms.
    | Les appels vers OpenObserve lui-même sont exclus (anti-récursion).
    | Jamais de query string (peut contenir des tokens).
    */

    'outbound_http_log' => [
        'enabled' => env('OUTBOUND_HTTP_LOG', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Slow query logging
    |--------------------------------------------------------------------------
    | enabled         : activer l'écoute de QueryExecuted.
    | threshold_ms    : seuil en millisecondes — les requêtes plus lentes sont envoyées.
    | log_bindings    : inclure les bindings SQL (peut contenir des données sensibles).
    */

    'slow_query' => [
        'enabled'      => env('SLOW_QUERY_LOG', false),
        'threshold_ms' => (float) env('SLOW_QUERY_THRESHOLD_MS', 1000),
        'log_bindings' => env('SLOW_QUERY_LOG_BINDINGS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes health
    |--------------------------------------------------------------------------
    | enabled        : enregistrer les routes /health et /health/deep.
    | prefix         : préfixe optionnel (vide = /health à la racine, requis pour l'ALB).
    | deep_token     : si défini, /health/deep exige ?token= ou header X-Health-Token.
    | deep_throttle  : "tentatives,minutes" pour le rate-limit de /health/deep.
    */

    'health' => [
        'enabled' => env('OBSERVABILITY_HEALTH_ROUTES', true),
        'prefix' => env('OBSERVABILITY_HEALTH_PREFIX', ''),
        'deep_token' => env('HEALTH_TOKEN'),
        'deep_throttle' => env('HEALTH_DEEP_THROTTLE', '30,1'),
        // Checks activés sur /health/deep. Un check absent ou non configurable → "skipped".
        'checks' => [
            'db' => env('HEALTH_CHECK_DB', true),
            'cache' => env('HEALTH_CHECK_CACHE', true),
            'queue' => env('HEALTH_CHECK_QUEUE', true),
        ],
    ],

];
