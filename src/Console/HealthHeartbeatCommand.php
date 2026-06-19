<?php

namespace Gysc\Observability\Console;

use Gysc\Observability\Http\Controllers\HealthController;
use Gysc\Observability\Support\OpenObserveClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;

class HealthHeartbeatCommand extends Command
{
    protected $signature = 'observability:heartbeat';

    protected $description = 'Exécute /health/deep et pousse le résultat vers OpenObserve';

    public function handle(): int
    {
        if (! config('observability.enabled')) {
            $this->warn('OPENOBSERVE_ENABLED=false — heartbeat désactivé.');

            return self::FAILURE;
        }

        $controller = app(HealthController::class);

        $start = microtime(true);
        $response = $controller->deep();
        $totalMs = round((microtime(true) - $start) * 1000, 2);

        $body = json_decode($response->getContent(), true);

        $payload = [
            '_timestamp' => (int) round(microtime(true) * 1_000_000),
            'level' => $body['status'] === 'ok' ? 'info' : 'error',
            'message' => 'health_check',
            'service' => config('observability.service', 'laravel'),
            'env' => app()->environment(),
            'status' => $body['status'],
            'http_status' => $response->getStatusCode(),
            'duration_ms' => $totalMs,
            'check_db' => $body['db'] ?? 'skipped',
            'check_cache' => $body['cache'] ?? 'skipped',
            'check_queue' => $body['queue'] ?? 'skipped',
            'queue_sizes' => $this->collectQueueSizes(),
        ];

        try {
            app(OpenObserveClient::class)->ingest([$payload]);
            $this->info("Heartbeat envoyé — status={$body['status']} ({$totalMs}ms)");
        } catch (\Throwable $e) {
            error_log('[observability] heartbeat ingest failed: '.$e->getMessage());
            $this->error('Envoi échoué : '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function collectQueueSizes(): array
    {
        $sizes = [];

        $queues = $this->resolveQueueNames();

        foreach ($queues as $name) {
            try {
                $sizes[$name] = Queue::size($name);
            } catch (\Throwable $e) {
                // Driver ne supporte pas size() — ignorer silencieusement
            }
        }

        return $sizes;
    }

    private function resolveQueueNames(): array
    {
        $names = [];

        $default = config('queue.connections.'.config('queue.default').'.queue', 'default');
        $names[] = $default;

        // Horizon expose ses queues dans la config
        if (class_exists('Laravel\Horizon\Horizon')) {
            try {
                $supervisors = config('horizon.defaults', []) + config('horizon.environments.'.app()->environment(), []);
                foreach ($supervisors as $supervisor) {
                    if (isset($supervisor['queue'])) {
                        $parsed = is_array($supervisor['queue'])
                            ? $supervisor['queue']
                            : explode(',', $supervisor['queue']);
                        foreach ($parsed as $q) {
                            $q = trim($q);
                            if ($q !== '' && ! in_array($q, $names, true)) {
                                $names[] = $q;
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Config Horizon inaccessible — on continue avec la queue par défaut
            }
        }

        return $names;
    }
}