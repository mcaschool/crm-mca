<?php

declare(strict_types=1);
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Contracts\ConversationDirectory;
use Modules\CollaborationCore\Contracts\DeviceRegistry;
use Modules\CollaborationCore\Contracts\PresenceService;
use Modules\CollaborationCore\Data\CallTarget;
use Modules\CollaborationCore\Data\ConversationRef;
use Modules\CollaborationCore\Data\InstitutionContext;
use Modules\CollaborationCore\Enums\ConversationType;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\CollaborationCore\Integration\CallPresenceAuthorizer;
use Modules\CollaborationCore\Integration\CallPresenceCleanupAuthorizer;
use Modules\CollaborationCore\Services\ApplicationAccess;
use Modules\CollaborationCore\Services\Revocation;
use Modules\CollaborationRealtime\Data\EventEnvelope;
use Modules\CollaborationRealtime\Integration\RevocationConsumer;
use Modules\CollaborationRealtime\Services\EventCodec;
use Modules\CollaborationRealtime\Services\RevocationDelivery;
use Modules\CollaborationRealtime\Services\RevocationPolicy;
use Modules\Institutions\Models\Institution;
use Modules\TeamChat\Services\ChatCommands;
use Modules\TeamChat\Services\SpaceCommands;

require_once __DIR__.'/../../../../tests/RealtimeFixtures.php';

beforeEach(function (): void {
    $this->tenant = Institution::factory()->create();
    $this->u = User::factory()->create(['institution_id' => $this->tenant->id]);
    $this->v = User::factory()->create(['institution_id' => $this->tenant->id]);
    $this->w = User::factory()->create(['institution_id' => $this->tenant->id]);
    $this->a = realtimeActor($this->u);
    $this->b = realtimeActor($this->v);
    $this->c = realtimeActor($this->w);
});
function revocations4b(?string $type = null)
{
    return DB::table('collab_events')->where('aggregate_type', 'collaboration_revocation')
        ->when($type !== null, fn ($q) => $q->where('envelope->type', $type));
}

it('records effective access revocation once and rejects new operations immediately', function () {
    app(ApplicationAccess::class)->set($this->u, $this->v, 'collaboration', false);
    app(ApplicationAccess::class)->set($this->u, $this->v, 'collaboration', false);
    expect(revocations4b('collaboration.access.revoked')->count())->toBe(1)
        ->and(revocations4b('collaboration.device.revoked')->count())->toBe(1);
    expect(fn () => app(DeviceRegistry::class)->resolveCurrent($this->b))->toThrow(ContractException::class);
    $data = json_decode(revocations4b('collaboration.access.revoked')->value('envelope'), true);
    expect($data['data'])->toEqual(['reason' => 'application_revoked', 'resource_id' => (string) $this->v->id, 'scope' => 'user', 'user_id' => $this->v->id]);
    foreach (revocations4b()->get() as $event) {
        expect($event->envelope)->not->toContain($this->v->email)->not->toContain('session_hash')->not->toContain('password');
        expect(json_decode($event->audience, true)['users'])->toBe([]);
        expect(DB::table('collab_outbox')->where('event_id', $event->id)->value('status'))->toBe('pending');
    }
});
it('rolls back the access mutation devices and outbox together', function () {
    DB::beginTransaction();
    app(ApplicationAccess::class)->set($this->u, $this->v, 'collaboration', false);
    expect(revocations4b()->count())->toBe(2);
    DB::rollBack();
    expect(revocations4b()->count())->toBe(0);
    expect(app(DeviceRegistry::class)->resolveCurrent($this->b)->id)->toBe($this->b->deviceId);
});
it('records user deactivation credentials and old institution scope', function () {
    $this->v->update(['status' => 'inactive']);
    $this->v->update(['status' => 'inactive']);
    expect(revocations4b('collaboration.access.revoked')->count())->toBe(1);
    $this->v->update(['status' => 'active']);
    $this->v->update(['password' => 'local-test-only-new-password']);
    expect(revocations4b('collaboration.access.revoked')->count())->toBe(2);
    $other = Institution::factory()->create();
    $this->v->update(['institution_id' => $other->id]);
    expect(revocations4b('collaboration.access.revoked')->where('institution_id', $this->tenant->id)->count())->toBe(3)
        ->and(revocations4b()->where('institution_id', $other->id)->count())->toBe(0);
});
it('records disabled tenant events without granting a client access exception', function () {
    $other = Institution::factory()->create();
    realtimeActor(User::factory()->create(['institution_id' => $other->id]));
    $this->tenant->forceFill(['collaboration_enabled' => false])->save();
    expect(revocations4b('collaboration.access.revoked')->count())->toBe(3)
        ->and(revocations4b()->where('institution_id', $other->id)->count())->toBe(0);
    $event = app(EventCodec::class)->decode(revocations4b()->first())->event;
    expect((new RevocationPolicy)->allows($this->a, $event))->toBeFalse();
    expect(fn () => app(DeviceRegistry::class)->resolveCurrent($this->a))->toThrow(ContractException::class);
});
it('rolls back model updates with their revocations', function () {
    DB::beginTransaction();
    $this->v->update(['status' => 'inactive']);
    DB::rollBack();
    expect($this->v->fresh()->status)->toBe('active')->and(revocations4b()->count())->toBe(0);
});
it('revokes only the selected device and session with safe repetition', function () {
    $second = realtimeActor($this->v);
    app(DeviceRegistry::class)->revoke($second, $this->b->deviceId);
    app(DeviceRegistry::class)->revoke($second, $this->b->deviceId);
    app(Revocation::class)->session($this->v->id, $this->b->sessionId, 'logout');
    expect(revocations4b('collaboration.device.revoked')->count())->toBe(1)
        ->and(app(DeviceRegistry::class)->resolveCurrent($second)->id)->toBe($second->deviceId);
    expect(fn () => app(DeviceRegistry::class)->revoke($this->a, $second->deviceId))->toThrow(ContractException::class);
});
it('materializes expiry and lost session eligibility once during reconciliation', function () {
    DB::table('collab_devices')->where('id', $this->b->deviceId)->update(['expires_at' => now()->subMinute()]);
    expect(fn () => app(DeviceRegistry::class)->resolveCurrent($this->b))->toThrow(ContractException::class);
    expect(app(Revocation::class)->reconcile($this->tenant->id))->toBe(1)
        ->and(app(Revocation::class)->reconcile($this->tenant->id))->toBe(0)
        ->and(revocations4b('collaboration.device.revoked')->count())->toBe(1);
});
it('fails closed until a consumer exists and atomically deduplicates its durable effects', function () {
    // Integración 4B: TeamCalls ya enlaza su consumidor; se retira para comprobar el comportamiento SIN él.
    app()->offsetUnset(RevocationConsumer::class);
    app(Revocation::class)->session($this->v->id, $this->b->sessionId);
    $event = app(EventCodec::class)->decode(revocations4b()->first())->event;
    expect(fn () => app(RevocationDelivery::class)->deliver($event))->toThrow(RuntimeException::class);
    $consumer = new class implements RevocationConsumer
    {
        public int $calls = 0;

        public bool $fail = true;

        public function consume(EventEnvelope $event): void
        {
            $this->calls++;
            if ($this->fail) {
                throw new RuntimeException('retry');
            }
        }
    };
    app()->instance(RevocationConsumer::class, $consumer);
    expect(fn () => app(RevocationDelivery::class)->deliver($event))->toThrow(RuntimeException::class);
    expect(DB::table('collab_revocation_receipts')->count())->toBe(0);
    $consumer->fail = false;
    app(RevocationDelivery::class)->deliver($event);
    app(RevocationDelivery::class)->deliver($event);
    expect($consumer->calls)->toBe(2)->and(DB::table('collab_revocation_receipts')->count())->toBe(1);
});
it('emits direct group and derived channel revocations without changing historical membership periods', function () {
    $chat = app(ChatCommands::class);
    $spaces = app(SpaceCommands::class);
    $direct = $chat->direct($this->a, $this->v->id);
    $chat->membership($this->b, $direct, false);
    $group = $spaces->create($this->a, 'group', 'g', 'Group', '', [$this->v->id, $this->w->id]);
    $spaces->member($this->a, 'conversation', $group, 'remove-g', $this->v->id, 'remove');
    $spaces->member($this->a, 'conversation', $group, 'remove-g', $this->v->id, 'remove');
    $team = $spaces->create($this->a, 'team', 't', 'Team', '', [$this->v->id, $this->w->id]);
    $private = $spaces->create($this->a, 'channel', 'p', 'Private', '', [$this->v->id], $team, 'private');
    $spaces->member($this->a, 'team', $team, 'remove-t', $this->v->id, 'remove');
    expect(revocations4b('conversation.membership.revoked')->count())->toBe(5);
    $scopes = revocations4b('conversation.membership.revoked')->pluck('envelope')->map(fn ($e) => json_decode($e, true)['data']['scope'])->sort()->values()->all();
    expect($scopes)->toBe(['channel', 'channel', 'direct', 'group', 'team']);
    $spaces->member($this->a, 'team', $team, 'rejoin-t', $this->v->id, 'add');
    expect(DB::table('collab_chat_participants')->where('conversation_id', $private)->where('user_id', $this->v->id)->value('active'))->toBe(0);
});
it('resolves only the direct peer from the approved CallTarget and revalidates membership', function () {
    $chat = app(ChatCommands::class);
    $direct = $chat->direct($this->a, $this->v->id);
    $ref = new ConversationRef(ConversationType::Direct, $direct);
    $target = CallTarget::conversation($ref);
    $directory = app(ConversationDirectory::class);
    expect($directory->eligibleInvitees($this->a, $target->conversation, $target->userIds)[0]->id)->toBe($this->v->id);
    expect(fn () => $directory->eligibleInvitees($this->a, $ref, [$this->u->id]))->toThrow(ContractException::class);
    expect(fn () => $directory->eligibleInvitees($this->c, $ref, []))->toThrow(ContractException::class);
    $chat->membership($this->b, $direct, false);
    expect(fn () => $directory->eligibleInvitees($this->a, $ref, []))->toThrow(ContractException::class);
});
it('still requires explicit invitees for groups and channels', function () {
    $spaces = app(SpaceCommands::class);
    $g = $spaces->create($this->a, 'group', 'g', 'Group', '', [$this->v->id, $this->w->id]);
    $t = $spaces->create($this->a, 'team', 't', 'Team', '', [$this->v->id]);
    $c = DB::table('collab_chat_conversations')->where('team_id', $t)->value('id');
    foreach ([[ConversationType::Group, $g], [ConversationType::Channel, $c]] as [$type, $id]) {
        $ref = new ConversationRef($type, $id);
        expect(fn () => app(ConversationDirectory::class)->eligibleInvitees($this->a, $ref, []))->toThrow(ContractException::class);
        expect(app(ConversationDirectory::class)->eligibleInvitees($this->a, $ref, [$this->v->id])[0]->id)->toBe($this->v->id);
    }
});
it('clears historical calls after leave or end without erasing another call', function () {
    $adapter = new class($this->tenant->id, $this->u->id) implements CallPresenceCleanupAuthorizer
    {
        public bool $active = true;

        public function __construct(private int $tenant, private int $user) {}

        public function authorize(InstitutionContext $context, int $userId, string $callId): void
        {
            $this->authorizeCleanup($context, $userId, $callId);
            if (! $this->active) {
                throw new ContractException('forbidden', 403);
            }
        }

        public function authorizeCleanup(InstitutionContext $context, int $userId, string $callId): void
        {
            if ($context->institutionId !== $this->tenant || $userId !== $this->user || ! in_array($callId, ['left', 'ended'], true)) {
                throw new ContractException('forbidden', 403);
            }
        }
    };
    app()->instance(CallPresenceAuthorizer::class, $adapter);
    $presence = app(PresenceService::class);
    $ctx = new InstitutionContext($this->tenant->id);
    $presence->setInCall($ctx, $this->u->id, 'left', 30);
    $presence->setInCall($ctx, $this->u->id, 'ended', 60);
    $adapter->active = false;
    expect(fn () => $presence->setInCall($ctx, $this->u->id, 'left', 30))->toThrow(ContractException::class);
    $presence->clearInCall($ctx, $this->u->id, 'left');
    $presence->clearInCall($ctx, $this->u->id, 'left');
    expect(DB::table('collab_presence_calls')->pluck('call_id')->all())->toBe(['ended']);
    expect(fn () => $presence->clearInCall($ctx, $this->v->id, 'ended'))->toThrow(ContractException::class);
    expect(fn () => $presence->clearInCall(new InstitutionContext(Institution::factory()->create()->id), $this->u->id, 'ended'))->toThrow(ContractException::class);
    $this->travel(61)->seconds();
    $presence->clearInCall($ctx, $this->u->id, 'ended');
    expect(DB::table('collab_presence_calls')->count())->toBe(0);
});
it('invalidates stored database sessions atomically and reports their registered devices', function () {
    config(['session.driver' => 'database']);
    $id = str_repeat('s', 40);
    DB::table('sessions')->insert(['id' => $id, 'user_id' => $this->v->id, 'payload' => '', 'last_activity' => now()->timestamp]);
    DB::table('collab_devices')->where('id', $this->b->deviceId)->update(['session_hash' => hash('sha256', $id)]);
    DB::beginTransaction();
    app(Revocation::class)->session($this->v->id, hash('sha256', $id), 'logout');
    expect(DB::table('sessions')->where('id', $id)->exists())->toBeFalse();
    DB::rollBack();
    expect(DB::table('sessions')->where('id', $id)->exists())->toBeTrue()->and(revocations4b()->count())->toBe(0);
    app(Revocation::class)->session($this->v->id, hash('sha256', $id), 'logout');
    app(Revocation::class)->session($this->v->id, hash('sha256', $id), 'logout');
    expect(DB::table('sessions')->where('id', $id)->exists())->toBeFalse()->and(revocations4b()->count())->toBe(1);
});
it('rolls back group channel and team revocation with their memberships', function () {
    $spaces = app(SpaceCommands::class);
    $team = $spaces->create($this->a, 'team', 't', 'Team', '', [$this->v->id]);
    $channel = $spaces->create($this->a, 'channel', 'p', 'Private', '', [$this->v->id], $team, 'private');
    DB::beginTransaction();
    $spaces->member($this->a, 'conversation', $channel, 'remove-p', $this->v->id, 'remove');
    expect(revocations4b()->count())->toBe(1);
    DB::rollBack();
    expect(revocations4b()->count())->toBe(0)
        ->and(DB::table('collab_chat_participants')->where('conversation_id', $channel)->where('user_id', $this->v->id)->value('active'))->toBe(1);
});
it('rolls back an Eloquent security save if durable recording fails', function () {
    app()->bind(\Modules\CollaborationCore\Contracts\RevocationRecorder::class, fn () => new class implements \Modules\CollaborationCore\Contracts\RevocationRecorder
    {
        public function record(\Modules\CollaborationCore\Events\RevocationNotice $notice): string
        {
            throw new RuntimeException('outbox unavailable');
        }
    });
    expect(fn () => $this->v->update(['status' => 'inactive']))->toThrow(RuntimeException::class);
    expect($this->v->fresh()->status)->toBe('active')
        ->and(DB::table('collab_devices')->where('id', $this->b->deviceId)->value('revoked_at'))->toBeNull()
        ->and(revocations4b()->count())->toBe(0);
});
it('rejects secrets and client audiences for internal revocation envelopes', function () {
    $notice = new \Modules\CollaborationCore\Events\CollaborationAccessRevoked($this->tenant->id, $this->v->id, 'user_inactive');
    $id = (string) \Illuminate\Support\Str::ulid();
    $make = fn ($data) => new EventEnvelope($id, $notice->type(), 1, $this->tenant->id, 'collaboration_revocation', $id, 1, null, now()->toDateTimeImmutable(), $id, null, $data);
    expect(fn () => (new RevocationPolicy)->validate($make($notice->data() + ['secret' => 'forbidden'])))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(\Modules\CollaborationRealtime\Contracts\TransactionalEventRecorder::class)->append(
        new InstitutionContext($this->tenant->id), $make($notice->data()), new \Modules\CollaborationRealtime\Data\DeliveryAudience($this->tenant->id, [$this->u->id])
    ))->toThrow(ContractException::class);
    expect(revocations4b()->count())->toBe(0);
});
it('denies arbitrary presence cleanup without the trusted TeamCalls adapter', function () {
    expect(fn () => app(PresenceService::class)->clearInCall(new InstitutionContext($this->tenant->id), $this->u->id, 'unknown'))->toThrow(ContractException::class);
});
