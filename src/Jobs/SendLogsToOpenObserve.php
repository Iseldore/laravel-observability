<?php

namespace Gysc\Observability\Jobs;

use Gysc\Observability\Logging\OpenObserveHandler;
use Gysc\Observability\Support\OpenObserveClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Envoie un batch de logs déjà normalisés à OpenObserve, hors du cycle requête.
 *
 * Garanties :
 *  - fail-silent : toute erreur est avalée et journalisée en stderr direct (error_log),
 *    JAMAIS via Log:: (anti-récursion) ;
 *  - $tries = 1 : pas de retries qui empileraient des jobs ;
 *  - le batch est un tableau de tableaux scalaires → sérialisation triviale et sûre.
 */
class SendLogsToOpenObserve implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    /** Pas de retry : un log perdu est acceptable, un job qui s'empile ne l'est pas. */
    public int $timeout = 10;

    /**
     * @param  array<int, array<string, mixed>>  $batch
     */
    public function __construct(public array $batch)
    {
    }

    /**
     * Dispatch défensif : ne lève jamais vers l'appelant (le handler de log).
     * Applique la connexion/queue configurées et désactive l'envoi si non activé.
     *
     * @param  array<int, array<string, mixed>>  $batch
     */
    public static function dispatchSafe(array $batch): void
    {
        if (empty($batch) || ! config('observability.enabled')) {
            return;
        }

        try {
            $job = new self($batch);

            if ($connection = config('observability.logs.queue_connection')) {
                $job->onConnection($connection);
            }
            if ($queue = config('observability.logs.queue')) {
                $job->onQueue($queue);
            }

            dispatch($job);
        } catch (\Throwable $e) {
            // Le dispatch lui-même peut échouer (queue indisponible) → fail-silent.
            error_log('[observability] dispatch failed: '.$e->getMessage());
        }
    }

    public function handle(): void
    {
        if (! config('observability.enabled')) {
            return;
        }

        // Anti-récursion : si le worker logge pendant le traitement, le channel openobserve
        // doit ignorer ces logs (ils partiront vers le fallback du stack).
        OpenObserveHandler::$sending = true;
        try {
            app(OpenObserveClient::class)->ingest($this->batch);
        } catch (\Throwable $e) {
            // Fail-silent : stderr direct uniquement, jamais Log::.
            error_log('[observability] ingest failed: '.$e->getMessage());
        } finally {
            OpenObserveHandler::$sending = false;
        }
    }

    /**
     * Si la queue elle-même marque le job comme failed, ne rien propager de bruyant.
     */
    public function failed(\Throwable $e): void
    {
        error_log('[observability] job failed: '.$e->getMessage());
    }
}
