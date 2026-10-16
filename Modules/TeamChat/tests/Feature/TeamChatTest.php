<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Contracts\ConversationDirectory;
use Modules\CollaborationCore\Contracts\DeviceRegistry;
use Modules\CollaborationCore\Contracts\NotificationDispatcher;
use Modules\CollaborationCore\Data\ConversationRef;
use Modules\CollaborationCore\Data\PageRequest;
use Modules\CollaborationCore\Enums\ConversationAction;
use Modules\CollaborationCore\Enums\ConversationType;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\CollaborationCore\Services\MfaSessionProof;
use Modules\CollaborationCore\Services\NotificationPreferences;
use Modules\CollaborationRealtime\Contracts\SyncFeed;
use Modules\Institutions\Models\Institution;
use Modules\TeamChat\Services\ChatCommands;
use Modules\TeamChat\Services\ChatQueries;

require_once __DIR__.'/../../../../tests/RealtimeFixtures.php';

beforeEach(function (): void {
    $this->withoutVite();
    $this->institution = Institution::factory()->create();
    $this->alice = User::factory()->create(['institution_id' => $this->institution->id, 'role' => 'member']);
    $this->bob = User::factory()->create(['institution_id' => $this->institution->id, 'role' => 'member']);
    $this->a = realtimeActor($this->alice);
    $this->b = realtimeActor($this->bob);
    $this->commands = app(ChatCommands::class);
    $this->queries = app(ChatQueries::class);
    $this->conversation = $this->commands->direct($this->a, $this->bob->id);
});

it('creates a unique symmetric direct thread without CRM privileges', function () {
    expect($this->commands->direct($this->b, $this->alice->id))->toBe($this->conversation)
        ->and(DB::table('collab_chat_conversations')->count())->toBe(1)
        ->and(DB::table('collab_chat_participants')->count())->toBe(2)
        ->and($this->alice->canWorkCrm())->toBeFalse();
    expect($this->queries->conversations($this->b, new PageRequest)['conversations'][0]['peer']['id'])->toBe($this->alice->id);
});

it('persists ordered text and deduplicates retries with conflict detection', function () {
    $id = $this->commands->send($this->a, $this->conversation, 'command-1', " Hola\r\nmundo ");
    expect($this->commands->send($this->a, $this->conversation, 'command-1', "Hola\nmundo"))->toBe($id);
    expect(fn () => $this->commands->send($this->a, $this->conversation, 'command-1', 'different'))->toThrow(ContractException::class, 'idempotency_conflict');
    $this->commands->send($this->b, $this->conversation, 'reply', 'Respuesta');
    $rows = $this->queries->messages(realtimeActor($this->alice), $this->conversation, new PageRequest)['messages'];
    expect(array_column($rows, 'sequence'))->toBe([1, 2])->and($rows[0]['body'])->toBe("Hola\nmundo")
        ->and(DB::table('collab_notification_intents')->count())->toBe(2)
        ->and(DB::table('collab_outbox')->count())->toBe(3);
});

it('rolls back messages sequences events and notification intents atomically', function () {
    $mock = Mockery::mock(NotificationDispatcher::class);
    $mock->shouldReceive('enqueue')->once()->andThrow(new RuntimeException('intent unavailable'));
    app()->instance(NotificationDispatcher::class, $mock);
    expect(fn () => app(ChatCommands::class)->send($this->a, $this->conversation, 'rollback', 'text'))->toThrow(RuntimeException::class);
    expect(DB::table('collab_chat_messages')->count())->toBe(0)
        ->and(DB::table('collab_chat_conversations')->value('message_sequence'))->toBe(0)
        ->and(DB::table('collab_events')->count())->toBe(1)->and(DB::table('collab_outbox')->count())->toBe(1);
});

it('paginates both directions without gaps and checks cursor owner and expiry', function () {
    for ($i = 1; $i <= 7; $i++) {
        $this->commands->send($this->a, $this->conversation, 'page-'.$i, 'Message '.$i);
    }
    $last = $this->queries->messages($this->b, $this->conversation, new PageRequest(limit: 3));
    expect(array_column($last['messages'], 'sequence'))->toBe([5, 6, 7])->and($last['has_more'])->toBeTrue();
    $older = $this->queries->messages($this->b, $this->conversation, new PageRequest($last['older_cursor'], 3));
    expect(array_column($older['messages'], 'sequence'))->toBe([2, 3, 4]);
    $this->commands->send($this->a, $this->conversation, 'page-8', 'Eight');
    $newer = $this->queries->messages($this->b, $this->conversation, new PageRequest($last['newer_cursor'], 3));
    expect(array_column($newer['messages'], 'sequence'))->toBe([8]);
    expect(fn () => $this->queries->messages($this->a, $this->conversation, new PageRequest($last['older_cursor'])))->toThrow(ContractException::class, 'invalid');
    expect(fn () => $this->queries->messages($this->b, $this->conversation, new PageRequest('tampered')))->toThrow(ContractException::class, 'invalid');
    $this->travel(25)->hours();
    expect(fn () => $this->queries->messages(realtimeActor($this->bob), $this->conversation, new PageRequest($last['older_cursor'])))->toThrow(ContractException::class, 'sync_cursor_expired');
});

it('acknowledges only received incoming messages and never infers read from history', function () {
    $one = $this->commands->send($this->a, $this->conversation, 'one', 'One');
    $two = $this->commands->send($this->a, $this->conversation, 'two', 'Two');
    $this->queries->messages($this->b, $this->conversation, new PageRequest);
    expect(DB::table('collab_chat_receipts')->count())->toBe(0);
    $this->commands->acknowledge($this->b, $this->conversation, [$two], false);
    $rows = $this->queries->messages($this->a, $this->conversation, new PageRequest)['messages'];
    expect(array_column($rows, 'status'))->toBe(['sent', 'delivered']);
    $this->commands->acknowledge($this->b, $this->conversation, [$two], true);
    $this->commands->acknowledge(realtimeActor($this->bob), $this->conversation, [$two], true);
    $this->commands->acknowledge($this->b, $this->conversation, [$two], false);
    expect(DB::table('collab_chat_receipts')->count())->toBe(1)
        ->and($this->queries->conversation($this->b, $this->conversation)['unread_count'])->toBe(1)
        ->and(DB::table('collab_events')->where('envelope->type', 'teamchat.receipt.read')->count())->toBe(1);
    expect(fn () => $this->commands->acknowledge($this->a, $this->conversation, [$one], true))->toThrow(ContractException::class, 'not_found');
});

it('denies other members and institutions including counts cursors and receipts', function () {
    $stranger = realtimeActor(User::factory()->create(['institution_id' => $this->institution->id, 'role' => 'member']));
    $foreign = realtimeActor(User::factory()->create(['institution_id' => Institution::factory()->create()->id, 'role' => 'member']));
    $id = $this->commands->send($this->a, $this->conversation, 'private', 'Secret');
    foreach ([$stranger, $foreign] as $actor) {
        expect(fn () => $this->queries->messages($actor, $this->conversation, new PageRequest))->toThrow(ContractException::class, 'not_found');
        expect(fn () => $this->commands->send($actor, $this->conversation, 'attack', 'Text'))->toThrow(ContractException::class, 'not_found');
        expect(fn () => $this->commands->acknowledge($actor, $this->conversation, [$id], true))->toThrow(ContractException::class, 'not_found');
        expect($this->queries->conversations($actor, new PageRequest)['conversations'])->toBe([]);
        expect(app(SyncFeed::class)->read($actor, new PageRequest)->events)->toBe([]);
    }
    expect(fn () => $this->commands->direct($this->a, $foreign->userId))->toThrow(ContractException::class, 'not_found');
});

it('honors current grants devices and deactivated collaborators', function () {
    $this->bob->update(['status' => 'inactive']);
    expect(fn () => $this->commands->send($this->a, $this->conversation, 'inactive', 'Text'))->toThrow(ContractException::class, 'forbidden');
    expect(fn () => $this->queries->messages($this->b, $this->conversation, new PageRequest))->toThrow(ContractException::class);
    expect($this->queries->conversation($this->a, $this->conversation)['can_send'])->toBeFalse();
    app(DeviceRegistry::class)->revoke($this->a, $this->a->deviceId);
    expect(fn () => $this->queries->messages($this->a, $this->conversation, new PageRequest))->toThrow(ContractException::class, 'device_revoked');
});

it('revokes membership immediately across history sync and future call directory', function () {
    $directory = app(ConversationDirectory::class);
    $ref = new ConversationRef(ConversationType::Direct, $this->conversation);
    $version = $directory->accessVersion($this->a, $ref);
    $this->commands->membership($this->b, $this->conversation, false);
    expect(fn () => $this->queries->messages($this->b, $this->conversation, new PageRequest))->toThrow(ContractException::class, 'not_found');
    expect(app(SyncFeed::class)->read($this->b, new PageRequest)->events)->toBe([]);
    expect($directory->membership($this->a, $ref, $this->bob->id)->active)->toBeFalse()
        ->and($directory->accessVersion($this->a, $ref))->not->toBe($version);
    expect(fn () => $directory->authorize($this->a, $ref, ConversationAction::StartCall))->toThrow(ContractException::class, 'forbidden');
});

it('implements approved direct conversation call authorization without a calls engine', function () {
    $directory = app(ConversationDirectory::class);
    $ref = new ConversationRef(ConversationType::Direct, $this->conversation);
    expect($directory->resolve($this->a, $ref)->institutionId)->toBe($this->institution->id);
    foreach (ConversationAction::cases() as $action) {
        $directory->authorize($this->a, $ref, $action);
    }
    expect($directory->eligibleInvitees($this->a, $ref, [$this->bob->id])[0]->id)->toBe($this->bob->id);
    expect(fn () => $directory->eligibleInvitees($this->a, $ref, [$this->alice->id]))->toThrow(ContractException::class, 'invalid');
    expect(fn () => $directory->resolve($this->a, new ConversationRef(ConversationType::Group, $this->conversation)))->toThrow(ContractException::class, 'not_found');
});

it('syncs durable pointer events between devices and recovers after a stable cut', function () {
    $feed = app(SyncFeed::class);
    $cut = $feed->bootstrap($this->b, new PageRequest)->cursor;
    $id = $this->commands->send($this->a, $this->conversation, 'recover', 'Private message body');
    $page = $feed->read(realtimeActor($this->bob), new PageRequest($cut));
    expect($page->events)->toHaveCount(1)->and($page->events[0]->data['message_id'])->toBe($id)
        ->and(json_encode($page->events))->not->toContain('Private message body')
        ->and($feed->read($this->b, new PageRequest($page->cursor))->events)->toBe([]);
});

it('respects core notification preferences while retaining unread counts', function () {
    app(NotificationPreferences::class)->set($this->b, false);
    $this->commands->send($this->a, $this->conversation, 'muted', 'Text');
    expect(app(\Modules\CollaborationRealtime\Services\NotificationInbox::class)->current($this->b))->toBe([])
        ->and($this->queries->conversation($this->b, $this->conversation)['unread_count'])->toBe(1);
});

it('limits institutional directory data and excludes inactive users and secrets', function () {
    $this->bob->update(['name' => 'Bob colleague']);
    User::factory()->create(['institution_id' => $this->institution->id, 'name' => 'Bob inactive', 'status' => 'inactive']);
    User::factory()->create(['institution_id' => Institution::factory()->create()->id, 'name' => 'Bob foreign']);
    $result = $this->queries->directory($this->a, 'Bob', new PageRequest);
    expect($result['users'])->toBe([['id' => $this->bob->id, 'name' => 'Bob colleague']])
        ->and(json_encode($result))->not->toContain('email', 'password', 'two_factor');
});

it('serves the neutral web shell and API while refusing forged identity and oversized inputs', function () {
    $this->actingAs($this->alice)->withSession(['auth.mfa_user_id' => $this->alice->id, 'auth.mfa_fingerprint' => MfaSessionProof::fingerprint($this->alice)]);
    $this->get('/colaboracion')->assertOk()->assertSee('teamchat-config', false)->assertSee('Colaboración');
    $this->get('/dashboard')->assertForbidden();
    $url = '/api/v1/collaboration/conversations/'.$this->conversation.'/messages';
    $this->postJson($url, ['command_id' => 'forged', 'body' => 'X', 'sender_id' => $this->bob->id])->assertUnprocessable();
    $this->postJson($url, ['command_id' => 'long', 'body' => str_repeat('a', 5001)])->assertUnprocessable();
    $this->postJson($url, ['command_id' => 'empty', 'body' => '   '])->assertUnprocessable();
    $this->postJson($url, ['command_id' => 'html', 'body' => '<script>window.hacked=true</script>'])->assertOk()->assertJsonPath('data.sender_id', $this->alice->id);
    $this->getJson($url.'?limit=51')->assertUnprocessable();
    $this->getJson($url)->assertOk()->assertJsonPath('data.messages.0.body', '<script>window.hacked=true</script>');
});

it('denies unauthenticated and collaboration-revoked API access', function () {
    $this->getJson('/api/v1/collaboration/conversations')->assertUnauthorized();
    DB::table('collab_application_access')->insert(['institution_id' => $this->institution->id, 'user_id' => $this->alice->id, 'application' => 'collaboration', 'allowed' => false, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($this->alice)->withSession(['auth.mfa_user_id' => $this->alice->id, 'auth.mfa_fingerprint' => MfaSessionProof::fingerprint($this->alice)]);
    $this->getJson('/api/v1/collaboration/conversations')->assertForbidden();
});

it('never allows self reactivation or receipt acknowledgements with invalid identifiers', function () {
    $this->commands->membership($this->b, $this->conversation, false);
    expect(fn () => $this->commands->membership($this->b, $this->conversation, true))->toThrow(ContractException::class, 'forbidden');
    expect(fn () => $this->commands->acknowledge($this->a, $this->conversation, [[]], true))->toThrow(ContractException::class, 'invalid');
});

it('renders neutral collaboration without commercial pollers and supports English', function () {
    $this->alice->update(['preferred_language' => 'en']);
    $this->actingAs($this->alice)->withSession(['auth.mfa_user_id' => $this->alice->id, 'auth.mfa_fingerprint' => MfaSessionProof::fingerprint($this->alice)]);
    $this->get('/colaboracion')->assertOk()->assertSee('Collaboration')->assertSee('New conversation')
        ->assertDontSee('wire:poll', false)->assertDontSee('__mcaPanelBadges', false);
});

it('rejects malformed event payloads before recording them in the outbox', function () {
    $policy = app(\Modules\TeamChat\Integration\ChatEventPolicy::class);
    foreach ([['message_id' => null, 'sequence' => 1], ['message_id' => (string) \Illuminate\Support\Str::ulid(), 'sequence' => 0], ['message_id' => (string) \Illuminate\Support\Str::ulid(), 'sequence' => 1, 'body' => 'not allowed']] as $payload) {
        $event = new \Modules\CollaborationRealtime\Data\EventEnvelope((string) \Illuminate\Support\Str::ulid(), 'teamchat.message.created', 1, $this->institution->id, 'teamchat_conversation', $this->conversation, 2, $this->alice->id, now()->toDateTimeImmutable(), 'policy-test', null, ['conversation_id' => $this->conversation] + $payload);
        expect(fn () => $policy->validate($event))->toThrow(InvalidArgumentException::class);
    }
});
