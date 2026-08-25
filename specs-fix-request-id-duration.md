# Specs — Corriger request_id, duration_ms et contexte utilisateur sur http_request/http_outbound

## Contexte

Le MCP `gyscake-observe` (GYSCake) a été refondu en 2026-07 pour interroger directement les
streams OpenObserve réels par app/environnement. En vérifiant les tools sur des données de
prod (`lemonpie_develop`), deux défauts structurels ont été constatés sur les logs émis par
ce package :

1. **`request_id` n'est posé que sur les logs passés par Monolog** (`Log::info/error`,
   exceptions) — jamais sur `http_request`/`http_outbound`, qui représentent l'essentiel du
   volume (1857 logs `http_request` sur ~2063 logs totaux observés, contre seulement 2 avec
   `request_id`). Un support qui reçoit un `request_id` (header `X-Request-Id`) ne peut donc
   quasiment jamais corréler les logs de la requête correspondante.
2. **`duration_ms` est quasi absent sur `http_request`** (3 lignes sur 1857 observées) alors
   qu'il est présent à 100% sur `http_outbound`. Cause : `duration_ms` dépend de la constante
   globale `LARAVEL_START`, définie une seule fois par `public/index.php` au boot du process.
   Sous Octane — confirmé utilisé ici par les commentaires de `RequestIdProcessor` et
   `ContextProcessor` eux-mêmes, et par `ObservabilityServiceProvider::registerOctaneFlush()`
   — un worker traite des centaines/milliers de requêtes sans jamais réexécuter
   `public/index.php` : `LARAVEL_START` reste figée au boot du worker, pas à la requête
   courante.

Root cause commune aux deux points : `RequestLogger` et `OutboundHttpLogger`
(`src/Http/*.php`) construisent leur payload à la main et l'envoient directement via
`SendLogsToOpenObserve::dispatchSafe()`, **sans jamais passer par un `Logger` Monolog**. Or
`RequestIdProcessor` et `ContextProcessor` (qui posent `user_id`/`user_email`) ne
s'exécutent que sur les records qui transitent par un channel Monolog — donc jamais sur ces
deux event listeners.

**Conséquence additionnelle non mesurée mais same-cause** : `user_id`/`user_email`
(`ContextProcessor::defaultContext()`) sont eux aussi absents de `http_request`/
`http_outbound` pour la même raison structurelle. À corriger dans le même effort.

## Objectif

- `request_id` présent sur 100% des logs `http_request` et `http_outbound`.
- `duration_ms` présent et correct sur 100% des logs `http_request`, y compris sous Octane
  (process long-vécu, plusieurs requêtes par worker).
- `user_id`/`user_email` présents sur `http_request`/`http_outbound` quand une session
  authentifiée existe (parité avec ce que `ContextProcessor` fait déjà pour les logs
  Monolog manuels).
- Ne pas casser le comportement existant sur Monolog (`Log::info/error`, `ExceptionLogger`)
  ni la compat Monolog 2/3 (déjà gérée par `RequestIdProcessor`/`ContextProcessor`).

## Non-objectifs

- Ne pas fusionner les deux chemins d'émission (event listeners directs vs channel Monolog)
  en un seul pipeline unifié — trop large pour cet effort, et le chemin direct existe pour
  de bonnes raisons (payload contrôlé, pas de coût du `BufferHandler`/normalizer sur des
  logs à très haut volume). L'objectif est de réutiliser la LOGIQUE de résolution
  (`request_id`, contexte utilisateur), pas l'architecture Monolog.
- Ne pas modifier `RecordNormalizer`, `OpenObserveHandler`, `OpenObserveChannelFactory` :
  hors périmètre, ces classes ne sont pas concernées par le chemin direct.
- Ne pas changer le format de `duration_ms` sur `http_outbound` (déjà correct).

## Correctifs à apporter

### 1. `request_id` sur `RequestLogger` et `OutboundHttpLogger`

`RequestIdProcessor::resolveRequestId()` (privée, `src/Logging/RequestIdProcessor.php:44`)
contient déjà toute la logique nécessaire : lire `X-Request-Id`/`X-Amzn-Trace-Id` sur la
requête courante, sinon générer un UUID v4. Cette méthode doit devenir réutilisable hors du
processor Monolog.

Actions :
- Extraire `resolveRequestId()` et `uuid4()` de `RequestIdProcessor` vers une classe/méthode
  statique partagée (ex: `Iseldore\Observability\Support\RequestId::resolve()`), sans
  changer le comportement (mêmes headers, même format UUID).
- `RequestIdProcessor::__invoke()` appelle cette méthode partagée au lieu de la logique
  inline — aucun changement de comportement observable pour les logs Monolog existants.
- `RequestLogger::handle()` (`src/Http/RequestLogger.php:18`) et
  `OutboundHttpLogger::handle()` (`src/Http/OutboundHttpLogger.php:19`) ajoutent
  `$payload['request_id'] = RequestId::resolve();` avant `dispatchSafe()`.
- **Important** : pour que les logs `http_request`, `http_outbound`, et tout log Monolog
  manuel émis PENDANT la même requête HTTP partagent le même `request_id`, la résolution
  doit rester basée sur le header entrant en priorité (déjà le cas) — le point d'attention
  est que `RequestLogger` et `OutboundHttpLogger` appellent chacun `resolve()`
  indépendamment. Si le header est absent, `RequestIdProcessor` (Monolog) et `RequestLogger`
  génèrent CHACUN un UUID différent pour la même requête → pas de corrélation possible. Il
  faut donc mémoriser l'id généré (pas seulement le header lu) pour la durée de la requête,
  probablement via `app()->instance(...)` ou un binding singleton résolu paresseusement à la
  première résolution puis réutilisé par tous les appelants suivants dans la même requête.
  Sous Octane, s'assurer que ce singleton est bien réinitialisé à chaque requête (pas figé
  au boot du worker comme `LARAVEL_START`) — utiliser un binding scope-requête (`scoped()`
  Laravel, ou un flush explicite dans `registerOctaneFlush()`/événement `RequestReceived`
  d'Octane), pas un simple attribut statique de classe.

### 2. `duration_ms` fiable sous Octane sur `RequestLogger`

`LARAVEL_START` (`src/Http/RequestLogger.php:36`) est structurellement incompatible avec un
process long-vécu : à remplacer par un timer posé au tout début de CHAQUE requête, pas au
boot du process.

Options (à trancher en implémentation, pas figées ici) :
- **Middleware dédié** posé le plus tôt possible dans la pile (`web`/`api` + routes
  `/health`), qui enregistre `microtime(true)` dans un binding scope-requête au moment où il
  s'exécute — `RequestLogger::handle()` (déclenché sur `RequestHandled`, donc après la
  réponse) lit ce timestamp au lieu de `LARAVEL_START`.
- Alternative plus simple si Octane expose un événement de début de requête
  (`Laravel\Octane\Events\RequestReceived`) : y poser le timestamp dans le même binding
  scope-requête que pour `request_id` (point 1), évitant d'ajouter un middleware.

Dans les deux cas : si aucun timestamp de début n'a pu être capturé (contexte anormal, pas
de middleware exécuté), **omettre `duration_ms`** comme le code actuel le fait déjà
(`if ($durationMs !== null)`) plutôt que d'écrire une valeur aberrante — ce garde-fou existe
déjà et doit être conservé.

### 3. `user_id`/`user_email` sur `RequestLogger` et `OutboundHttpLogger`

`ContextProcessor::defaultContext()` (`src/Logging/ContextProcessor.php:100`, publique
statique) est déjà directement appelable sans dépendre de Monolog.

Actions :
- `RequestLogger::handle()` et `OutboundHttpLogger::handle()` appellent le résolveur de
  contexte configuré (`observability.context.resolver` ?? `ContextProcessor::defaultContext`)
  et fusionnent le résultat scalaire dans le payload, avec le même filtrage "scalaires
  uniquement" que `ContextProcessor::resolveContext()` (dupliquer ou extraire cette
  validation dans une fonction partagée plutôt que de la réécrire).
- Respecter la même règle de non-écrasement que `ContextProcessor` (`$record['extra'] +=
  $context` — ne jamais écraser une clé déjà posée) : si le payload construit par
  `RequestLogger` a déjà une clé identique à une clé de contexte, le contexte ne doit pas
  l'écraser.

## Fichiers concernés

- `src/Logging/RequestIdProcessor.php` — extraire `resolveRequestId()`/`uuid4()`.
- `src/Http/RequestLogger.php` — ajouter `request_id`, remplacer `LARAVEL_START`, ajouter
  contexte utilisateur.
- `src/Http/OutboundHttpLogger.php` — ajouter `request_id`, ajouter contexte utilisateur.
- `src/Logging/ContextProcessor.php` — exposer/extraire la validation "scalaires
  uniquement" si mutualisée.
- Nouveau : `src/Support/RequestId.php` (ou emplacement équivalent) — résolution partagée
  request_id + timestamp de début de requête, scope-requête (pas figée au boot du process).
- `src/ObservabilityServiceProvider.php` — si un middleware ou un événement Octane
  supplémentaire est nécessaire pour poser le timestamp de début de requête (point 2).

## Tests à ajouter (Pest, cf. `tests/Pest.php`/`tests/TestCase.php` pour les conventions)

- `request_id` identique entre `RequestLogger` et un `Log::info()` manuel émis pendant la
  même requête simulée (avec et sans header `X-Request-Id` entrant).
- `request_id` différent entre deux requêtes simulées successives (pas de fuite d'un
  process/worker à l'autre — test qui aurait détecté le bug `LARAVEL_START` s'il existait
  pour request_id).
- `duration_ms` présent et plausible (>0, <quelques secondes) sur `http_request` dans un
  test qui simule DEUX requêtes handled successivement dans le même test (reproduit un
  worker Octane qui survit à plusieurs requêtes) — doit échouer sur le code actuel
  (`LARAVEL_START` figé à la première requête donnerait une durée aberrante ou absente sur
  la deuxième), passer après le fix.
- `user_id`/`user_email` présents sur `http_request` quand un utilisateur est authentifié
  au moment de la requête ; absents proprement (pas d'erreur) hors contexte authentifié.
- Non-régression : les tests existants `LoggingTest.php`/`ContextProcessorTest.php`
  continuent de passer sans modification de comportement sur le chemin Monolog.

## Risques / points d'attention

- Toute résolution de contexte (`request_id`, `user_id`) doit rester non-bloquante :
  `RequestLogger`/`OutboundHttpLogger` sont déjà enveloppés dans un `try/catch` global qui
  logge en `error_log` et ne relance jamais (`catch (\Throwable $e)`) — s'assurer que les
  nouveaux appels restent sous cette protection, pas de nouveau point de défaillance capable
  de casser une requête applicative.
- Le binding scope-requête pour mémoriser `request_id`/timestamp doit être correctement
  nettoyé/réinitialisé à chaque requête sous Octane — un oubli reproduirait exactement le
  bug `LARAVEL_START` actuel, mais pour la nouvelle donnée. Tester explicitement ce cas
  (deux requêtes successives dans le même worker/process de test).
- Vérifier l'impact sur le volume/coût : ajouter `request_id` (UUID, ~36 caractères) et deux
  colonnes de contexte à ~100% des logs `http_request`/`http_outbound` augmente légèrement
  le volume de données ingérées — probablement négligeable, mais à mentionner dans la PR.

## Déploiement

Une fois corrigé et testé, le package doit être republié et déployé sur LemonPie (au moins
`develop`, déjà instrumenté) avant que le MCP `gyscake-observe` puisse considérer
`observe_request_trace`/le contexte utilisateur comme fiables — actuellement documentés
comme limitation connue dans `mcp/observe/server.ts` (GYSCake), à retirer de cette
documentation une fois le fix vérifié en conditions réelles (nouvelle vérification via
`observe_logs_search` sur `request_id`/`duration_ms`/`user_id`, comme celle qui a révélé le
problème).
