<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Contracts\ConversationDirectory;
use Modules\CollaborationCore\Data\ConversationRef;
use Modules\CollaborationCore\Data\PageRequest;
use Modules\CollaborationCore\Enums\ConversationType;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\CollaborationRealtime\Contracts\SyncFeed;
use Modules\Institutions\Models\Institution;
use Modules\TeamChat\Services\ChatCommands;
use Modules\TeamChat\Services\ChatQueries;
use Modules\TeamChat\Services\SpaceCommands;
use Modules\TeamChat\Services\SpaceQueries;

require_once __DIR__.'/../../../../tests/RealtimeFixtures.php';

beforeEach(function (): void {
    $this->withoutVite();
    $this->institution = Institution::factory()->create();
    $this->actors = [];
    for ($i = 0; $i < 5; $i++) {
        $this->actors[] = realtimeActor(User::factory()->create(['institution_id' => $this->institution->id, 'role' => 'member']));
    }
    [$this->a, $this->b, $this->c, $this->d, $this->e] = $this->actors;
    $this->spaces = app(SpaceCommands::class);
    $this->chats = app(ChatCommands::class);
    $this->queries = app(ChatQueries::class);
    $this->group = $this->spaces->create($this->a, 'group', 'create-group', 'Académico', '', [$this->b->userId, $this->c->userId]);
});

it('reuses one engine for groups with per-recipient receipts and idempotent creation', function () {
    expect($this->spaces->create($this->a, 'group', 'create-group', 'Académico', '', [$this->b->userId, $this->c->userId]))->toBe($this->group);
    expect(fn () => $this->spaces->create($this->a, 'group', 'create-group', 'Different', '', [$this->b->userId, $this->c->userId]))->toThrow(ContractException::class, 'idempotency_conflict');
    $id = $this->chats->send($this->a, $this->group, 'g-one', 'One');
    $this->chats->send($this->b, $this->group, 'g-two', 'Two');
    $this->chats->send($this->c, $this->group, 'g-three', 'Three');
    $this->chats->acknowledge($this->b, $this->group, [$id], true);
    expect($this->queries->messages($this->c, $this->group, new PageRequest)['messages'][0]['status'])->toBe('sent');
    expect($this->queries->messages($this->a, $this->group, new PageRequest)['messages'][0]['read_count'])->toBe(1);
    expect($this->queries->conversation($this->b, $this->group)['unread_count'])->toBe(1);
    expect(DB::table('collab_chat_messages')->count())->toBe(3);
    $audience = json_decode(DB::table('collab_notification_intents')->where('source_event_id', DB::table('collab_events')->where('envelope->data->message_id', $id)->value('id'))->value('recipients'), true);
    expect($audience)->toBe([$this->b->userId, $this->c->userId]);
});

it('enforces new and returning membership periods for history counters cursors receipts and sync', function () {
    $old = $this->chats->send($this->a, $this->group, 'old', 'Before');
    $this->spaces->member($this->a, 'conversation', $this->group, 'add-d', $this->d->userId, 'add');
    $page = $this->queries->messages($this->d, $this->group, new PageRequest);
    expect($page['messages'])->toBe([])->and($this->queries->conversation($this->d, $this->group)['unread_count'])->toBe(0);
    expect(fn () => $this->chats->acknowledge($this->d, $this->group, [$old], true))->toThrow(ContractException::class, 'not_found');
    $visible = $this->chats->send($this->a, $this->group, 'visible', 'Visible');
    $this->spaces->member($this->a, 'conversation', $this->group, 'remove-d', $this->d->userId, 'remove');
    expect(fn () => $this->queries->messages($this->d, $this->group, new PageRequest))->toThrow(ContractException::class, 'not_found');
    $gap = $this->chats->send($this->a, $this->group, 'gap', 'During absence');
    $this->spaces->member($this->a, 'conversation', $this->group, 'readd-d', $this->d->userId, 'add');
    expect($this->queries->messages($this->d, $this->group, new PageRequest($page['newer_cursor']))['messages'])->toBe([]);
    $new = $this->chats->send($this->a, $this->group, 'new', 'After rejoin');
    expect(array_column($this->queries->messages($this->d, $this->group, new PageRequest)['messages'], 'id'))->toBe([$new]);
    $this->chats->acknowledge($this->b, $this->group, [$old, $visible, $gap], true);
    foreach (app(SyncFeed::class)->read($this->d, new PageRequest(limit: 100))->events as $event) {
        expect($event->data['message_id'] ?? null)->not->toBe($old)->not->toBe($visible)->not->toBe($gap);
        expect($event->data['message_ids'] ?? [])->not->toContain($old, $visible, $gap);
    }
});

it('protects owner and role transitions without CRM privileges', function () {
    expect(fn () => $this->spaces->member($this->b, 'conversation', $this->group, 'escalate', $this->b->userId, 'role', 'admin'))->toThrow(ContractException::class, 'forbidden');
    expect(fn () => $this->chats->membership($this->a, $this->group, false))->toThrow(ContractException::class, 'forbidden');
    $this->spaces->member($this->a, 'conversation', $this->group, 'promote', $this->b->userId, 'role', 'admin');
    $this->spaces->member($this->b, 'conversation', $this->group, 'admit', $this->d->userId, 'add');
    expect(fn () => $this->spaces->member($this->b, 'conversation', $this->group, 'remove-owner', $this->a->userId, 'remove'))->toThrow(ContractException::class, 'forbidden');
    $this->spaces->member($this->a, 'conversation', $this->group, 'transfer', $this->b->userId, 'transfer');
    $this->spaces->member($this->a, 'conversation', $this->group, 'transfer', $this->b->userId, 'transfer');
    $this->chats->membership($this->a, $this->group, false);
    expect(fn () => $this->chats->send($this->a, $this->group, 'after-leave', 'No'))->toThrow(ContractException::class, 'not_found');
    $this->spaces->update($this->b, 'conversation', $this->group, 'archive', 'Archived', '', true);
    expect(fn () => $this->chats->send($this->c, $this->group, 'archived', 'No'))->toThrow(ContractException::class, 'forbidden');
    expect(DB::table('collab_chat_participants')->where('conversation_id', $this->group)->where('role', 'owner')->where('active', true)->count())->toBe(1);
});

it('derives standard channels from teams and never restores private admission on team rejoin', function () {
    $team = $this->spaces->create($this->a, 'team', 'team', 'Academia', '', [$this->b->userId]);
    $team2 = $this->spaces->create($this->a, 'team', 'team2', 'Proyecto', '', [$this->b->userId]);
    $general = app(SpaceQueries::class)->team($this->b, $team)['general_id'];
    expect(DB::table('collab_chat_participants')->where('conversation_id', $general)->count())->toBe(0);
    expect(count(app(SpaceQueries::class)->teams($this->b, new PageRequest)['teams']))->toBe(2);
    $private = $this->spaces->create($this->a, 'channel', 'private', 'Reservado', '', [$this->b->userId], $team, 'private');
    $old = $this->chats->send($this->a, $general, 'general-old', 'Before');
    $this->spaces->member($this->a, 'team', $team, 'team-add', $this->c->userId, 'add');
    expect($this->queries->messages($this->c, $general, new PageRequest)['messages'])->toBe([]);
    expect(fn () => $this->queries->conversation($this->c, $private))->toThrow(ContractException::class, 'not_found');
    $directory = app(ConversationDirectory::class);
    expect($directory->resolve($this->b, new ConversationRef(ConversationType::Channel, $general))->active)->toBeTrue();
    expect(fn () => $directory->eligibleInvitees($this->a, new ConversationRef(ConversationType::Channel, $private), [$this->c->userId]))->toThrow(ContractException::class, 'not_found');
    expect(array_column(app(SpaceQueries::class)->channels($this->c, $team, new PageRequest)['channels'], 'id'))->toBe([$general]);
    $this->spaces->member($this->a, 'team', $team, 'team-remove', $this->b->userId, 'remove');
    expect(fn () => $this->queries->conversation($this->b, $private))->toThrow(ContractException::class, 'not_found');
    $this->spaces->member($this->a, 'team', $team, 'team-readd', $this->b->userId, 'add');
    expect(fn () => $this->queries->conversation($this->b, $private))->toThrow(ContractException::class, 'not_found');
    expect($this->queries->messages($this->b, $general, new PageRequest)['messages'])->toBe([]);
    expect(app(SpaceQueries::class)->team($this->b, $team2)['name'])->toBe('Proyecto');
});

it('keeps directory signatures and requires explicit authorized call invitees', function () {
    $directory = app(ConversationDirectory::class);
    $ref = new ConversationRef(ConversationType::Group, $this->group);
    expect($directory->resolve($this->a, $ref)->active)->toBeTrue();
    expect(count($directory->eligibleInvitees($this->a, $ref, [$this->b->userId])))->toBe(1);
    expect(fn () => $directory->eligibleInvitees($this->a, $ref, []))->toThrow(ContractException::class, 'invalid');
    expect(fn () => $directory->eligibleInvitees($this->a, $ref, [$this->d->userId]))->toThrow(ContractException::class, 'not_found');
    $version = $directory->accessVersion($this->a, $ref);
    $this->spaces->member($this->a, 'conversation', $this->group, 'expel-b', $this->b->userId, 'remove');
    expect($directory->accessVersion($this->a, $ref))->not->toBe($version);
    expect($directory->membership($this->a, $ref, $this->b->userId)->active)->toBeFalse();
});

it('denies cross institution identities and administrative identity bypass', function () {
    $foreign = realtimeActor(User::factory()->create(['institution_id' => Institution::factory()->create()->id, 'role' => 'admin']));
    expect(fn () => $this->spaces->member($this->a, 'conversation', $this->group, 'foreign', $foreign->userId, 'add'))->toThrow(ContractException::class, 'not_found');
    expect(fn () => $this->queries->conversation($foreign, $this->group))->toThrow(ContractException::class, 'not_found');
    User::whereKey($this->d->userId)->update(['role' => 'admin']);
    expect(fn () => $this->queries->conversation($this->d, $this->group))->toThrow(ContractException::class, 'not_found');
    expect(fn () => app(SpaceQueries::class)->members($this->d, 'conversation', $this->group))->toThrow(ContractException::class, 'not_found');
    expect($this->queries->conversations($this->d, new PageRequest)['conversations'])->toBe([]);
});

it('rolls back membership audit clock and outbox if recording fails', function () {
    $count = DB::table('collab_chat_membership_history')->count();
    $mock = Mockery::mock(\Modules\CollaborationRealtime\Contracts\TransactionalEventRecorder::class);
    $mock->shouldReceive('append')->once()->andThrow(new RuntimeException('outbox unavailable'));
    app()->instance(\Modules\CollaborationRealtime\Contracts\TransactionalEventRecorder::class, $mock);
    expect(fn () => app(SpaceCommands::class)->member($this->a, 'conversation', $this->group, 'rollback-member', $this->d->userId, 'add'))->toThrow(RuntimeException::class);
    expect(DB::table('collab_chat_membership_history')->count())->toBe($count);
    expect(fn () => $this->queries->conversation($this->d, $this->group))->toThrow(ContractException::class, 'not_found');
});

it('enforces team administration roles and closes a private channel whose owner leaves its team', function () {
    $team = $this->spaces->create($this->a, 'team', 'owned-team', 'Team', '', [$this->b->userId, $this->c->userId]);
    expect(fn () => $this->spaces->create($this->b, 'channel', 'unauthorized-channel', 'No', '', [], $team, 'standard'))->toThrow(ContractException::class, 'forbidden');
    $this->spaces->member($this->a, 'team', $team, 'team-admin', $this->b->userId, 'role', 'admin');
    $private = $this->spaces->create($this->b, 'channel', 'owned-private', 'Private', '', [$this->c->userId], $team, 'private');
    expect(fn () => $this->queries->conversation($this->a, $private))->toThrow(ContractException::class, 'not_found');
    $this->spaces->member($this->a, 'team', $team, 'remove-owner', $this->b->userId, 'remove');
    expect($this->queries->conversation($this->c, $private)['can_send'])->toBeFalse();
    expect((bool) DB::table('collab_chat_conversations')->where('id', $private)->value('archived'))->toBeTrue();
});

it('uses the same HTTP services and GET does not change memberships or receipts', function () {
    $user = User::findOrFail($this->a->userId);
    $proof = app(\Modules\CollaborationCore\Services\MfaSessionProof::class)->fingerprint($user);
    $this->actingAs($user)->withSession(['auth.mfa_user_id' => $user->id, 'auth.mfa_fingerprint' => $proof]);
    $before = [DB::table('collab_chat_participants')->count(), DB::table('collab_chat_receipts')->count(), DB::table('collab_chat_membership_history')->count()];
    $this->getJson('/api/v1/collaboration/conversations/'.$this->group)->assertOk()->assertJsonPath('data.type', 'group');
    $this->getJson('/api/v1/collaboration/spaces/conversation/'.$this->group.'/members')->assertOk()->assertJsonCount(3, 'data.members');
    $this->getJson('/api/v1/collaboration/spaces/conversation/'.$this->group.'/changes')->assertOk();
    expect([DB::table('collab_chat_participants')->count(), DB::table('collab_chat_receipts')->count(), DB::table('collab_chat_membership_history')->count()])->toBe($before);
    $this->postJson('/api/v1/collaboration/spaces', ['kind' => 'team', 'name' => 'API Team', 'command_id' => 'http-team', 'owner_id' => $this->d->userId])->assertUnprocessable();
    $this->postJson('/api/v1/collaboration/spaces', ['kind' => 'team', 'name' => 'API Team', 'command_id' => 'http-team'])->assertOk()->assertJsonPath('data.name', 'API Team');
    $this->putJson('/api/v1/collaboration/spaces/conversation/'.$this->group, ['command_id' => 'visibility-change', 'name' => 'Group', 'description' => '', 'archived' => false, 'visibility' => 'standard'])->assertUnprocessable();
});

it('rejects stale send retries from before a rejoin and current grant revocation', function () {
    $this->chats->send($this->b, $this->group, 'previous-period', 'Before');
    $this->spaces->member($this->a, 'conversation', $this->group, 'remove', $this->b->userId, 'remove');
    $this->spaces->member($this->a, 'conversation', $this->group, 'rejoin', $this->b->userId, 'add');
    expect(fn () => $this->chats->send($this->b, $this->group, 'previous-period', 'Before'))->toThrow(ContractException::class, 'not_found');
    User::whereKey($this->b->userId)->update(['status' => 'inactive']);
    expect(fn () => $this->queries->conversation($this->b, $this->group))->toThrow(ContractException::class);
    $this->chats->send($this->a, $this->group, 'after-disabled', 'Current recipients only');
    $intent = DB::table('collab_notification_intents')->orderByDesc('id')->first();
    expect(json_decode($intent->recipients, true))->not->toContain($this->b->userId);
});
