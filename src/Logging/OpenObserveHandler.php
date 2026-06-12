<?php

namespace Gysc\Observability\Logging;

use Gysc\Observability\Jobs\SendLogsToOpenObserve;
use Monolog\Handler\AbstractProcessingHandler;

/**
 * Handler Monolog qui transforme les records en payloads OpenObserve et dispatche
 * leur envoi sur la queue (via SendLogsToOpenObserve).
 *
 * Pensé pour être enveloppé dans un BufferHandler : le vrai travail se fait dans
 * `handleBatch()`, appelé une fois par requête au flush du buffer. `write()` reste
 * un no-op (le buffering est délégué au BufferHandler parent).
 *
 * Compat Monolog 2 (`array`) et 3 (`LogRecord`) : `write()` n'est pas typé.
 */
class OpenObserveHandler extends AbstractProcessingHandler
{
    /**
     * Garde anti-récursion partagée : vraie pendant le flush/dispatch. Tout log émis
     * durant cette fenêtre est ignoré par CE handler (il continue vers les autres
     * channels du stack), évitant une boucle de logs → jobs → logs.
     */
    public static bool $sending = false;

    private string $service;

    private string $env;

    public function __construct(string $service, string $env, $level = 100, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
        $this->service = $service;
        $this->env = $env;
    }

    /**
     * No-op : le buffering est assuré par le BufferHandler enveloppant.
     *
     * @param  array|\Monolog\LogRecord  $record
     */
    protected function write($record): void
    {
        // intentionnellement vide — voir handleBatch()
    }

    /**
     * Appelé par BufferHandler::flush() en fin de requête avec tous les records bufferisés.
     *
     * @param  array<int, array|\Monolog\LogRecord>  $records
     */
    public function handleBatch(array $records): void
    {
        if (self::$sending || empty($records)) {
            return;
        }

        self::$sending = true;
        try {
            $payloads = [];
            foreach ($records as $record) {
                $payloads[] = RecordNormalizer::toArray($record, $this->service, $this->env);
            }

            SendLogsToOpenObserve::dispatchSafe($payloads);
        } catch (\Throwable $e) {
            // Fail-silent absolu : un échec de logging ne doit jamais casser l'app.
            error_log('[observability] handleBatch failed: '.$e->getMessage());
        } finally {
            self::$sending = false;
        }
    }
}
