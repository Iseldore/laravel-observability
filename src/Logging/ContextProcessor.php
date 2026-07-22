<?php

namespace Iseldore\Observability\Logging;

/**
 * Injecte un contexte applicatif partagé (`user_id`, `user_email`, tenant, …) dans `extra`
 * de CHAQUE record — y compris les `\Log::info()/error()` manuels du code métier.
 *
 * C'est le complément de RequestIdProcessor : celui-ci corrèle les logs d'une même requête,
 * celui-là leur donne le « qui » et le « où » nécessaires au support (« le client X a eu une
 * erreur »). Sans lui, un `Log::error('échec sync', ['order_id' => …])` au milieu d'un
 * contrôleur ne porte ni utilisateur ni tenant.
 *
 * Résolution paresseuse À CHAQUE record (pas figée au boot) : indispensable sous Octane, où
 * le processor persiste entre requêtes et l'utilisateur authentifié change d'une requête à
 * l'autre.
 *
 * Agnostique du modèle User de l'app : le résolveur est un callable fourni par la config
 * (`observability.context.resolver`). Défaut : `auth()->user()` + `getAuthIdentifier()`/`email`.
 * Ne lève jamais (contexte console / hors requête / résolveur défaillant) : l'observabilité
 * ne doit jamais casser l'app.
 *
 * Compat Monolog 2 (`array`) et 3 (`LogRecord`).
 */
class ContextProcessor
{
    /** @var callable(): array<string, scalar|null> */
    private $resolver;

    /**
     * @param  callable(): array<string, scalar|null>|null  $resolver  Retourne les paires
     *         clé/valeur à injecter (scalaires uniquement). `null` = résolveur par défaut.
     */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? [self::class, 'defaultContext'];
    }

    /**
     * @param  array|\Monolog\LogRecord  $record
     * @return array|\Monolog\LogRecord
     */
    public function __invoke($record)
    {
        $context = $this->resolveContext();

        if ($context === []) {
            return $record;
        }

        if (is_array($record)) {
            // Monolog 2.x — on ne surcharge jamais une clé déjà posée par l'appelant.
            $record['extra'] += $context;

            return $record;
        }

        // Monolog 3.x — LogRecord : `extra` mutable (non readonly).
        $record->extra += $context;

        return $record;
    }

    /**
     * Récupère le contexte via le résolveur, en garantissant un tableau de scalaires et
     * en n'échouant jamais.
     *
     * @return array<string, scalar|null>
     */
    private function resolveContext(): array
    {
        try {
            $raw = ($this->resolver)();
        } catch (\Throwable $e) {
            return [];
        }

        return self::onlyScalars($raw);
    }

    /**
     * Filtre un tableau brut pour ne garder que des paires clé/scalaire, en avalant tout
     * type non conforme — un objet/tableau ferait exploser le schéma OpenObserve (cf.
     * RecordNormalizer). Partagée avec les appelants hors Monolog (`RequestLogger`,
     * `OutboundHttpLogger`) qui résolvent le même contexte applicatif.
     *
     * @param  mixed  $raw
     * @return array<string, scalar|null>
     */
    public static function onlyScalars($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $clean = [];
        foreach ($raw as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $clean[(string) $key] = $value;
            }
        }

        return $clean;
    }

    /**
     * Résout le contexte configuré (`observability.context.resolver` ?? défaut) sans jamais
     * lever, pour les appelants hors Monolog. Retourne un tableau de scalaires uniquement.
     *
     * @return array<string, scalar|null>
     */
    public static function resolveConfigured(): array
    {
        $resolver = config('observability.context.resolver') ?? [self::class, 'defaultContext'];

        try {
            $raw = $resolver();
        } catch (\Throwable $e) {
            return [];
        }

        return self::onlyScalars($raw);
    }

    /**
     * Résolveur par défaut : identifiant + email de l'utilisateur authentifié, sans donnée
     * sensible (jamais de mot de passe/token). Renvoie `[]` hors contexte authentifié.
     *
     * @return array<string, scalar|null>
     */
    public static function defaultContext(): array
    {
        if (! function_exists('auth')) {
            return [];
        }

        try {
            $user = auth()->user();
        } catch (\Throwable $e) {
            return [];
        }

        if ($user === null) {
            return [];
        }

        $context = [];
        if (method_exists($user, 'getAuthIdentifier')) {
            $context['user_id'] = $user->getAuthIdentifier();
        }
        if (isset($user->email) && is_scalar($user->email)) {
            $context['user_email'] = $user->email;
        }

        return $context;
    }
}
