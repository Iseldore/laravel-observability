<?php

namespace Gysc\Observability\Support;

use Illuminate\Support\Facades\Http;

/**
 * Client HTTP minimal vers l'API d'ingestion OpenObserve (`_json`).
 *
 * Ne logge jamais via `Log::` (anti-récursion) : en cas d'erreur, l'appelant décide
 * (le job avale et journalise en stderr direct).
 */
class OpenObserveClient
{
    /**
     * Envoie un batch de logs (tableau d'objets) sur le stream configuré.
     *
     * @param  array<int, array<string, mixed>>  $batch
     *
     * @throws \Throwable  si la requête échoue (connexion, timeout, statut HTTP en erreur)
     */
    public function ingest(array $batch): void
    {
        $config = config('observability.openobserve');

        $url = rtrim((string) $config['url'], '/')
            .'/api/'.rawurlencode((string) $config['org'])
            .'/'.rawurlencode((string) $config['stream'])
            .'/_json';

        $request = Http::withBasicAuth((string) $config['user'], (string) $config['token'])
            ->timeout((float) $config['timeout'])
            ->acceptJson()
            ->asJson();

        // connectTimeout() n'existe qu'à partir de Laravel 9 — appel conditionnel
        // pour rester compatible Laravel 8 (Marlenka).
        if (method_exists($request, 'connectTimeout')) {
            $request = $request->connectTimeout((float) $config['connect_timeout']);
        }

        $response = $request->post($url, $batch);

        // Lève une exception sur statut 4xx/5xx — capturée par le job (fail-silent).
        $response->throw();
    }
}
