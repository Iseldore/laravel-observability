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
OPENOBSERVE_URL=https://observe.iseldore.fr
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
        'via' => \Gysc\Observability\Logging\OpenObserveChannelFactory::class,
        'level' => env('LOG_LEVEL', 'debug'),
    ],
],
```

Les logs sont bufferisés par requête puis envoyés via un job en queue. Si OpenObserve est injoignable,
l'envoi échoue silencieusement — l'application n'est jamais impactée.

## Health

- `GET /health` — **liveness pure** : toujours `200`, aucune dépendance. À brancher sur l'ALB.
- `GET /health/deep` — DB + cache + queue. `200` si tout va bien, `503` si un composant échoue.
  Protégée par `HEALTH_TOKEN` (`?token=` ou header `X-Health-Token`) + rate-limit.

> ⚠️ **À faire manuellement dans chaque app** : exempter `health` du mode maintenance, sinon `artisan down`
> rend `/health` indisponible et l'ALB tue les tasks. Ajouter `'health'` à `$except` de
> `app/Http/Middleware/PreventRequestsDuringMaintenance.php`.
