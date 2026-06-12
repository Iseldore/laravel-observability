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
