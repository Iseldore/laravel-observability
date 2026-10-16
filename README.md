# iseldore/laravel-observability

Package Laravel d'observabilité pour [OpenObserve](https://openobserve.ai) : logs structurés envoyés en
**queue asynchrone** (fail-silent) + routes **health** standardisées (liveness pour l'ALB, deep pour le
monitoring). Compatible **Laravel 8 → 13** et **Monolog 2 & 3**.

## Installation

```bash
composer require iseldore/laravel-observability
php artisan vendor:publish --tag=observability-config
```

## Configuration `.env`

```env
OPENOBSERVE_ENABLED=true              # false en local/test
OPENOBSERVE_URL=https://openobserve.example.com
OPENOBSERVE_ORG=default
OPENOBSERVE_STREAM=mon-app            # un stream par application
OPENOBSERVE_USER=...                  # token user OpenObserve
OPENOBSERVE_TOKEN=...
OBSERVABILITY_SERVICE=mon-app-prod
HEALTH_TOKEN=...                      # protège /health/deep
```

## Logs → OpenObserve

Ajouter le channel `openobserve` dans `config/logging.php` et le placer en tête du stack par défaut,
**avec un fallback** (les logs continuent même si OpenObserve est down) :

```php
'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['openobserve', 'stderr'], // adapter le fallback à l'app
        'ignore_exceptions' => true,
    ],

    'openobserve' => [
        'driver' => 'custom',
        'via' => \Iseldore\Observability\Logging\OpenObserveChannelFactory::class,
        'level' => env('LOG_LEVEL', 'debug'),
    ],
],
```

Les logs sont bufferisés par requête puis envoyés via un job en queue. Si OpenObserve est injoignable,
l'envoi échoue silencieusement — l'application n'est jamais impactée.

Chaque log porte un champ `request_id` (repris de l'en-tête `X-Request-Id` ou `X-Amzn-Trace-Id`,
sinon un UUID v4 généré) pour corréler tous les logs d'une même requête. Les clés de `context` /
`extra` sont aplaties en colonnes préfixées (`context_<clé>`, `extra_<clé>`) ; tout sous-tableau ou
objet est sérialisé en une seule colonne JSON pour garder un schéma OpenObserve stable.

> ℹ️ Le format de `request_id` varie selon la source du header : `X-Request-Id` est repris tel
> quel (souvent un UUID applicatif), `X-Amzn-Trace-Id` (posé par l'ALB) est conservé brut sous sa
> forme `Root=1-<...>-<...>;Parent=...;Sampled=...` plutôt que d'en extraire uniquement `Root=`.
> Ce choix est volontaire : la valeur brute reste directement grep-able dans AWS X-Ray/CloudWatch
> pour croiser les traces ALB. Ce n'est pas un bug — un support qui corrèle par `request_id` doit
> juste s'attendre à deux formats possibles selon que l'appelant fournissait `X-Request-Id` ou
> passait par un ALB avec X-Ray activé.

### Exceptions : un seul document par erreur

Un `Log::error('…', ['exception' => $e])` produit un **seul** document, `message=exception`
(mode `merge`, défaut) : il porte le message humain du log (`log_message`), son contexte
(`context_*`), l'exception (`exception_class`, `exception_message`, `exception_hash`) et deux
traces — `exception_trace` (5 premières frames) et `exception_app_trace` (premières frames **hors
`vendor/`**, pour retrouver le code applicatif qui a déclenché un appel de librairie).
`exception_hash` (classe + fichier + ligne) permet de compter des erreurs distinctes et relie
`exception` et `job_failed`.

```env
EXCEPTION_LOG_MODE=merge       # legacy = record brut ET document exception (ancien comportement)
EXCEPTION_LOG_APP_FRAMES=5
```

> ⚠️ En mode `merge` le record Monolog brut n'est plus envoyé : un filtre sur le texte du log
> (`message LIKE '…'`) doit viser `log_message`. Les requêtes qui lisent seulement `message` pour
> lister les erreurs (ex. le tool `observe_recent_errors`) doivent aussi sélectionner
> `exception_class` / `exception_message` / `log_message`.

## Health

- `GET /health` — **liveness pure** : toujours `200`, aucune dépendance. À brancher sur l'ALB.
- `GET /health/deep` — DB + cache + queue. `200` si tout va bien, `503` si un composant échoue.
  Protégée par `HEALTH_TOKEN` (`?token=` ou header `X-Health-Token`) + rate-limit.

> ⚠️ **À faire manuellement dans chaque app** : exempter `health` du mode maintenance, sinon `artisan down`
> rend `/health` indisponible et l'ALB tue les tasks. Ajouter `'health'` à `$except` de
> `app/Http/Middleware/PreventRequestsDuringMaintenance.php`.

## Listeners automatiques

Chaque listener est activable individuellement via `.env`. Tous sont fail-silent et n'impactent jamais l'application.

| Variable `.env` | Type de log | Données envoyées |
|-----------------|-------------|------------------|
| `REQUEST_LOG=true` | `http_request` | method, path, route / route_uri / route_action, status, duration_ms, memory_peak_kb, response_size |
| `OUTBOUND_HTTP_LOG=true` | `http_outbound` | method, host, path, status, duration_ms ; en cas d'échec de connexion : error_class, error_message |
| `SLOW_QUERY_LOG=true` | `slow_query` | SQL, sql_fingerprint, sql_hash, bindings_count, duration_ms, connection (seuil configurable) |
| `JOB_LOG=true` | `job_processed` / `job_failed` / `job_timed_out` | job_class, job_uuid, queue, attempts, request_id, duration_ms, exception |
| `AUTH_LOG=true` | `auth_login` / `auth_logout` / `auth_failed` | user_id, email, guard, request_id |
| `SCHEDULER_LOG=true` | `scheduled_task_finished` / `scheduled_task_failed` | task, expression, duration_s, exit_code |
| `EXCEPTION_LOG=true` | `exception` | exception_class, file, line, exception_hash, trace (5 frames), exception_app_trace, log_message |
| `CACHE_LOG=true` | `cache_stats` | hits, misses, hit_ratio (agrégé par requête) |

### Performance

L'envoi vers OpenObserve est **toujours déporté en queue** (`SendLogsToOpenObserve`, fail-silent) :
le cycle requête n'est jamais bloqué par le réseau. Le package suppose donc une **queue
asynchrone** (redis, sqs, database) — avec `QUEUE_CONNECTION=sync` (dev local), l'envoi
redevient synchrone et bloquant.

Coûts à connaître :

- `CACHE_LOG` écoute **chaque** hit/miss de cache : son overhead (un compteur incrémenté en
  mémoire, agrégé en un seul payload par requête) est proportionnel au volume d'accès cache.
  À réserver aux apps où cette métrique a de la valeur.
- `SLOW_QUERY_LOG` filtre sur le seuil **avant** toute allocation : une requête sous le seuil
  ne coûte quasiment rien.
- `REQUEST_LOG` lit la taille de réponse via l'en-tête `Content-Length` quand il est présent,
  pour éviter de matérialiser le corps en mémoire.

### Réduire le volume

| Option | Effet |
|---|---|
| `request_log.log_health=false` (défaut) | `/health` et `/health/deep` ne sont pas loggés |
| `request_log.except` | patterns (path ou nom de route) exclus |
| `request_log.sampling` | `['api/v1/login' => 0.1]` : ne garde que ~10 % des réponses < 400 plus rapides que `sampling_always_above_ms` (1000) ; erreurs et requêtes lentes toujours conservées |
| `job_log.except` | classes de jobs dont le `job_processed` est masqué (un échec est toujours loggé) |
| `logs.except_messages` | patterns de messages de logs de niveau < warning à ne pas envoyer |
| `outbound_http_log.except_hosts` | hôtes sortants ignorés (ex. service de rendu local) |
| `auth_log.events` | `['logout','failed']` pour ne plus logger les `auth_login` |

> Une ligne `http_request` échantillonnée porte `sample_rate` : le nombre réel de requêtes vaut
> `sum(1 / sample_rate)` (lignes sans `sample_rate` : poids 1). Les agrégats de latence restent
> représentatifs ; les comptages bruts doivent être ré-échelonnés.

`request_log.db_stats=true` (désactivé par défaut) ajoute `db_query_count` et `db_time_ms` à
`http_request` et `job_*` pour repérer les N+1 qu'aucun `slow_query` ne signale.

### Slow queries

```env
SLOW_QUERY_LOG=true
SLOW_QUERY_THRESHOLD_MS=1000          # seuil en ms
SLOW_QUERY_LOG_BINDINGS=false         # inclure les bindings (peut contenir des données sensibles)
SLOW_QUERY_MAX_SQL_LENGTH=2000        # troncature de sql / sql_fingerprint (0 = illimité)
```

`sql_fingerprint` est le SQL normalisé (valeurs retirées, `IN (?, ?, …)` → `IN (?+)`) et `sql_hash`
son md5 : `GROUP BY sql_hash` regroupe les exécutions d'une même requête quelle que soit la taille
des listes `IN`.

## Heartbeat

La commande `observability:heartbeat` appelle `/health/deep` en interne, mesure la latence de chaque composant (DB, cache, queue), collecte la taille des queues (compatible Horizon), et pousse le résultat dans OpenObserve.

```env
HEALTH_HEARTBEAT_ENABLED=true
HEALTH_HEARTBEAT_SCHEDULE=everyMinute   # méthode Laravel Schedule
```

Le scheduling est automatique via le ServiceProvider — il suffit que `schedule:run` tourne en cron.

Le payload `health_check` contient : `status`, `duration_ms`, `check_db`, `check_cache`, `check_queue`, `queue_sizes`.

## Marqueur de déploiement

```bash
php artisan observability:deploy
php artisan observability:deploy --tag=v1.2.0 --message="Hotfix login"
```

Envoie un log `message=deploy` avec le commit SHA, le tag, le deployer (détectés automatiquement via git si non fournis). À intégrer dans le pipeline CI/CD pour corréler les incidents avec les déploiements.

## Commande de test

```bash
php artisan observability:test                    # tous les types
php artisan observability:test --type=health_check,deploy
php artisan observability:test --count=10 --dry-run
```

Génère des payloads réalistes pour tous les types de logs : `logs`, `slow_query`, `http_request`, `jobs`, `auth`, `http_outbound`, `health_check`, `deploy`, `scheduled_task`, `exception`, `cache_stats`.