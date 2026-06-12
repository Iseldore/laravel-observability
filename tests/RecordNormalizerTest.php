<?php

use Gysc\Observability\Logging\RecordNormalizer;

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
        ->and($out['context'])->toBe(['foo' => 'bar'])
        ->and($out['_timestamp'])->toBeInt();
});

it('normalise un record Monolog 3 (LogRecord) si disponible', function () {
    if (! class_exists(\Monolog\LogRecord::class)) {
        $this->markTestSkipped('Monolog 2 : LogRecord absent');
    }

    $record = new \Monolog\LogRecord(
        datetime: new \Monolog\DateTimeImmutable(true),
        channel: 'test',
        level: \Monolog\Level::Warning,
        message: 'warn',
        context: ['k' => 'v'],
        extra: ['request_id' => 'xyz'],
    );

    $out = RecordNormalizer::toArray($record, 'svc', 'testing');

    expect($out['level'])->toBe('warning')
        ->and($out['message'])->toBe('warn')
        ->and($out['request_id'])->toBe('xyz')
        ->and($out['context'])->toBe(['k' => 'v']);
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
        ->and($out['context']['res'])->toBeString();
});
