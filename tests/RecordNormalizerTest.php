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

it('aplatit les sous-tableaux de context en une seule colonne JSON string (schéma stable)', function () {
    $record = [
        'message' => 'm',
        'level' => 200,
        'level_name' => 'INFO',
        'datetime' => new DateTimeImmutable(),
        'context' => [
            'user_id' => 42,                       // scalaire → colonne typée
            'roles' => ['admin', 'editor'],        // tableau → une seule colonne texte JSON
        ],
        'extra' => [],
    ];

    $out = RecordNormalizer::toArray($record, 'svc', 'testing');

    expect($out['context_user_id'])->toBe(42)
        ->and($out['context_roles'])->toBe('["admin","editor"]')
        ->and($out)->not->toHaveKey('context_roles_0'); // pas d'explosion de colonnes
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
