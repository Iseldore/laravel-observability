<?php

namespace Gysc\Observability\Cache;

use Gysc\Observability\Jobs\SendLogsToOpenObserve;

class CacheLogger
{
    private static $hits = 0;

    private static $misses = 0;

    public function handleHit($event): void
    {
        if ($this->isObservabilityKey($event->key)) {
            return;
        }

        self::$hits++;
    }

    public function handleMissed($event): void
    {
        if ($this->isObservabilityKey($event->key)) {
            return;
        }

        self::$misses++;
    }

    public function flush(): void
    {
        $total = self::$hits + self::$misses;

        if ($total === 0) {
            return;
        }

        try {
            $config = config('observability');

            if (! ($config['enabled'] ?? false)) {
                self::reset();

                return;
            }

            $payload = [
                '_timestamp' => (int) round(microtime(true) * 1_000_000),
                'level'      => 'info',
                'message'    => 'cache_stats',
                'service'    => (string) ($config['service'] ?? 'laravel'),
                'env'        => app()->environment(),
                'hits'       => self::$hits,
                'misses'     => self::$misses,
                'hit_ratio'  => round(self::$hits * 100.0 / $total, 1),
            ];

            SendLogsToOpenObserve::dispatchSafe([$payload]);
        } catch (\Throwable $e) {
            error_log('[observability] cache_logger flush failed: '.$e->getMessage());
        } finally {
            self::reset();
        }
    }

    public static function reset(): void
    {
        self::$hits = 0;
        self::$misses = 0;
    }

    private function isObservabilityKey($key): bool
    {
        return is_string($key) && strpos($key, 'observability:') === 0;
    }
}