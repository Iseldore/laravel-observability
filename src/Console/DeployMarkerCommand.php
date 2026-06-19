<?php

namespace Gysc\Observability\Console;

use Gysc\Observability\Support\OpenObserveClient;
use Illuminate\Console\Command;

class DeployMarkerCommand extends Command
{
    protected $signature = 'observability:deploy
                            {--commit= : SHA du commit (tente git rev-parse --short HEAD si absent)}
                            {--tag= : Tag/version (tente git describe --tags si absent)}
                            {--deployer= : Qui déploie (tente git config user.name si absent)}
                            {--message= : Message libre}';

    protected $description = 'Marque un déploiement dans OpenObserve';

    public function handle(): int
    {
        if (! config('observability.enabled')) {
            $this->warn('OPENOBSERVE_ENABLED=false — deploy marker désactivé.');

            return self::FAILURE;
        }

        $commit   = $this->option('commit') ?: $this->gitCommand('git rev-parse --short HEAD');
        $tag      = $this->option('tag') ?: $this->gitCommand('git describe --tags --abbrev=0 2>/dev/null');
        $deployer = $this->option('deployer') ?: $this->gitCommand('git config user.name');
        $deployMessage = $this->option('message');

        $payload = [
            '_timestamp' => (int) round(microtime(true) * 1_000_000),
            'level'      => 'info',
            'message'    => 'deploy',
            'service'    => config('observability.service', 'laravel'),
            'env'        => app()->environment(),
        ];

        if ($commit !== null) {
            $payload['commit'] = $commit;
        }
        if ($tag !== null) {
            $payload['tag'] = $tag;
        }
        if ($deployer !== null) {
            $payload['deployer'] = $deployer;
        }
        if ($deployMessage !== null) {
            $payload['deploy_message'] = $deployMessage;
        }

        $this->info('Deploy marker :');
        foreach ($payload as $key => $value) {
            if ($key === '_timestamp') {
                continue;
            }
            $this->line("  <comment>{$key}</comment> : {$value}");
        }

        try {
            app(OpenObserveClient::class)->ingest([$payload]);
            $this->newLine();
            $this->info('Deploy marker envoyé avec succès.');
        } catch (\Throwable $e) {
            error_log('[observability] deploy marker ingest failed: ' . $e->getMessage());
            $this->error('Envoi échoué : ' . $e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Exécute une commande git et retourne le résultat nettoyé, ou null en cas d'échec.
     *
     * @param  string  $command
     * @return string|null
     */
    private function gitCommand($command)
    {
        $output = [];
        $exitCode = 1;

        @exec($command, $output, $exitCode);

        if ($exitCode !== 0 || empty($output)) {
            return null;
        }

        $result = trim(implode("\n", $output));

        return $result !== '' ? $result : null;
    }
}
