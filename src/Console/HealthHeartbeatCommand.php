<?php

namespace Gysc\Observability\Console;

use Gysc\Observability\Http\Controllers\HealthController;
use Gysc\Observability\Support\OpenObserveClient;
use Illuminate\Console\Command;

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
}