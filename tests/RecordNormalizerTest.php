<?php

use Iseldore\Observability\Logging\RecordNormalizer;

it('normalise un record Monolog 2 (array)', function () {
    $record = [
        'message' => 'hello',
        'level' => 400,
        'level_name' => 'ERROR',
        'datetime' => new DateTimeImmutable('2026-01-01T00:00:00.500000+00:00'),
        'context' => ['foo' => 'bar'],
        'extra' => ['request_id' => 'abc-123'],
    ];

    $out = RecordNormalizer::toArray($record, 'svc', 'testing');

    expect($out['level'])->toBe('error')
        ->and($out['message'])->toBe('hello')
        ->and($out['service'])->toBe('svc')
        ->and($out['env'])->toBe('testing')
        ->and($out['request_id'])->toBe('abc-123')
        // context aplati en colonnes préfixées context_<clé>
        ->and($out['context_foo'])->toBe('bar')
        ->and($out)->not->toHaveKey('context')
        // request_id promu au premier niveau et retiré de extra (pas de doublon extra_request_id)
        ->and($out)->not->toHaveKey('extra_request_id')
        ->and($out['_timestamp'])->toBeInt();
});

it('normalise un record Monolog 3 (LogRecord) si disponible', function () {
    if (! class_exists(\Monolog\LogRecord::class)) {
        $this->markTestSkipped('Monolog 2 : LogRecord absent');
    }

    // Arguments positionnels (datetime, channel, level, message, context, extra) :
    // pas d'arguments nommés, pour que ce fichier parse aussi sous PHP 7.x.
    // Ce bloc ne s'exécute que si Monolog 3 est présent (cf. class_exists ci-dessus).
    $record = new \Monolog\LogRecord(
        new \Monolog\DateTimeImmutable(true),
        'test',
        \Monolog\Level::Warning,
        'warn',
        ['k' => 'v'],
        ['request_id' => 'xyz'],
    );

    $out = RecordNormalizer::toArray($record, 'svc', 'testing');

    expect($out['level'])->toBe('warning')
        ->and($out['message'])->toBe('warn')
        ->and($out['request_id'])->toBe('xyz')
        ->and($out['context_k'])->toBe('v')
        ->and($out)->not->toHaveKey('context');
});

it('promeut user_id et user_email de extra au premier niveau (comme request_id)', function () {
    $record = [
        'message' => 'échec sync stock',
        'level' => 400,
        'level_name' => 'ERROR',
        'datetime' => new DateTimeImmutable(),
        // Posés par ContextProcessor dans extra.
        'extra' => ['request_id' => 'req-1', 'user_id' => 42, 'user_email' => 'client@example.com'],
        'context' => ['order_id' => 7],
    ];

    $out = RecordNormalizer::toArray($record, 'lemonpie', 'production');

    expect($out['user_id'])->toBe(42)
        ->and($out['user_email'])->toBe('client@example.com')
        ->and($out['request_id'])->toBe('req-1')
        // context métier toujours aplati sous context_*
        ->and($out['context_order_id'])->toBe(7)
        // pas de doublon extra_user_id / extra_user_email / extra_request_id
        ->and($out)->not->toHaveKey('extra_user_id')
        ->and($out)->not->toHaveKey('extra_user_email')
        ->and($out)->not->toHaveKey('extra_request_id');
});

it('aplatit les sous-tableaux de context en une seule colonne JSON string (schéma stable)', function () {
    $record = [
        'message' => 'm',
        'level' => 200,
        'level_name' => 'INFO',
        'datetime' => new DateTimeImmutable(),
        'context' => [
            'account_id' => 42,                    // scalaire → colonne typée
            'roles' => ['admin', 'editor'],        // tableau → une seule colonne texte JSON
        ],
        'extra' => [],
    ];

    $out = RecordNormalizer::toArray($record, 'svc', 'testing');

    expect($out['context_account_id'])->toBe(42)
        ->and($out['context_roles'])->toBe('["admin","editor"]')
        ->and($out)->not->toHaveKey('context_roles_0'); // pas d'explosion de colonnes
});

it('encode une liste indexée pure de context en une seule colonne JSON (pas de context_0, context_1, ...)', function () {
    $record = [
        'message' => 'erreur avec stack trace',
        'level' => 400,
        'level_name' => 'ERROR',
        'datetime' => new DateTimeImmutable(),
        'context' => ['file.php:10', 'file.php:20', 'file.php:30'],
        'extra' => [],
    ];

    $out = RecordNormalizer::toArray($record, 'svc', 'testing');

    expect($out['context'])->toBe('["file.php:10","file.php:20","file.php:30"]')
        ->and($out)->not->toHaveKey('context_0')
        ->and($out)->not->toHaveKey('context_1');
});

it('promeut user_id et user_email écrits manuellement dans context (pas seulement extra)', function () {
    $record = [
        'message' => 'log manuel',
        'level' => 200,
        'level_name' => 'INFO',
        'datetime' => new DateTimeImmutable(),
        'context' => ['user_id' => 99, 'user_email' => 'manual@example.com', 'foo' => 'bar'],
        'extra' => [],
    ];

    $out = RecordNormalizer::toArray($record, 'svc', 'testing');

    expect($out['user_id'])->toBe(99)
        ->and($out['user_email'])->toBe('manual@example.com')
        ->and($out['context_foo'])->toBe('bar')
        ->and($out)->not->toHaveKey('context_user_id')
        ->and($out)->not->toHaveKey('context_user_email');
});

it('rend le payload JSON-sérialisable même avec des valeurs exotiques', function () {
    $record = [
        'message' => 'm',
        'level' => 200,
        'level_name' => 'INFO',
        'datetime' => new DateTimeImmutable(),
        'context' => ['res' => fopen('php://memory', 'r')],
        'extra' => [],
    ];

    $out = RecordNormalizer::toArray($record, 'svc', 'testing');

    expect(json_encode($out))->toBeString()
        ->and($out['context_res'])->toBeString();
});
