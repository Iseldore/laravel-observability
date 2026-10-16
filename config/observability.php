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

    // OBSERVABILITY_SERVICE distingue chaque appli GYSC dans OpenObserve : à définir par appli.
    'service' => env('OBSERVABILITY_SERVICE', env('APP_NAME', 'laravel')),

    /*
    |--------------------------------------------------------------------------
    | Connexion OpenObserve
    |--------------------------------------------------------------------------
    */

    'openobserve' => [
        // Secrets / infra propres à chaque environnement : jamais de défaut en dur ici.
        'url' => env('OPENOBSERVE_URL'),
        'user' => env('OPENOBSERVE_USER'),
        'token' => env('OPENOBSERVE_TOKEN'),
        // Seule variable à renseigner en plus de OPENOBSERVE_ENABLED : diffère par environnement.
        'stream' => env('OPENOBSERVE_STREAM', 'default'),
        'org' => env('OPENOBSERVE_ORG', 'default'),
        // Timeouts HTTP courts : l'envoi ne doit jamais bloquer un worker.
        'timeout' => (float) env('OPENOBSERVE_TIMEOUT', 2),
        'connect_timeout' => (float) env('OPENOBSERVE_CONNECT_TIMEOUT', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Contexte applicatif injecté sur chaque log
    |--------------------------------------------------------------------------
    | ContextProcessor ajoute automatiquement user_id / user_email (et tout ce que
    | renvoie le résolveur) sur CHAQUE record, y compris les Log:: manuels — sans
    | toucher au code métier. Indispensable au support (« le client X a eu une erreur »).
    |
    | resolver : callable () => array<string, scalaire|null> résolu à chaque record.
    |            null = défaut (auth()->user() : user_id + user_email).
    |            Fournir le sien pour un modèle User atypique ou un identifiant tenant, ex :
    |
    |            'resolver' => function () {
    |                $u = auth()->user();
    |                return $u ? ['user_id' => $u->id, 'tenant_id' => $u->company_id] : [];
    |            },
    |
    | Le résolveur ne doit renvoyer que des scalaires (un objet/tableau ferait exploser
    | le schéma OpenObserve). Il ne doit jamais lever : toute exception est avalée.
    */

    'context' => [
        'resolver' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Logs
    |--------------------------------------------------------------------------
    */

    'logs' => [
        // Connexion de queue utilisée pour dispatcher l'envoi (null = défaut de l'app).
        'queue_connection' => env('OPENOBSERVE_QUEUE_CONNECTION'),
        'queue' => env('OPENOBSERVE_QUEUE', 'observability'),
        // Masque SendLogsToOpenObserve des « Recent Jobs » d'Horizon (ajout à horizon.silenced).
        'silence_in_horizon' => (bool) env('OPENOBSERVE_SILENCE_IN_HORIZON', true),
        // 0 = pas de limite de buffer dans la requête (flush en fin de requête).
        'buffer_limit' => (int) env('OPENOBSERVE_BUFFER_LIMIT', 0),
        // Patterns (Str::is, wildcards "*") comparés au message des logs de niveau < warning :
        // ceux qui matchent ne sont pas envoyés à OpenObserve (bruit applicatif connu). Les
        // warning/error ne sont jamais filtrés. Ex : ['SOQL Query', '[SalesForce] Success request*'].
        'except_messages' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP request logging (requêtes entrantes)
    |--------------------------------------------------------------------------
    | Logge chaque requête avec method, path, status_code, duration_ms.
    | Les assets statiques (js/css/images) sont filtrés automatiquement.
    |
    | except : patterns (Str::is, wildcards "*" supportés) comparés au path (sans
    |          slash initial) ET au nom de route. Un seul match suffit à exclure.
    |          Ex : 'api/v2/salto/ID/gate-logs' avec un wildcard à la place de ID
    |          (path), ou 'salto.gateLogs' (nom de route).
    |
    | log_health : false (défaut) exclut /health et /health/deep (routes du package, appelées en
    |          continu par l'ALB). true pour les logger.
    |
    | sampling : [pattern => taux 0.0–1.0], mêmes patterns que `except`. Ne s'applique qu'aux
    |          réponses < 400 plus rapides que sampling_always_above_ms : une erreur ou une
    |          requête lente est toujours conservée. Chaque ligne conservée porte `sample_rate`
    |          (nombre réel de requêtes ≈ count / sample_rate). Ex : ['api/v1/login' => 0.1].
    |
    | db_stats : true ajoute db_query_count et db_time_ms à http_request et job_* (détection
    |          des N+1). Désactivé par défaut (un compteur par requête SQL).
    */

    'request_log' => [
        'enabled' => env('REQUEST_LOG', true),
        'except' => [
            // 'api/v2/salto/*/gate-logs',
            // 'salto.gateLogs',
        ],
        'log_health' => (bool) env('REQUEST_LOG_HEALTH', false),
        'sampling' => [
            // 'api/v1/login' => 0.1,
        ],
        'sampling_always_above_ms' => (float) env('REQUEST_LOG_SAMPLING_ALWAYS_ABOVE_MS', 1000),
        'db_stats' => (bool) env('REQUEST_LOG_DB_STATS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Job queue logging
    |--------------------------------------------------------------------------
    | Logge JobProcessed (info), JobFailed (error), JobTimedOut (error).
    | SendLogsToOpenObserve est toujours exclu (anti-récursion).
    |
    | Chaque log porte job_uuid, request_id (partagé avec l'`exception` du même job) et
    | duration_ms (mesurée depuis JobProcessing).
    |
    | except : patterns (Str::is, wildcards "*" supportés) comparés à la classe du job. Ne masque
    |          que job_processed : un échec ou un timeout est toujours loggé.
    |          Ex : ['App\\Helpers\\Odata\\UpdatePersonalAccessToken'].
    */

    'job_log' => [
        'enabled' => env('JOB_LOG', true),
        'except' => [
            // 'App\\Helpers\\Odata\\UpdatePersonalAccessToken',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Auth logging
    |--------------------------------------------------------------------------
    | Logge Login (info), Logout (info), Failed (warning).
    | N'envoie jamais de mot de passe — uniquement email/user_id si disponible.
    |
    | events : événements loggés parmi 'login', 'logout', 'failed' (retirer 'login' pour une
    |          API dont chaque appel s'authentifie : le http_request correspondant suffit).
    |          Chaque log porte le request_id de la requête.
    */

    'auth_log' => [
        'enabled' => env('AUTH_LOG', true),
        'events' => ['login', 'logout', 'failed'],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP sortant logging
    |--------------------------------------------------------------------------
    | Logge les appels Http:: sortants : method, host, path, status_code, duration_ms.
    | Les appels vers OpenObserve lui-même sont exclus (anti-récursion).
    | Jamais de query string (peut contenir des tokens).
    |
    | Les échecs de connexion (DNS, timeout, refus) sont aussi loggés (http_outbound, level
    | error, error_class / error_message) via l'événement ConnectionFailed.
    |
    | except_hosts : hôtes à ne pas logger (ex. service local de rendu '127.0.0.1').
    */

    'outbound_http_log' => [
        'enabled' => env('OUTBOUND_HTTP_LOG', true),
        'except_hosts' => [
            // '127.0.0.1',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduler logging
    |--------------------------------------------------------------------------
    | Logge ScheduledTaskFinished (info) et ScheduledTaskFailed (error).
    | Requiert Laravel 8+ (events de scheduling).
    */

    'scheduler_log' => [
        'enabled' => env('SCHEDULER_LOG', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Exception logging structuré
    |--------------------------------------------------------------------------
    | Écoute MessageLogged (level error+) et envoie un payload structuré avec
    | classe, fichier, ligne, trace (5 frames max), exception_hash et exception_app_trace
    | (premières frames hors vendor/ : le code applicatif à l'origine de l'appel).
    |
    | mode     : 'merge'  (défaut) le document `exception` est la représentation unique d'un
    |                     log porteur d'un Throwable : il reprend le message humain (log_message)
    |                     et le contexte (context_*), le record Monolog brut n'est plus envoyé.
    |            'legacy' comportement historique : record brut ET document `exception`.
    | app_frames : nombre de frames applicatives (hors vendor/) conservées dans exception_app_trace.
    */

    'exception_log' => [
        'enabled' => env('EXCEPTION_LOG', true),
        'mode' => env('EXCEPTION_LOG_MODE', 'merge'),
        'app_frames' => (int) env('EXCEPTION_LOG_APP_FRAMES', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache hit/miss logging
    |--------------------------------------------------------------------------
    | Agrège les hits/misses par requête et envoie un seul payload cache_stats
    | en fin de requête. Les clés observability:* sont exclues.
    */

    'cache_log' => [
        'enabled' => env('CACHE_LOG', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Slow query logging
    |--------------------------------------------------------------------------
    | enabled         : activer l'écoute de QueryExecuted.
    | threshold_ms    : seuil en millisecondes — les requêtes plus lentes sont envoyées.
    | log_bindings    : inclure les bindings SQL (peut contenir des données sensibles).
    | max_sql_length  : longueur max de `sql` / `sql_fingerprint` (0 = illimité) ; au-delà, le
    |                   texte est coupé et suffixé de "…[truncated]".
    |
    | Chaque log porte aussi sql_fingerprint (SQL normalisé : valeurs et listes IN retirées),
    | sql_hash (md5 du fingerprint, clé de regroupement) et bindings_count.
    */

    'slow_query' => [
        'enabled'      => env('SLOW_QUERY_LOG', true),
        'threshold_ms' => (float) env('SLOW_QUERY_THRESHOLD_MS', 1000),
        'log_bindings' => env('SLOW_QUERY_LOG_BINDINGS', false),
        'max_sql_length' => (int) env('SLOW_QUERY_MAX_SQL_LENGTH', 2000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes health
    |--------------------------------------------------------------------------
    | enabled        : enregistrer les routes /health et /health/deep.
    | prefix         : préfixe optionnel (vide = /health à la racine, requis pour l'ALB).
    | deep_token     : si défini, /health/deep exige ?token= ou header X-Health-Token.
    | deep_throttle  : "tentatives,minutes" pour le rate-limit de /health/deep.
    | db_timeout     : timeout (secondes) forcé sur la connexion PDO du check DB — évite
    |                   qu'une base indisponible/figée bloque le worker qui sert /health/deep.
    */

    'health' => [
        'enabled' => env('OBSERVABILITY_HEALTH_ROUTES', true),
        'prefix' => env('OBSERVABILITY_HEALTH_PREFIX', ''),
        'deep_token' => env('HEALTH_TOKEN'),
        'deep_throttle' => env('HEALTH_DEEP_THROTTLE', '30,1'),
        'db_timeout' => env('HEALTH_DB_TIMEOUT', 2),
        // Checks activés sur /health/deep. Un check absent ou non configurable → "skipped".
        'checks' => [
            'db' => env('HEALTH_CHECK_DB', true),
            'cache' => env('HEALTH_CHECK_CACHE', true),
            'queue' => env('HEALTH_CHECK_QUEUE', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Health heartbeat (observability:heartbeat)
    |--------------------------------------------------------------------------
    | schedule : expression cron ou shortcut Laravel ('everyMinute', 'everyFiveMinutes'…).
    |            null = pas de scheduling automatique (appel manuel ou cron système).
    */

    'heartbeat' => [
        'enabled' => env('HEALTH_HEARTBEAT_ENABLED', false),
        'schedule' => env('HEALTH_HEARTBEAT_SCHEDULE', 'everyMinute'),
    ],

];
