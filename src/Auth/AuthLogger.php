<?php

namespace Iseldore\Observability\Auth;

use Iseldore\Observability\Jobs\SendLogsToOpenObserve;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

/**
 * Logge les événements d'authentification Laravel.
 *
 * Login   → level info
 * Logout  → level info
 * Failed  → level warning (tentative échouée — peut indiquer brute-force)
 *
 * Aucune donnée sensible : on n'envoie pas le mot de passe.
 * L'email/identifiant est inclus uniquement si c'est une string (pas d'objet).
 */
class AuthLogger
{
    public function handleLogin(Login $event): void
    {
        $this->dispatch('auth_login', 'info', $event->guard, $event->user);
    }

    public function handleLogout(Logout $event): void
    {
        $this->dispatch('auth_logout', 'info', $event->guard, $event->user);
    }

    public function handleFailed(Failed $event): void
    {
        $this->dispatch('auth_failed', 'warning', $event->guard, $event->user, $event->credentials);
    }

    private function dispatch(string $message, string $level, string $guard, $user, array $credentials = []): void
    {
        try {
            $config = config('observability');

            if (! ($config['enabled'] ?? false)) {
                return;
            }

            $payload = [
                '_timestamp' => (int) round(microtime(true) * 1000000),
                'level'      => $level,
                'message'    => $message,
                'service'    => (string) ($config['service'] ?? 'laravel'),
                'env'        => app()->environment(),
                'guard'      => $guard,
            ];

            // Identifier l'utilisateur sans exposer de données sensibles
            if ($user !== null) {
                if (method_exists($user, 'getAuthIdentifier')) {
                    $payload['user_id'] = $user->getAuthIdentifier();
                }
                if (isset($user->email)) {
                    $payload['user_email'] = $user->email;
                }
            }

            // Pour auth_failed, inclure l'email tenté (jamais le mot de passe)
            if ($message === 'auth_failed' && isset($credentials['email'])) {
                $payload['attempted_email'] = $credentials['email'];
            }

            SendLogsToOpenObserve::dispatchSafe([$payload]);
        } catch (\Throwable $e) {
            error_log('[observability] auth_logger failed: '.$e->getMessage());
        }
    }
}
