<?php

use Iseldore\Observability\Jobs\SendLogsToOpenObserve;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

/**
 * auth_* : request_id (rattachement au http_request), contexte applicatif et activation par
 * événement via auth_log.events (specs PKG-10).
 */
function authRecords(): \Illuminate\Support\Collection
{
    return collect(Queue::pushed(SendLogsToOpenObserve::class))->flatMap(function ($job) {
        return $job->batch;
    });
}

function authUser(): GenericUser
{
    return new GenericUser(['id' => 5, 'email' => 'jeanne@example.test']);
}

it('pose le même request_id sur auth_login et sur le http_request de la requête', function () {
    Queue::fake();
    Route::post('/api/login-test', function () {
        event(new Login('web', authUser(), false));

        return response('ok');
    });

    $this->withHeaders(['X-Request-Id' => 'req-login-1'])->post('/api/login-test')->assertOk();

    $records = authRecords();

    expect($records->firstWhere('message', 'auth_login')['request_id'])->toBe('req-login-1')
        ->and($records->firstWhere('message', 'http_request')['request_id'])->toBe('req-login-1');
});

it('garde user_id et user_email de l’événement, sans les écraser par le résolveur', function () {
    Queue::fake();
    config()->set('observability.context.resolver', function () {
        return ['user_id' => 999, 'tenant_id' => 'acme'];
    });

    app('events')->dispatch(new Login('web', authUser(), false));

    $record = authRecords()->firstWhere('message', 'auth_login');

    expect($record['user_id'])->toBe(5)
        ->and($record['user_email'])->toBe('jeanne@example.test')
        ->and($record['tenant_id'])->toBe('acme')
        ->and($record['guard'])->toBe('web');
});

it('n’émet pas auth_login quand login est retiré de auth_log.events', function () {
    Queue::fake();
    config()->set('observability.auth_log.events', ['logout', 'failed']);

    app('events')->dispatch(new Login('web', authUser(), false));
    app('events')->dispatch(new Logout('web', authUser()));
    app('events')->dispatch(new Failed('web', null, ['email' => 'x@example.test', 'password' => 'secret']));

    $records = authRecords();

    expect($records->pluck('message')->all())->toBe(['auth_logout', 'auth_failed'])
        ->and($records->firstWhere('message', 'auth_failed')['attempted_email'])->toBe('x@example.test')
        ->and(json_encode($records->all()))->not->toContain('secret');
});

it('loggue les trois événements par défaut', function () {
    Queue::fake();

    app('events')->dispatch(new Login('web', authUser(), false));
    app('events')->dispatch(new Logout('web', authUser()));
    app('events')->dispatch(new Failed('web', null, ['email' => 'x@example.test']));

    expect(authRecords()->pluck('message')->all())->toBe(['auth_login', 'auth_logout', 'auth_failed']);
});
