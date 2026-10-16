<?php

declare(strict_types=1);
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Contracts\ActorContextFactory;
use Modules\CollaborationCore\Contracts\DeviceRegistry;
use Modules\CollaborationCore\Contracts\NotificationDispatcher;
use Modules\CollaborationCore\Contracts\PresenceService;
use Modules\CollaborationCore\Contracts\UserDirectory;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\InstitutionContext;
use Modules\CollaborationCore\Data\NotificationAudience;
use Modules\CollaborationCore\Data\NotificationIntent;
use Modules\CollaborationCore\Enums\ManualPresence;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\CollaborationCore\Integration\CallPresenceAuthorizer;
use Modules\CollaborationCore\Services\ApplicationAccess;
use Modules\CollaborationCore\Services\InstitutionExecution;
use Modules\CollaborationCore\Services\NotificationPreferences;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Identity\Enums\UserRole;
use Modules\Institutions\Models\Institution;

function coreUser(?Institution $institution = null, array $attributes = []): User
{
    return User::factory()->create(['institution_id' => ($institution ?? Institution::factory()->create())->id, 'role' => UserRole::Member] + $attributes);
}
function coreActor(User $user, ?string $sessionId = null): ActorContext
{
    $request = Request::create('/api/v1/collaboration/me');
    $session = new Store('test', new ArraySessionHandler(120), $sessionId);
    $session->start();
    \Modules\CollaborationCore\Services\MfaSessionProof::record($session, $user);
    $request->setLaravelSession($session);
    $request->setUserResolver(fn () => $user);

    return app(ActorContextFactory::class)->fromAuthenticatedRequest($request);
}
beforeEach(function (): void {
    $this->withoutVite();
});

it('allows neutral active institutional users without CRM privileges', function () {
    $u = coreUser();
    expect($u->canAccessApplication('collaboration'))->toBeTrue()
        ->and($u->canWorkCrm())->toBeFalse()->and($u->crmPersona())->toBe('none')
        ->and($u->canSendEmail())->toBeFalse()->and($u->canManageCatalog())->toBeFalse()
        ->and($u->canAccessApplication('hr'))->toBeFalse()->and($u->canAccessApplication('sigac'))->toBeFalse();
    $this->actingAs($u)->withSession(['auth.mfa_user_id' => $u->id, 'auth.mfa_fingerprint' => \Modules\CollaborationCore\Services\MfaSessionProof::fingerprint($u)])->getJson('/api/v1/collaboration/me')->assertOk()->assertJsonPath('data.id', $u->id);
    $this->get('/dashboard')->assertForbidden();
    $this->get(route('profile.edit'))->assertOk();
});
it('preserves legacy roles and separates identity administration', function () {
    $i = Institution::factory()->create();
    foreach (['admin', 'marketing', 'admissions'] as $role) {
        $u = User::factory()->create(['institution_id' => $i->id, 'role' => $role]);
        expect($u->canWorkCrm())->toBeTrue();
    }
    $identity = coreUser($i, ['is_identity_admin' => true]);
    expect($identity->canManageUsers())->toBeTrue()->and($identity->canManageCrm())->toBeFalse();
    $crm = User::factory()->create(['institution_id' => $i->id, 'is_identity_admin' => false]);
    expect($crm->canManageUsers())->toBeFalse()->and($crm->canManageCrm())->toBeTrue();
});
it('requires verified MFA and rejects guests even with JSON actor fields', function () {
    $this->getJson('/api/v1/collaboration/me')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
    $u = coreUser();
    $this->actingAs($u)->getJson('/api/v1/collaboration/me?user_id=1&institution_id=1')->assertForbidden()->assertJsonPath('error.code', 'two_factor_required');
});
it('derives actor identity from the server and hides secrets', function () {
    $u = coreUser();
    $r = $this->actingAs($u)->withSession(['auth.mfa_user_id' => $u->id, 'auth.mfa_fingerprint' => \Modules\CollaborationCore\Services\MfaSessionProof::fingerprint($u)])->getJson('/api/v1/collaboration/me?user_id=9999&institution_id=9999&device_id=spoof');
    $r->assertOk()->assertJsonPath('data.id', $u->id)->assertJsonPath('data.institution_id', $u->institution_id);
    expect($r->json('data.device_id'))->not->toBe('spoof');
    $devices = $this->getJson('/api/v1/collaboration/devices')->assertOk()->getContent();
    foreach (['session_hash', 'auth_fingerprint', 'password', 'two_factor', 'remember_token', 'email'] as $secret) {
        expect($devices)->not->toContain($secret);
    }
    expect(array_keys($u->toArray()))->not->toContain('two_factor_secret', 'two_factor_recovery_codes');
});
it('rejects cross institution user and presence lookups', function () {
    $u = coreUser();
    $other = coreUser();
    $a = coreActor($u);
    expect(fn () => app(UserDirectory::class)->resolve($a, $other->id))->toThrow(ContractException::class, 'not_found');
    expect(fn () => app(PresenceService::class)->snapshot($a, [$other->id]))->toThrow(ContractException::class, 'not_found');
});
it('binds devices to sessions and supports multiple sessions', function () {
    $u = coreUser();
    $a = coreActor($u);
    $b = coreActor($u);
    $registry = app(DeviceRegistry::class);
    expect($a->deviceId)->not->toBe($b->deviceId)->and($registry->listForCurrentUser($a))->toHaveCount(2);
    $forged = new ActorContext($a->institutionId, $a->userId, $b->deviceId, $a->sessionId, $a->requestId, $a->authenticationLevel);
    expect(fn () => $registry->assertSessionBinding($forged))->toThrow(ContractException::class, 'forbidden');
    $registry->revoke($a, $b->deviceId);
    expect(fn () => $registry->assertSessionBinding($b))->toThrow(ContractException::class, 'device_revoked');
    $registry->assertSessionBinding($a);
});
it('never allows revoking another users device', function () {
    $u = coreUser();
    $v = coreUser($u->institution);
    $a = coreActor($u);
    $b = coreActor($v);
    expect(fn () => app(DeviceRegistry::class)->revoke($a, $b->deviceId))->toThrow(ContractException::class, 'not_found');
});
it('registers once for a session and does not let a revoked session enroll again', function () {
    $u = coreUser();
    $id = str_repeat('a', 40);
    $a = coreActor($u, $id);
    $b = coreActor($u, $id);
    expect($a->deviceId)->toBe($b->deviceId)->and(DB::table('collab_devices')->count())->toBe(1);
    app(DeviceRegistry::class)->revoke($a, $a->deviceId);
    expect(fn () => coreActor($u, $id))->toThrow(ContractException::class, 'device_revoked');
});
it('aggregates heartbeats across devices without treating login as presence', function () {
    $u = coreUser();
    $a = coreActor($u);
    $b = coreActor($u);
    $p = app(PresenceService::class);
    expect($p->snapshot($a, [$u->id])[0]->online)->toBeFalse();
    $p->heartbeat($a);
    $this->travel(60)->seconds();
    $p->heartbeat($b);
    $this->travel(31)->seconds();
    expect($p->snapshot($b, [$u->id])[0]->online)->toBeTrue();
    app(DeviceRegistry::class)->revoke($b, $a->deviceId);
    expect($p->snapshot($b, [$u->id])[0]->online)->toBeTrue();
    $this->travel(60)->seconds();
    expect($p->snapshot($b, [$u->id])[0]->online)->toBeFalse();
});
it('preserves manual status and distinct concurrent call presence', function () {
    app()->bind(CallPresenceAuthorizer::class, fn () => new class implements CallPresenceAuthorizer
    {
        public function authorize(InstitutionContext $context, int $userId, string $callId): void {}
    });
    $u = coreUser();
    $a = coreActor($u);
    $p = app(PresenceService::class);
    $ctx = new InstitutionContext($a->institutionId);
    $p->heartbeat($a);
    $p->setManualStatus($a, ManualPresence::Busy);
    $p->setInCall($ctx, $u->id, 'call_a', 60);
    $p->setInCall($ctx, $u->id, 'call_b', 120);
    $p->clearInCall($ctx, $u->id, 'call_a');
    $s = $p->snapshot($a, [$u->id])[0];
    expect($s->callId)->toBe('call_b')->and($s->manualStatus)->toBe(ManualPresence::Busy);
    $this->travel(121)->seconds();
    $s = $p->snapshot($a, [$u->id])[0];
    expect($s->callId)->toBeNull()->and($s->manualStatus)->toBe(ManualPresence::Busy)->and($s->online)->toBeFalse();
});
it('fails closed without the TeamCalls membership adapter', function () {
    $u = coreUser();
    expect(fn () => app(PresenceService::class)->setInCall(new InstitutionContext($u->institution_id), $u->id, 'call_a', 60))->toThrow(ContractException::class, 'forbidden');
});
it('applies revocation immediately and requires a new session after regrant', function () {
    $u = coreUser();
    $admin = User::factory()->create(['institution_id' => $u->institution_id]);
    $a = coreActor($u);
    app(ApplicationAccess::class)->set($admin, $u, 'collaboration', false);
    expect(fn () => app(DeviceRegistry::class)->assertSessionBinding($a))->toThrow(ContractException::class, 'forbidden');
    app(ApplicationAccess::class)->set($admin, $u, 'collaboration', true);
    expect(fn () => app(DeviceRegistry::class)->assertSessionBinding($a))->toThrow(ContractException::class, 'device_revoked');
});
it('revokes devices on account deactivation and credential changes', function () {
    $u = coreUser();
    $a = coreActor($u);
    $u->update(['status' => 'inactive']);
    $u->update(['status' => 'active']);
    expect(fn () => app(DeviceRegistry::class)->assertSessionBinding($a))->toThrow(ContractException::class, 'device_revoked');
    $b = coreActor($u);
    $u->update(['password' => 'new-long-secret']);
    expect(fn () => app(DeviceRegistry::class)->assertSessionBinding($b))->toThrow(ContractException::class, 'device_revoked');
});
it('rejects disabled institutions and mobile enrollment without mobile authentication', function () {
    $u = coreUser();
    $a = coreActor($u);
    $u->institution->forceFill(['collaboration_enabled' => false])->save();
    expect(fn () => app(DeviceRegistry::class)->assertSessionBinding($a))->toThrow(ContractException::class, 'forbidden');
});
it('restores institutional context when an internal operation fails', function () {
    $i = Institution::factory()->create();
    $ctx = app(CurrentInstitution::class);
    $ctx->set(999);
    try {
        app(InstitutionExecution::class)->run(new InstitutionContext($i->id), fn () => throw new RuntimeException('test'));
    } catch (RuntimeException) {
    }
    expect($ctx->id())->toBe(999);
});
it('persists notification intent once and detects conflicting replay', function () {
    $u = coreUser();
    coreActor($u);
    $ctx = new InstitutionContext($u->institution_id);
    $intent = new NotificationIntent('n_a', 'call.invited', 'call_a', 'e_a', 'dedupe_a', now()->addMinutes(5)->toDateTimeImmutable());
    $audience = new NotificationAudience($u->institution_id, [$u->id]);
    $d = app(NotificationDispatcher::class);
    expect($d->enqueue($ctx, $intent, $audience))->toBe('n_a')->and($d->enqueue($ctx, $intent, $audience))->toBe('n_a');
    expect(DB::table('collab_notification_intents')->count())->toBe(1)->and(DB::table('collab_notification_intents')->value('status'))->toBe('pending');
    $conflict = new NotificationIntent('n_b', 'call.invited', 'call_b', 'e_a', 'dedupe_a', $intent->expiresAt);
    expect(fn () => $d->enqueue($ctx, $conflict, $audience))->toThrow(ContractException::class, 'idempotency_conflict');
});
it('filters notification preferences and rejects foreign recipients atomically', function () {
    $u = coreUser();
    $a = coreActor($u);
    $v = coreUser();
    app(NotificationPreferences::class)->set($a, false);
    $ctx = new InstitutionContext($u->institution_id);
    $intent = new NotificationIntent('n_a', 'call.invited', 'call_a', 'e_a', 'dedupe_a', now()->addMinutes(5)->toDateTimeImmutable());
    $d = app(NotificationDispatcher::class);
    expect(fn () => $d->enqueue($ctx, $intent, new NotificationAudience($ctx->institutionId, [$u->id, $v->id])))->toThrow(ContractException::class, 'not_found');
    expect(DB::table('collab_notification_intents')->count())->toBe(0);
    $d->enqueue($ctx, $intent, new NotificationAudience($ctx->institutionId, [$u->id]));
    expect(DB::table('collab_notification_intents')->value('status'))->toBe('suppressed');
});
it('invalidates MFA proof even when credentials change before first device enrollment', function () {
    $u = coreUser();
    $proof = \Modules\CollaborationCore\Services\MfaSessionProof::fingerprint($u);
    $u->update(['password' => 'another-secret-password']);
    $this->actingAs($u)->withSession(['auth.mfa_user_id' => $u->id, 'auth.mfa_fingerprint' => $proof])
        ->getJson('/api/v1/collaboration/me')->assertForbidden()->assertJsonPath('error.code', 'two_factor_required');
});
it('revokes the linked database session and rejects externally deleted sessions', function () {
    $u = coreUser();
    $id = str_repeat('b', 40);
    $other = str_repeat('c', 40);
    config(['session.driver' => 'database']);
    foreach ([$id, $other] as $s) {
        DB::table('sessions')->insert(['id' => $s, 'user_id' => $u->id, 'payload' => '', 'last_activity' => now()->timestamp]);
    }
    $a = coreActor($u, $id);
    $b = coreActor($u, $other);
    app(DeviceRegistry::class)->revoke($a, $b->deviceId);
    expect(DB::table('sessions')->where('id', $other)->exists())->toBeFalse();
    DB::table('sessions')->where('id', $id)->delete();
    expect(fn () => app(DeviceRegistry::class)->assertSessionBinding($a))->toThrow(ContractException::class, 'session_revoked');
});
it('rejects expired sessions and never treats presence as authorization', function () {
    $u = coreUser();
    $a = coreActor($u);
    app(PresenceService::class)->heartbeat($a);
    DB::table('collab_devices')->where('id', $a->deviceId)->update(['expires_at' => now()->subSecond()]);
    expect(fn () => app(DeviceRegistry::class)->assertSessionBinding($a))->toThrow(ContractException::class, 'session_revoked');
});
it('excludes invalid notification devices and records expired intent without delivery', function () {
    $u = coreUser();
    $a = coreActor($u);
    $b = coreActor($u);
    app(DeviceRegistry::class)->revoke($a, $b->deviceId);
    $ctx = new InstitutionContext($u->institution_id);
    $intent = new NotificationIntent('n_expired', 'call.invited', 'call_a', 'e_a', 'dedupe_expired', now()->subSecond()->toDateTimeImmutable());
    app(NotificationDispatcher::class)->enqueue($ctx, $intent, new NotificationAudience($u->institution_id, [$u->id]));
    $row = DB::table('collab_notification_intents')->first();
    expect($row->status)->toBe('expired')->and(json_decode($row->device_ids, true))->toBe([$a->deviceId]);
});
it('denies mobile registration until a complete MFA mobile flow is available', function () {
    $u = coreUser();
    $a = coreActor($u);
    $s = new \Modules\CollaborationCore\Data\AuthenticatedSessionContext($a->institutionId, $a->userId, $a->sessionId, $a->requestId, $a->authenticationLevel);
    expect(fn () => app(DeviceRegistry::class)->register($s, new \Modules\CollaborationCore\Data\DeviceRegistration(\Modules\CollaborationCore\Enums\DevicePlatform::Android, 'Phone')))->toThrow(ContractException::class, 'forbidden');
});
it('clears request tenant context on success and failure', function () {
    $u = coreUser();
    $this->actingAs($u)->withSession(['auth.mfa_user_id' => $u->id, 'auth.mfa_fingerprint' => \Modules\CollaborationCore\Services\MfaSessionProof::fingerprint($u)]);
    $this->getJson('/api/v1/collaboration/me')->assertOk();
    expect(app(CurrentInstitution::class)->isResolved())->toBeFalse();
    $this->getJson('/api/v1/collaboration/users/999999')->assertNotFound();
    expect(app(CurrentInstitution::class)->isResolved())->toBeFalse();
});
it('blocks neutral CRM Livewire requests and allows independent identity administration', function () {
    $u = coreUser(null, ['is_identity_admin' => true]);
    $this->actingAs($u)->postJson('/livewire/update', ['components' => [['snapshot' => json_encode(['memo' => ['name' => 'crm.leads.index']])]]])->assertForbidden();
    $this->get(route('users.index'))->assertOk();
    $this->get(route('advisors.index'))->assertForbidden();
});
it('requires identity administration to grant applications and keeps grants independent', function () {
    $u = coreUser();
    $admin = coreUser($u->institution, ['is_identity_admin' => true]);
    $other = coreUser($u->institution);
    expect(fn () => app(ApplicationAccess::class)->set($u, $other, 'crm', true))->toThrow(ContractException::class, 'forbidden');
    app(ApplicationAccess::class)->set($admin, $u, 'hr', true);
    expect($u->canAccessApplication('hr'))->toBeTrue()->and($u->canAccessApplication('sigac'))->toBeFalse()->and($u->canWorkCrm())->toBeFalse();
    app(ApplicationAccess::class)->set($admin, $u, 'crm', true);
    expect($u->canAccessApplication('crm'))->toBeTrue()->and($u->canWorkCrm())->toBeFalse()->and($u->crmPersona())->toBe('none');
    $u->update(['role' => UserRole::Admissions]);
    expect($u->canWorkCrm())->toBeTrue();
    app(ApplicationAccess::class)->set($admin, $u, 'crm', false);
    expect($u->canWorkCrm())->toBeFalse()->and($u->canAccessApplication('collaboration'))->toBeTrue();
});
it('prunes only expired data for the selected institution', function () {
    $u = coreUser();
    $v = coreUser();
    $a = coreActor($u);
    $b = coreActor($v);
    app(PresenceService::class)->heartbeat($a);
    app(PresenceService::class)->heartbeat($b);
    $this->travel(91)->seconds();
    $this->artisan('collaboration:prune', ['--institution' => $u->institution_id])->assertExitCode(0);
    expect(DB::table('collab_presence_devices')->where('institution_id', $u->institution_id)->count())->toBe(0)
        ->and(DB::table('collab_presence_devices')->where('institution_id', $v->institution_id)->count())->toBe(1)
        ->and(DB::table('collab_devices')->count())->toBe(2);
});
it('manages institutional administrators independently and prevents self revocation', function () {
    $u = coreUser();
    $admin = coreUser($u->institution, ['is_identity_admin' => true]);
    app(ApplicationAccess::class)->setIdentityAdministrator($admin, $u, true);
    expect($u->fresh()->canManageUsers())->toBeTrue()->and($u->fresh()->canWorkCrm())->toBeFalse();
    app(ApplicationAccess::class)->setIdentityAdministrator($admin, $u, false);
    expect($u->fresh()->canManageUsers())->toBeFalse();
    expect(fn () => app(ApplicationAccess::class)->setIdentityAdministrator($admin, $admin, false))->toThrow(ContractException::class, 'forbidden');
});
it('denies direct CRM policy actions for neutral or revoked actors', function () {
    $u = coreUser();
    $lead = new \Modules\Crm\Models\Lead;
    expect($u->can('create', \Modules\Crm\Models\Lead::class))->toBeFalse()
        ->and($u->can('update', $lead))->toBeFalse()->and($u->can('delete', $lead))->toBeFalse();
    $admin = User::factory()->create(['institution_id' => $u->institution_id]);
    app(ApplicationAccess::class)->set($admin, $admin, 'crm', false);
    expect($admin->can('create', \Modules\Crm\Models\Lead::class))->toBeFalse()
        ->and($admin->can('update', $lead))->toBeFalse();
});
it('restores enclosing middleware context without leaking a new request context', function () {
    $u = coreUser();
    $request = Request::create('/dashboard');
    $session = new Store('test', new ArraySessionHandler(120));
    $session->start();
    $request->setLaravelSession($session);
    $request->setUserResolver(fn () => $u);
    $ctx = app(CurrentInstitution::class);
    $ctx->forget();
    $middleware = app(\Modules\Core\Http\Middleware\ResolveInstitutionFromUser::class);
    $middleware->handle($request, function () use ($u, $ctx) {
        expect($ctx->id())->toBe((int) $u->institution_id);

        return response('ok');
    });
    expect($ctx->isResolved())->toBeFalse();
    $ctx->set(987);
    try {
        $middleware->handle($request, fn () => throw new RuntimeException('test'));
    } catch (RuntimeException) {
    }
    expect($ctx->id())->toBe(987);
});
it('provisions seed administrator explicitly without restoring revoked access', function () {
    $this->seed(\Database\Seeders\DatabaseSeeder::class);
    $admin = User::query()->where('email', 'admin@mcaschool.us')->firstOrFail();
    expect($admin->canWorkCrm())->toBeTrue()->and($admin->canManageUsers())->toBeTrue();
    app(ApplicationAccess::class)->set($admin, $admin, 'crm', false);
    $this->seed(\Database\Seeders\DatabaseSeeder::class);
    expect($admin->fresh()->canWorkCrm())->toBeFalse();
});
it('enforces CSRF for cookie authenticated collaboration mutations', function () {
    $u = coreUser();
    $this->actingAs($u)->withSession(['auth.mfa_user_id' => $u->id, 'auth.mfa_fingerprint' => \Modules\CollaborationCore\Services\MfaSessionProof::fingerprint($u)]);
    $this->app->instance('env', 'local');
    $this->postJson('/api/v1/collaboration/presence/heartbeat')->assertForbidden()->assertJsonPath('error.code', 'forbidden');
});
it('canonicalizes notification dates and preserves opaque identifier case', function () {
    $u = coreUser();
    coreActor($u);
    $ctx = new InstitutionContext($u->institution_id);
    $audience = new NotificationAudience($u->institution_id, [$u->id]);
    $expires = now()->addMinutes(5)->toDateTimeImmutable();
    $dispatcher = app(NotificationDispatcher::class);
    $a = new NotificationIntent('notice_A', 'call.invited', 'call_a', 'event_a', 'dedupe_A', $expires);
    $dispatcher->enqueue($ctx, $a, $audience);
    $same = new NotificationIntent('notice_A', 'call.invited', 'call_a', 'event_a', 'dedupe_A', $expires->setTimezone(new DateTimeZone('America/New_York')));
    expect($dispatcher->enqueue($ctx, $same, $audience))->toBe('notice_A');
    $b = new NotificationIntent('notice_a', 'call.invited', 'call_a', 'event_a', 'dedupe_a', $expires);
    $dispatcher->enqueue($ctx, $b, $audience);
    expect(DB::table('collab_notification_intents')->where('institution_id', $u->institution_id)->count())->toBe(2);
});
it('does not carry panel permission decisions into a later HTTP request', function () {
    $i = Institution::factory()->create();
    $u = User::factory()->create(['institution_id' => $i->id, 'role' => 'admissions']);
    $this->actingAs($u)->get('/dashboard')->assertOk();
    // Simulates a grant revoked by a different process, outside local invalidation.
    DB::table('collab_application_access')->insert(['institution_id' => $i->id, 'user_id' => $u->id, 'application' => 'crm', 'allowed' => false, 'created_at' => now(), 'updated_at' => now()]);
    $this->get('/dashboard')->assertForbidden();
    expect(app(ApplicationAccess::class)->allows($u, 'collaboration'))->toBeTrue();
});
it('returns stable validation errors and supports notification preferences', function () {
    $u = coreUser();
    $this->actingAs($u)->withSession(['auth.mfa_user_id' => $u->id, 'auth.mfa_fingerprint' => \Modules\CollaborationCore\Services\MfaSessionProof::fingerprint($u)]);
    $this->putJson('/api/v1/collaboration/presence/status', ['status' => 'in_call'])->assertUnprocessable()->assertJsonPath('error.code', 'invalid');
    $this->putJson('/api/v1/collaboration/notification-preferences', ['enabled' => false])->assertOk()->assertJsonPath('data.enabled', false);
});
