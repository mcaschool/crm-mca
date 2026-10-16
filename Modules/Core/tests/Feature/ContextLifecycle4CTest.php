<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Modules\Core\Exceptions\MissingInstitutionContextException;
use Modules\Core\Http\Middleware\ResolveInstitutionFromUser;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Core\Tests\Support\ContextLifecycleJob;
use Modules\Crm\Models\Contact;
use Modules\Institutions\Models\Institution;

it('4C restores nested contexts and explicit global mode after exceptions', function () {
    $context = app(CurrentInstitution::class);
    expect($context->isResolved())->toBeFalse();
    $context->runGlobally(function () use ($context) {
        expect(fn () => $context->runFor(11, function () use ($context) {
            expect($context->id())->toBe(11)->and($context->isGlobal())->toBeFalse();
            $context->runFor(22, function () use ($context) {
                expect($context->id())->toBe(22);
                throw new RuntimeException('nested failure');
            });
        }))->toThrow(RuntimeException::class, 'nested failure');
        expect($context->isGlobal())->toBeTrue()->and($context->id())->toBeNull();
    });
    expect($context->isResolved())->toBeFalse()->and($context->isGlobal())->toBeFalse();
    expect(fn () => Contact::query()->count())->toThrow(MissingInstitutionContextException::class);
});

it('4C restores HTTP context after success and exception without leaking to anonymous requests', function () {
    $a = Institution::factory()->create();
    $b = Institution::factory()->create();
    foreach ([$a, $b] as $institution) {
        $request = Request::create('/');
        $request->setLaravelSession(app('session')->driver());
        $request->setUserResolver(fn () => new \App\Models\User(['institution_id' => $institution->id]));
        app(ResolveInstitutionFromUser::class)->handle($request, function () use ($institution) {
            expect(app(CurrentInstitution::class)->id())->toBe($institution->id);

            return response('ok');
        });
        expect(app(CurrentInstitution::class)->isResolved())->toBeFalse();
        expect(fn () => app(ResolveInstitutionFromUser::class)->handle($request, function () {
            throw new RuntimeException('request failed');
        }))->toThrow(RuntimeException::class);
        expect(app(CurrentInstitution::class)->isResolved())->toBeFalse();
    }
    $anonymous = Request::create('/');
    app(ResolveInstitutionFromUser::class)->handle($anonymous, function () {
        expect(fn () => Contact::query()->count())->toThrow(MissingInstitutionContextException::class);

        return response('ok');
    });
});

it('4C synchronous job exception restores the enclosing institution', function () {
    $a = Institution::factory()->create();
    $b = Institution::factory()->create();
    $current = app(CurrentInstitution::class);
    $current->set($b->id);
    expect(fn () => dispatch_sync(new ContextLifecycleJob($a->id, false, true)))
        ->toThrow(RuntimeException::class, 'job failure');
    expect($current)->toBe(app(CurrentInstitution::class))
        ->and($current->id())->toBe($b->id)->and($current->isGlobal())->toBeFalse();
});

it('4C persistent worker processes two institutions and a retry with no ambient tenant after each job', function () {
    config(['queue.default' => 'database']);
    $a = Institution::factory()->create();
    $b = Institution::factory()->create();
    app(CurrentInstitution::class)->runFor($a->id, fn () => Contact::factory()->count(2)->create());
    app(CurrentInstitution::class)->runFor($b->id, fn () => Contact::factory()->count(3)->create());
    ContextLifecycleJob::$observations = [];
    $after = [];
    Queue::after(function () use (&$after) {
        $after[] = [app(CurrentInstitution::class)->id(), app(CurrentInstitution::class)->isGlobal()];
    });
    dispatch((new ContextLifecycleJob($a->id, true))->onQueue('context-4c'));
    dispatch((new ContextLifecycleJob($b->id))->onQueue('context-4c'));
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'context-4c', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 2, '--memory' => 0]);
    expect(ContextLifecycleJob::$observations)->toHaveCount(3);
    foreach (ContextLifecycleJob::$observations as [$id, $count]) {
        expect($count)->toBe($id === $a->id ? 2 : 3);
    }
    expect(array_column(ContextLifecycleJob::$observations, 0))->toContain($a->id, $b->id)
        ->and(array_column(ContextLifecycleJob::$observations, 2))->toContain(2)
        ->and($after)->toBe([[null, false], [null, false], [null, false]])
        ->and(app(CurrentInstitution::class)->isResolved())->toBeFalse();
});
