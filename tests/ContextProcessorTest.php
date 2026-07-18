<?php

use Iseldore\Observability\Logging\ContextProcessor;

/**
 * Construit un record Monolog 2 (array) minimal pour les tests.
 */
function makeRecord(array $extra = []): array
{
    return [
        'message' => 'm',
        'level' => 200,
        'level_name' => 'INFO',
        'datetime' => new DateTimeImmutable(),
        'context' => [],
        'extra' => $extra,
    ];
}

it('injecte le contexte du résolveur dans extra (Monolog 2)', function () {
    $processor = new ContextProcessor(fn () => ['user_id' => 42, 'tenant_id' => 7]);

    $out = $processor(makeRecord());

    expect($out['extra']['user_id'])->toBe(42)
        ->and($out['extra']['tenant_id'])->toBe(7);
});

it('ne surcharge jamais une clé extra déjà posée par l\'appelant', function () {
    $processor = new ContextProcessor(fn () => ['user_id' => 42]);

    // request_id déjà posé par RequestIdProcessor en amont ; user_id déjà présent.
    $out = $processor(makeRecord(['request_id' => 'req-1', 'user_id' => 99]));

    expect($out['extra']['user_id'])->toBe(99)          // valeur d'origine préservée
        ->and($out['extra']['request_id'])->toBe('req-1');
});

it('ne lève jamais et n\'ajoute rien si le résolveur échoue', function () {
    $processor = new ContextProcessor(function () {
        throw new RuntimeException('boom');
    });

    $out = $processor(makeRecord(['request_id' => 'req-1']));

    expect($out['extra'])->toBe(['request_id' => 'req-1']);
});

it('ignore les valeurs non scalaires renvoyées par le résolveur', function () {
    $processor = new ContextProcessor(fn () => [
        'user_id' => 42,
        'roles' => ['admin'],            // tableau → écarté (schéma OpenObserve)
        'model' => new stdClass(),       // objet → écarté
    ]);

    $out = $processor(makeRecord());

    expect($out['extra'])->toHaveKey('user_id')
        ->and($out['extra'])->not->toHaveKey('roles')
        ->and($out['extra'])->not->toHaveKey('model');
});

it('laisse le record intact si le résolveur ne renvoie rien', function () {
    $processor = new ContextProcessor(fn () => []);

    $out = $processor(makeRecord(['request_id' => 'req-1']));

    expect($out['extra'])->toBe(['request_id' => 'req-1']);
});

it('injecte le contexte dans un LogRecord Monolog 3 si disponible', function () {
    if (! class_exists(\Monolog\LogRecord::class)) {
        $this->markTestSkipped('Monolog 2 : LogRecord absent');
    }

    $processor = new ContextProcessor(fn () => ['user_id' => 42]);

    $record = new \Monolog\LogRecord(
        new \Monolog\DateTimeImmutable(true),
        'test',
        \Monolog\Level::Info,
        'm',
        [],
        ['request_id' => 'req-1'],
    );

    $out = $processor($record);

    expect($out->extra['user_id'])->toBe(42)
        ->and($out->extra['request_id'])->toBe('req-1');
});
