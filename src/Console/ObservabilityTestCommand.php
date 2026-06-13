<?php

namespace Gysc\Observability\Console;

use Gysc\Observability\Support\OpenObserveClient;
use Illuminate\Console\Command;

/**
 * Envoie des payloads de test vers OpenObserve pour valider la configuration
 * et peupler les dashboards avec des données représentatives.
 *
 * Usage :
 *   php artisan observability:test
 *   php artisan observability:test --count=10
 *   php artisan observability:test --type=logs
 *   php artisan observability:test --type=slow_query,jobs,auth
 */
class ObservabilityTestCommand extends Command
{
    protected $signature = 'observability:test
                            {--count=3 : Nombre d\'envois par type de métrique}
                            {--type=* : Types à envoyer (logs,slow_query,http_request,jobs,auth,http_outbound). Tous si omis.}
                            {--dry-run : Affiche les payloads sans les envoyer}';

    protected $description = 'Envoie des données de test vers OpenObserve pour valider la config et peupler les dashboards';

    private const TYPES = ['logs', 'slow_query', 'http_request', 'jobs', 'auth', 'http_outbound'];

    public function handle(): int
    {
        $count  = max(1, (int) $this->option('count'));
        $types  = $this->option('type') ?: self::TYPES;
        $dryRun = (bool) $this->option('dry-run');

        if (! config('observability.enabled') && ! $dryRun) {
            $this->warn('OPENOBSERVE_ENABLED=false — utilisez --dry-run ou activez la config.');
            return self::FAILURE;
        }

        $service = config('observability.service', 'laravel');
        $env     = app()->environment();

        $this->info("Service : <comment>{$service}</comment> | Env : <comment>{$env}</comment> | Count : <comment>{$count}</comment> par type");
        $dryRun && $this->warn('Mode dry-run : aucun envoi réel.');
        $this->newLine();

        $batches = [];

        foreach ($types as $type) {
            if (! in_array($type, self::TYPES, true)) {
                $this->warn("Type inconnu ignoré : {$type}");
                continue;
            }

            $payloads = $this->{"generate_{$type}"}($count, $service, $env);
            $batches[$type] = $payloads;

            $this->line("  <info>✓</info> <comment>{$type}</comment> : {$count} payload(s) préparé(s)");
        }

        if ($dryRun) {
            $this->newLine();
            $this->line(json_encode(array_values($batches), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        $client = app(OpenObserveClient::class);
        $errors = 0;

        foreach ($batches as $type => $payloads) {
            try {
                $client->ingest($payloads);
                $this->line("  <info>→ envoyé</info> <comment>{$type}</comment>");
            } catch (\Throwable $e) {
                $this->error("  ✗ {$type} : " . $e->getMessage());
                $errors++;
            }
        }

        $this->newLine();

        if ($errors === 0) {
            $this->info('Tous les payloads ont été ingérés avec succès.');
            $this->line('Ouvre OpenObserve et ajuste la fenêtre temporelle à "Past 15 minutes".');
        } else {
            $this->warn("{$errors} type(s) ont échoué — vérifiez OPENOBSERVE_URL et OPENOBSERVE_TOKEN.");
        }

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Générateurs par type
    // ─────────────────────────────────────────────────────────────────────────

    private function generate_logs(int $count, string $service, string $env): array
    {
        $levels   = ['debug', 'info', 'info', 'info', 'warning', 'error', 'critical'];
        $messages = [
            'User profile updated successfully',
            'Cache miss on key user_permissions_42',
            'Scheduled task ran: send-daily-digest',
            'New file uploaded: report-2026-06.pdf',
            'Deprecated API endpoint called: /v1/users',
            'Unhandled exception in PaymentController@store',
            'Database connection pool exhausted',
        ];

        return array_map(fn($i) => [
            '_timestamp' => $this->ts(-$i * 30),
            'level'      => $levels[$i % count($levels)],
            'message'    => $messages[$i % count($messages)],
            'service'    => $service,
            'env'        => $env,
            'context'    => ['test' => true, 'iteration' => $i + 1],
        ], range(0, $count - 1));
    }

    private function generate_slow_query(int $count, string $service, string $env): array
    {
        $queries = [
            'SELECT * FROM orders o JOIN order_items oi ON oi.order_id = o.id WHERE o.status = ? AND o.created_at > ?',
            'SELECT u.*, p.* FROM users u LEFT JOIN profiles p ON p.user_id = u.id WHERE u.role = ?',
            'UPDATE sessions SET last_activity = ? WHERE user_id IN (SELECT id FROM users WHERE active = 1)',
            'SELECT COUNT(*) FROM logs WHERE created_at BETWEEN ? AND ? AND level = ?',
        ];

        return array_map(fn($i) => [
            '_timestamp'  => $this->ts(-$i * 45),
            'level'       => 'warning',
            'message'     => 'slow_query',
            'service'     => $service,
            'env'         => $env,
            'duration_ms' => round(500 + ($i + 1) * 250 + mt_rand(0, 100), 2),
            'sql'         => $queries[$i % count($queries)],
            'connection'  => 'mysql',
        ], range(0, $count - 1));
    }

    private function generate_http_request(int $count, string $service, string $env): array
    {
        $routes = [
            ['GET',  '/api/users',              200, 45],
            ['POST', '/api/tickets',            201, 120],
            ['GET',  '/dashboard',              200, 380],
            ['GET',  '/api/reports/monthly',    200, 950],
            ['PUT',  '/api/users/42',           422, 35],
            ['GET',  '/api/branches',           200, 210],
            ['POST', '/api/webhooks/onedev',    500, 55],
        ];

        return array_map(function ($i) use ($routes, $service, $env) {
            [$method, $path, $status, $baseMs] = $routes[$i % count($routes)];
            $status_code = $status;
            $level = $status_code >= 500 ? 'error' : ($status_code >= 400 ? 'warning' : 'info');

            return [
                '_timestamp'  => $this->ts(-$i * 20),
                'level'       => $level,
                'message'     => 'http_request',
                'service'     => $service,
                'env'         => $env,
                'method'      => $method,
                'path'        => $path,
                'status_code' => $status_code,
                'duration_ms' => round($baseMs + mt_rand(-10, 50), 2),
            ];
        }, range(0, $count - 1));
    }

    private function generate_jobs(int $count, string $service, string $env): array
    {
        $jobs = [
            ['App\\Jobs\\SendWeeklyReport',      'job_processed', null,                           null],
            ['App\\Jobs\\SyncUserPermissions',   'job_processed', null,                           null],
            ['App\\Jobs\\ProcessPaymentWebhook', 'job_failed',    'RuntimeException',             'Payment gateway timeout after 30s'],
            ['App\\Jobs\\GeneratePdfExport',     'job_processed', null,                           null],
            ['App\\Jobs\\SendSlackNotification', 'job_failed',    'Illuminate\\Http\\Client\\ConnectionException', 'cURL error 28: Connection timed out'],
            ['App\\Jobs\\CleanupExpiredSessions','job_processed', null,                           null],
            ['App\\Jobs\\ImportCsvData',         'job_timed_out', 'JobTimedOutException',         'Job exceeded maximum execution time of 60 seconds'],
        ];

        return array_map(function ($i) use ($jobs, $service, $env) {
            [$class, $message, $exClass, $exMsg] = $jobs[$i % count($jobs)];

            $payload = [
                '_timestamp' => $this->ts(-$i * 60),
                'level'      => $message === 'job_processed' ? 'info' : 'error',
                'message'    => $message,
                'service'    => $service,
                'env'        => $env,
                'job_class'  => $class,
                'queue'      => 'default',
                'connection' => 'redis',
                'attempts'   => $message === 'job_processed' ? 1 : mt_rand(1, 3),
            ];

            if ($exClass !== null) {
                $payload['exception_class']   = $exClass;
                $payload['exception_message'] = $exMsg;
            }

            return $payload;
        }, range(0, $count - 1));
    }

    private function generate_auth(int $count, string $service, string $env): array
    {
        $events = [
            ['auth_login',  'info',    'thomas@gysc.fr',  42],
            ['auth_login',  'info',    'alice@gysc.fr',   17],
            ['auth_failed', 'warning', 'unknown@test.com', null],
            ['auth_logout', 'info',    'thomas@gysc.fr',  42],
            ['auth_failed', 'warning', 'admin@gysc.fr',   null],
        ];

        return array_map(function ($i) use ($events, $service, $env) {
            [$message, $level, $email, $userId] = $events[$i % count($events)];

            $payload = [
                '_timestamp' => $this->ts(-$i * 120),
                'level'      => $level,
                'message'    => $message,
                'service'    => $service,
                'env'        => $env,
                'guard'      => 'web',
            ];

            if ($userId !== null) {
                $payload['user_id']    = $userId;
                $payload['user_email'] = $email;
            }

            if ($message === 'auth_failed') {
                $payload['attempted_email'] = $email;
            }

            return $payload;
        }, range(0, $count - 1));
    }

    private function generate_http_outbound(int $count, string $service, string $env): array
    {
        $calls = [
            ['POST', 'onedev.gysc.fr',              '/api/issue/comments',       201,  85],
            ['GET',  'api.anthropic.com',            '/v1/messages',              200, 1240],
            ['GET',  's3.eu-west-3.amazonaws.com',   '/bucket/file.json',         200,  32],
            ['POST', 'hooks.slack.com',              '/services/T00/B00/xxx',     200,  95],
            ['GET',  'api.anthropic.com',            '/v1/messages',              429,  12],
            ['POST', 'onedev.gysc.fr',              '/api/issue/comments',       500, 210],
        ];

        return array_map(function ($i) use ($calls, $service, $env) {
            [$method, $host, $path, $status, $baseMs] = $calls[$i % count($calls)];
            $level = $status >= 500 ? 'error' : ($status >= 400 ? 'warning' : 'info');

            return [
                '_timestamp'  => $this->ts(-$i * 25),
                'level'       => $level,
                'message'     => 'http_outbound',
                'service'     => $service,
                'env'         => $env,
                'method'      => $method,
                'host'        => $host,
                'path'        => $path,
                'status_code' => $status,
                'duration_ms' => round($baseMs + mt_rand(-5, 30), 2),
            ];
        }, range(0, $count - 1));
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function ts(int $offsetSeconds = 0): int
    {
        return (int) round((microtime(true) + $offsetSeconds) * 1_000_000);
    }
}
