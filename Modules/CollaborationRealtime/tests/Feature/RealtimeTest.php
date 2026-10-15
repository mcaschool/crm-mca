<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Contracts\DeviceRegistry;
use Modules\CollaborationCore\Data\InstitutionContext;
use Modules\CollaborationCore\Data\PageRequest;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\CollaborationCore\Services\MfaSessionProof;
use Modules\CollaborationRealtime\Contracts\SyncFeed;
use Modules\CollaborationRealtime\Contracts\TransactionalEventRecorder;
use Modules\CollaborationRealtime\Data\DeliveryAudience;
use Modules\CollaborationRealtime\Services\DeviceChannels;
use Modules\Institutions\Models\Institution;

require_once __DIR__.'/../../../../tests/RealtimeFixtures.php';

beforeEach(function (): void {
    $this->withoutVite();
    $this->policy = realtimePolicies();
    $this->u = User::factory()->create(['institution_id' => Institution::factory()->create()->id, 'role' => 'member']);
    $this->a = realtimeActor($this->u);
    $this->recorder = app(TransactionalEventRecorder::class);
    $this->feed = app(SyncFeed::class);
    $this->append = fn ($e, $audience = null) => $this->recorder->append(new InstitutionContext($this->a->institutionId), $e, $audience ?? new DeliveryAudience($this->a->institutionId, [$this->a->userId]));
});

it('rolls back the event outbox and sequence together', function () {
    DB::beginTransaction();
    ($this->append)(realtimeEvent($this->a));
    expect(DB::table('collab_outbox')->count())->toBe(1);
    DB::rollBack();
    expect(DB::table('collab_events')->count())->toBe(0)->and(DB::table('collab_outbox')->count())->toBe(0)->and(DB::table('collab_streams')->count())->toBe(0);
});
it('deduplicates identical envelopes and rejects changed payloads', function () {
    ($this->append)(realtimeEvent($this->a));
    ($this->append)(realtimeEvent($this->a));
    expect(DB::table('collab_events')->count())->toBe(1)->and(DB::table('collab_outbox')->count())->toBe(1);
    expect(fn () => ($this->append)(realtimeEvent($this->a, data: ['value' => 2])))->toThrow(ContractException::class, 'idempotency_conflict');
});
it('enforces aggregate order and rejects duplicate versions with new identifiers', function () {
    ($this->append)(realtimeEvent($this->a));
    expect(fn () => ($this->append)(realtimeEvent($this->a, 'different')))->toThrow(ContractException::class, 'idempotency_conflict');
    ($this->append)(realtimeEvent($this->a, 'next', 2));
    $page = $this->feed->read($this->a, new PageRequest);
    expect(array_map(fn ($e) => $e->aggregateVersion, $page->events))->toBe([1, 2]);
});
it('pages and resumes on another valid device without event duplication', function () {
    ($this->append)(realtimeEvent($this->a));
    ($this->append)(realtimeEvent($this->a, 'next', 2));
    $p = $this->feed->read($this->a, new PageRequest(limit: 1));
    expect($p->hasMore)->toBeTrue()->and($p->events[0]->id)->toBe('event-1');
    $q = $this->feed->read(realtimeActor($this->u), new PageRequest($p->cursor));
    expect($q->events)->toHaveCount(1)->and($q->events[0]->id)->toBe('next')->and($q->hasMore)->toBeFalse();
    expect($this->feed->read($this->a, new PageRequest($q->cursor))->events)->toBe([]);
});
it('keeps a stable bootstrap cut while new events arrive', function () {
    ($this->append)(realtimeEvent($this->a));
    ($this->append)(realtimeEvent($this->a, 'second', 1, 'resource-2'));
    $p = $this->feed->bootstrap($this->a, new PageRequest(limit: 1));
    ($this->append)(realtimeEvent($this->a, 'third', 2));
    $q = $this->feed->bootstrap($this->a, new PageRequest($p->nextPageCursor, 1));
    expect($p->resources[0]['version'])->toBe('1')->and($q->resources[0]['id'])->toBe('resource-2');
    $replay = $this->feed->read($this->a, new PageRequest($p->cursor));
    expect(array_map(fn ($e) => $e->id, $replay->events))->toBe(['third']);
});
it('rejects manipulated cross user and expired cursors', function () {
    $cursor = $this->feed->read($this->a, new PageRequest)->cursor;
    expect(fn () => $this->feed->read($this->a, new PageRequest(substr($cursor, 0, -4).'aaaa')))->toThrow(ContractException::class, 'invalid');
    $other = User::factory()->create(['institution_id' => $this->a->institutionId]);
    expect(fn () => $this->feed->read(realtimeActor($other), new PageRequest($cursor)))->toThrow(ContractException::class, 'invalid');
    $this->travel(25)->hours();
    // A valid fresh session must still receive cursor-expired rather than a replay.
    expect(fn () => $this->feed->read(realtimeActor($this->u), new PageRequest($cursor)))->toThrow(ContractException::class, 'sync_cursor_expired');
});
it('rejects foreign audiences and context forgery', function () {
    $other = User::factory()->create(['institution_id' => Institution::factory()->create()->id]);
    expect(fn () => ($this->append)(realtimeEvent($this->a), new DeliveryAudience($this->a->institutionId, [$other->id])))->toThrow(ContractException::class, 'not_found');
    expect(fn () => ($this->append)(realtimeEvent($this->a), new DeliveryAudience($other->institution_id, [$other->id])))->toThrow(ContractException::class, 'invalid');
});
it('checks current resource permissions and excludes revoked devices', function () {
    ($this->append)(realtimeEvent($this->a));
    $this->policy->allowed = false;
    expect($this->feed->read($this->a, new PageRequest)->events)->toBe([]);
    $this->policy->allowed = true;
    app(DeviceRegistry::class)->revoke($this->a, $this->a->deviceId);
    expect(fn () => $this->feed->read($this->a, new PageRequest))->toThrow(ContractException::class, 'device_revoked');
    expect(fn () => app(DeviceChannels::class)->name($this->a))->toThrow(ContractException::class, 'device_revoked');
});
it('never replays ephemeral or expired events through historical sync', function () {
    ($this->append)(realtimeEvent($this->a, type: 'test.typing'), new DeliveryAudience($this->a->institutionId, [$this->a->userId], expiresAt: now()->addSeconds(20)->toDateTimeImmutable()));
    ($this->append)(realtimeEvent($this->a, 'expired', 2), new DeliveryAudience($this->a->institutionId, [$this->a->userId], expiresAt: now()->subSecond()->toDateTimeImmutable()));
    expect($this->feed->read($this->a, new PageRequest)->events)->toBe([]);
    expect(fn () => ($this->append)(realtimeEvent($this->a, 'bad-ttl', 3, type: 'test.typing')))->toThrow(ContractException::class, 'invalid');
});
it('rejects secrets and unregistered producers', function () {
    expect(fn () => ($this->append)(realtimeEvent($this->a, data: ['password' => 'secret'])))->toThrow(ContractException::class, 'invalid');
    expect(fn () => ($this->append)(realtimeEvent($this->a, type: 'unknown.event')))->toThrow(LogicException::class);
});
it('preserves the cancellation representation without a second event', function () {
    ($this->append)(realtimeEvent($this->a, type: 'call.ended', data: ['call_id' => 'resource-1', 'status' => 'cancelled']));
    expect($this->feed->read($this->a, new PageRequest)->events[0]->data['status'])->toBe('cancelled');
});
it('rejects excluded devices without excluding the same users other devices', function () {
    ($this->append)(realtimeEvent($this->a), new DeliveryAudience($this->a->institutionId, [$this->a->userId], $this->a->deviceId));
    expect($this->feed->read($this->a, new PageRequest)->events)->toBe([])
        ->and($this->feed->read(realtimeActor($this->u), new PageRequest)->events)->toHaveCount(1);
});
it('authenticates HTTP sync and private channel subscriptions', function () {
    config(['broadcasting.connections.reverb.key' => 'test-key', 'broadcasting.connections.reverb.secret' => 'test-secret', 'broadcasting.connections.reverb.app_id' => 'test']);
    $this->getJson('/api/v1/collaboration/sync')->assertUnauthorized();
    $this->actingAs($this->u)->withSession(['auth.mfa_user_id' => $this->u->id, 'auth.mfa_fingerprint' => MfaSessionProof::fingerprint($this->u)]);
    $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
    $this->getJson('/api/v1/collaboration/sync?limit=101')->assertUnprocessable();
    $this->getJson('/api/v1/collaboration/sync')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $channel = $this->getJson('/api/v1/collaboration/realtime/channel')->assertOk()->json('data.channel');
    $this->postJson('/api/v1/collaboration/realtime/auth', ['socket_id' => '1.2', 'channel_name' => $channel])->assertOk()->assertJsonStructure(['auth']);
    $this->postJson('/api/v1/collaboration/realtime/auth', ['socket_id' => '1.2', 'channel_name' => 'private-collaboration.other'])->assertForbidden();
});

it('returns only live current signals and never replays their older versions', function () {
    ($this->append)(realtimeEvent($this->a, 'typing-1', 1, 'typing-user', 'test.typing'), new DeliveryAudience($this->a->institutionId, [$this->a->userId], expiresAt: now()->addSeconds(20)->toDateTimeImmutable()));
    ($this->append)(realtimeEvent($this->a, 'typing-2', 2, 'typing-user', 'test.typing'), new DeliveryAudience($this->a->institutionId, [$this->a->userId], expiresAt: now()->addSeconds(20)->toDateTimeImmutable()));
    $signals = app(\Modules\CollaborationRealtime\Services\CurrentSignals::class);
    expect(array_map(fn ($e) => $e->id, $signals->read($this->a, new PageRequest)->events))->toBe(['typing-2']);
    $this->travel(21)->seconds();
    expect($signals->read($this->a, new PageRequest)->events)->toBe([]);
});
it('rejects active-user permission and credential revocations on every read', function () {
    ($this->append)(realtimeEvent($this->a));
    DB::table('collab_application_access')->insert(['institution_id' => $this->a->institutionId, 'user_id' => $this->a->userId, 'application' => 'collaboration', 'allowed' => false, 'created_at' => now(), 'updated_at' => now()]);
    expect(fn () => $this->feed->read($this->a, new PageRequest))->toThrow(ContractException::class, 'forbidden');
});
it('enforces server capability checks on notification retrieval', function () {
    ($this->append)(realtimeEvent($this->a));
    app(\Modules\CollaborationCore\Contracts\NotificationDispatcher::class)->enqueue(new InstitutionContext($this->a->institutionId),
        new \Modules\CollaborationCore\Data\NotificationIntent('notification', 'test.changed', 'resource-1', 'event-1', 'dedupe', now()->addMinute()->toDateTimeImmutable()),
        new \Modules\CollaborationCore\Data\NotificationAudience($this->a->institutionId, [$this->a->userId]));
    $inbox = app(\Modules\CollaborationRealtime\Services\NotificationInbox::class);
    expect($inbox->current($this->a))->toHaveCount(1);
    $this->policy->allowed = false;
    expect($inbox->current($this->a))->toBe([]);
    $this->policy->allowed = true;
    app(\Modules\CollaborationCore\Services\NotificationPreferences::class)->set($this->a, false);
    expect($inbox->current($this->a))->toBe([]);
});
it('prunes expired transient payloads while preserving idempotent tombstones', function () {
    $event = realtimeEvent($this->a, type: 'test.typing');
    $audience = new DeliveryAudience($this->a->institutionId, [$this->a->userId], expiresAt: now()->addSecond()->toDateTimeImmutable());
    ($this->append)($event, $audience);
    DB::table('collab_outbox')->update(['status' => 'expired']);
    $this->travel(2)->days();
    expect(app(\Modules\CollaborationRealtime\Services\TechnicalRetention::class)->prune($this->a->institutionId))->toBe(1);
    ($this->append)($event, $audience);
    expect(DB::table('collab_events')->count())->toBe(1);
    expect($this->feed->read(realtimeActor($this->u), new PageRequest)->events)->toBe([]);
});
it('refuses network calls from uncommitted business transactions', function () {
    expect(fn () => app(\Modules\CollaborationRealtime\Contracts\RealtimePublisher::class)->publish(
        new \Modules\CollaborationRealtime\Data\DeliveryEnvelope(realtimeEvent($this->a), new DeliveryAudience($this->a->institutionId, [$this->a->userId]))
    ))->toThrow(LogicException::class);
});
it('rejects snapshot cursors used as event cursors', function () {
    ($this->append)(realtimeEvent($this->a));
    ($this->append)(realtimeEvent($this->a, 'other', 1, 'other'));
    $p = $this->feed->bootstrap($this->a, new PageRequest(limit: 1));
    expect(fn () => $this->feed->read($this->a, new PageRequest($p->nextPageCursor)))->toThrow(ContractException::class, 'invalid');
});

it('returns explicit UTC expirations for current signals', function () {
    ($this->append)(realtimeEvent($this->a, type: 'test.typing'), new DeliveryAudience($this->a->institutionId, [$this->a->userId], expiresAt: now()->addSeconds(20)->toDateTimeImmutable()));
    $response = app(\Modules\CollaborationRealtime\Services\CurrentSignals::class)->response($this->a, new PageRequest);
    expect($response['events'])->toHaveCount(1)->and($response['expires_at']['event-1'])->toEndWith('Z');
});
it('requires CSRF for WebSocket authorization using cookie sessions', function () {
    $this->actingAs($this->u)->withSession(['auth.mfa_user_id' => $this->u->id, 'auth.mfa_fingerprint' => MfaSessionProof::fingerprint($this->u)]);
    $this->app->instance('env', 'local');
    $this->postJson('/api/v1/collaboration/realtime/auth', ['socket_id' => '1.2', 'channel_name' => 'private-collaboration.forged'])->assertForbidden()->assertJsonPath('error.code', 'forbidden');
});

it('rejects a producer that conflicts with the active institutional context', function () {
    $other = Institution::factory()->create();
    $current = app(\Modules\Core\Tenancy\CurrentInstitution::class);
    expect(fn () => $current->runFor($other->id, fn () => ($this->append)(realtimeEvent($this->a))))->toThrow(ContractException::class, 'forbidden');
    expect(DB::table('collab_events')->count())->toBe(0);
});
