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
| `REQUEST_LOG=true` | `http_request` | method, path, status, duration_ms, memory_peak_kb, response_size |
| `OUTBOUND_HTTP_LOG=true` | `http_outbound` | method, host, path, status, duration_ms |
| `SLOW_QUERY_LOG=true` | `slow_query` | SQL, duration_ms, connection (seuil configurable) |
| `JOB_LOG=true` | `job_processed` / `job_failed` / `job_timed_out` | job_class, queue, attempts, exception |
| `AUTH_LOG=true` | `auth_login` / `auth_logout` / `auth_failed` | user_id, email, guard |
| `SCHEDULER_LOG=true` | `scheduled_task_finished` / `scheduled_task_failed` | task, expression, duration_s, exit_code |
| `EXCEPTION_LOG=true` | `exception` | exception_class, file, line, trace (5 frames) |
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

### Slow queries

```env
SLOW_QUERY_LOG=true
SLOW_QUERY_THRESHOLD_MS=1000          # seuil en ms
SLOW_QUERY_LOG_BINDINGS=false         # inclure les bindings (peut contenir des données sensibles)
```

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