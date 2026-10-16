<?php

declare(strict_types=1);
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\PageRequest;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\CollaborationRealtime\Services\NotificationInbox;
use Modules\Institutions\Models\Institution;
use Modules\TeamChat\Services\ChatCommands;
use Modules\TeamChat\Services\ChatQueries;
use Modules\TeamChat\Services\MessageInteractions;
use Modules\TeamChat\Services\SpaceCommands;

require_once __DIR__.'/../../../../tests/RealtimeFixtures.php';
beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('teamchat_private');
    $this->tenant = Institution::factory()->create();
    $this->users = User::factory()->count(4)->create(['institution_id' => $this->tenant->id, 'role' => 'member']);
    [$this->a,$this->b,$this->c,$this->d] = $this->users->map(fn ($u) => realtimeActor($u))->all();
    $this->chat = app(ChatCommands::class);
    $this->interactions = app(MessageInteractions::class);
    $this->spaces = app(SpaceCommands::class);
    $this->direct = $this->chat->direct($this->a, $this->b->userId);
    $this->group = $this->spaces->create($this->a, 'group', 'group', 'Group', '', [$this->b->userId, $this->c->userId]);
});
function interactionMessage(ActorContext $a, string $c, string $id): array
{
    return app(ChatQueries::class)->messages($a, $c, new PageRequest(null, 1), [$id])['messages'][0];
}
function interactionHttp($test, int $index = 0): void
{
    $u = $test->users[$index];
    $test->actingAs($u)->withSession(['auth.mfa_user_id' => $u->id, 'auth.mfa_fingerprint' => \Modules\CollaborationCore\Services\MfaSessionProof::fingerprint($u)]);
}
it('links replies without copying text and preserves old idempotency', function () {
    $original = $this->chat->send($this->a, $this->direct, 'original', 'Original');
    $reply = $this->chat->send($this->b, $this->direct, 'reply', 'Reply', [], $original);
    expect($this->chat->send($this->b, $this->direct, 'reply', 'Reply', [], $original))->toBe($reply);
    expect(interactionMessage($this->b, $this->direct, $reply)['reply']['body'])->toBe('Original');
    $this->interactions->change($this->a, $this->direct, $original, 'edit', 'edit', ['version' => 1, 'body' => 'Edited original']);
    expect(interactionMessage($this->b, $this->direct, $reply)['reply']['body'])->toBe('Edited original');
    $this->interactions->change($this->a, $this->direct, $original, 'delete', 'delete', ['version' => 2]);
    $reference = interactionMessage($this->b, $this->direct, $reply)['reply'];
    expect($reference['deleted'])->toBeTrue()->and($reference)->not->toHaveKey('body');
    expect(DB::table('collab_chat_messages')->where('id', $reply)->value('reply_to_id'))->toBe($original);
    expect(fn () => $this->chat->send($this->b, $this->group, 'foreign-reply', 'Reply', [], $original))->toThrow(ContractException::class);
});
it('redacts reply references across historical membership periods', function () {
    $old = $this->chat->send($this->a, $this->group, 'old', 'Secret before membership');
    $this->spaces->member($this->a, 'conversation', $this->group, 'add-d', $this->d->userId, 'add');
    $reply = $this->chat->send($this->a, $this->group, 'new', 'Current', [], $old);
    expect(interactionMessage($this->d, $this->group, $reply)['reply'])->toBe(['available' => false]);
    expect(fn () => $this->chat->send($this->d, $this->group, 'bad-reply', 'No', [], $old))->toThrow(ContractException::class);
    $stale = DB::table('collab_chat_messages')->where('id', $old)->first();
    $this->spaces->member($this->a, 'conversation', $this->group, 'remove-b', $this->b->userId, 'remove');
    $this->spaces->member($this->a, 'conversation', $this->group, 'return-b', $this->b->userId, 'add');
    expect(fn () => $this->interactions->metadata($this->b, $stale))->toThrow(ContractException::class, 'not_found');
    $later = $this->chat->send($this->a, $this->group, 'later', 'Later', [], $old);
    expect(interactionMessage($this->b, $this->group, $later)['reply'])->toBe(['available' => false]);
});
it('adds and removes reactions explicitly with durable retry semantics', function () {
    $id = $this->chat->send($this->a, $this->direct, 'message', 'React');
    foreach (['one', 'one', 'two'] as $command) {
        $this->interactions->change($this->b, $this->direct, $id, $command, 'react', ['emoji' => '👍']);
    }
    expect(DB::table('collab_chat_reactions')->count())->toBe(1);
    expect(interactionMessage($this->b, $this->direct, $id)['reactions'][0]['mine'])->toBeTrue();
    $this->interactions->change($this->b, $this->direct, $id, 'remove', 'unreact', ['emoji' => '👍']);
    $this->interactions->change($this->b, $this->direct, $id, 'one', 'react', ['emoji' => '👍']);
    expect(DB::table('collab_chat_reactions')->count())->toBe(0);
    expect(fn () => $this->interactions->change($this->b, $this->direct, $id, 'one', 'react', ['emoji' => '❤️']))->toThrow(ContractException::class, 'idempotency_conflict');
    expect(fn () => $this->interactions->change($this->b, $this->direct, $id, 'invalid', 'react', ['emoji' => '<script>']))->toThrow(ContractException::class);
});
it('hides revoked reaction participants and denies further interactions', function () {
    $id = $this->chat->send($this->a, $this->group, 'message', 'React');
    $this->interactions->change($this->b, $this->group, $id, 'react', 'react', ['emoji' => '👍']);
    $this->spaces->member($this->a, 'conversation', $this->group, 'remove', $this->b->userId, 'remove');
    expect(interactionMessage($this->a, $this->group, $id)['reactions'])->toBe([]);
    expect(fn () => $this->interactions->change($this->b, $this->group, $id, 'react2', 'react', ['emoji' => '👍']))->toThrow(ContractException::class);
});
it('notifies verified mentions once and updates them on edits', function () {
    $id = $this->chat->send($this->a, $this->group, 'mention', 'Hello @['.$this->b->userId.']');
    expect(DB::table('collab_notification_intents')->where('type', 'teamchat.message.mentioned')->count())->toBe(1);
    $body = 'Hello @['.$this->b->userId.'] and @['.$this->c->userId.']';
    $this->interactions->change($this->a, $this->group, $id, 'edit1', 'edit', ['version' => 1, 'body' => $body]);
    $this->interactions->change($this->a, $this->group, $id, 'edit1', 'edit', ['version' => 1, 'body' => $body]);
    expect(DB::table('collab_notification_intents')->where('type', 'teamchat.message.mentioned')->count())->toBe(2);
    $this->interactions->change($this->a, $this->group, $id, 'edit2', 'edit', ['version' => 2, 'body' => 'Removed mentions']);
    expect(app(NotificationInbox::class)->current($this->b))->toBe([]);
    $this->interactions->change($this->a, $this->group, $id, 'edit3', 'edit', ['version' => 3, 'body' => $body]);
    expect(DB::table('collab_notification_intents')->where('type', 'teamchat.message.mentioned')->count())->toBe(2);
    expect(interactionMessage($this->b, $this->group, $id)['mentions'])->toHaveCount(2);
});
it('rejects external mentions and recipients without historical access', function () {
    $foreign = realtimeActor(User::factory()->create(['institution_id' => Institution::factory()->create()->id, 'role' => 'member']));
    foreach ([$foreign->userId, $this->d->userId] as $user) {
        expect(fn () => $this->chat->send($this->a, $this->group, 'bad-'.$user, '@['.$user.']'))->toThrow(ContractException::class);
    }
    $id = $this->chat->send($this->a, $this->group, 'before', 'Before');
    $this->spaces->member($this->a, 'conversation', $this->group, 'join', $this->d->userId, 'add');
    expect(fn () => $this->interactions->change($this->a, $this->group, $id, 'edit', 'edit', ['version' => 1, 'body' => '@['.$this->d->userId.']']))->toThrow(ContractException::class);
});
it('revalidates mention preferences and revocation before delivery', function () {
    app(\Modules\CollaborationCore\Services\NotificationPreferences::class)->set($this->b, false);
    $id = $this->chat->send($this->a, $this->group, 'mention', '@['.$this->b->userId.']');
    expect(app(NotificationInbox::class)->current($this->b))->toBe([]);
    $this->spaces->member($this->a, 'conversation', $this->group, 'remove', $this->c->userId, 'remove');
    expect(fn () => $this->interactions->change($this->c, $this->group, $id, 'r', 'react', ['emoji' => '👍']))->toThrow(ContractException::class);
});
it('enforces authorship deadlines and optimistic edit versions', function () {
    $id = $this->chat->send($this->a, $this->direct, 'm', 'Original');
    expect(fn () => $this->interactions->change($this->b, $this->direct, $id, 'other', 'edit', ['version' => 1, 'body' => 'Wrong']))->toThrow(ContractException::class);
    $this->interactions->change($this->a, $this->direct, $id, 'first', 'edit', ['version' => 1, 'body' => 'First']);
    expect(fn () => $this->interactions->change($this->a, $this->direct, $id, 'second', 'edit', ['version' => 1, 'body' => 'Second']))->toThrow(ContractException::class, 'idempotency_conflict');
    expect(interactionMessage($this->a, $this->direct, $id)['version'])->toBe(2);
    expect(DB::table('collab_chat_message_audit')->count())->toBe(1);
    $this->travel(16)->minutes();
    expect(fn () => $this->interactions->change($this->a, $this->direct, $id, 'late', 'edit', ['version' => 2, 'body' => 'Late']))->toThrow(ContractException::class);
    $this->travelBack();
    config(['teamchat.interactions.delete_hours' => 0]);
    expect(fn () => $this->interactions->change($this->a, $this->direct, $id, 'late-delete', 'delete', ['version' => 2]))->toThrow(ContractException::class);
});
it('soft deletes preserving sequence receipts audit and inaccessible attachment bytes', function () {
    $file = UploadedFile::fake()->createWithContent('work.txt', 'Private work');
    $files = app(\Modules\TeamChat\Services\ChatAttachments::class);
    $u = $files->prepare($this->a, $this->direct, 'upload', 'work.txt', 'document', 'text/plain', $file->getSize(), hash_file('sha256', $file->getPathname()));
    $files->upload($this->a, $u['id'], $file);
    $id = $this->chat->send($this->a, $this->direct, 'm', 'Caption', [$u['id']]);
    $this->chat->acknowledge($this->b, $this->direct, [$id], true);
    $this->interactions->change($this->b, $this->direct, $id, 'r', 'react', ['emoji' => '👍']);
    $this->interactions->change($this->a, $this->direct, $id, 'p', 'pin');
    foreach (['d', 'd', 'd2'] as $command) {
        $this->interactions->change($this->a, $this->direct, $id, $command, 'delete', ['version' => 1]);
    }
    $m = interactionMessage($this->b, $this->direct, $id);
    expect($m['deleted'])->toBeTrue()->and($m['body'])->toBe('')->and($m['attachments'])->toBe([])->and($m['reactions'])->toBe([])->and($m['pinned'])->toBeFalse()->and($m['sequence'])->toBe(1);
    expect(DB::table('collab_chat_receipts')->count())->toBe(1);
    expect(DB::table('collab_chat_message_audit')->count())->toBe(1);
    expect(fn () => $files->authorized($this->b, $u['id']))->toThrow(ContractException::class);
    expect(Storage::disk('teamchat_private')->exists($this->tenant->id.'/'.$u['id'].'/content'))->toBeTrue();
});
it('rolls back mutations audit commands and events atomically', function () {
    $id = $this->chat->send($this->a, $this->direct, 'm', 'Before');
    $events = DB::table('collab_events')->count();
    DB::beginTransaction();
    $this->interactions->change($this->a, $this->direct, $id, 'edit', 'edit', ['version' => 1, 'body' => 'After']);
    DB::rollBack();
    expect(interactionMessage($this->a, $this->direct, $id)['body'])->toBe('Before');
    expect(DB::table('collab_events')->count())->toBe($events)->and(DB::table('collab_chat_message_audit')->count())->toBe(0)->and(DB::table('collab_chat_message_commands')->count())->toBe(0);
});
it('restricts pinning by space roles and respects configurable limits', function () {
    $id = $this->chat->send($this->a, $this->group, 'm', 'Pin');
    expect(fn () => $this->interactions->change($this->b, $this->group, $id, 'p', 'pin'))->toThrow(ContractException::class);
    $this->interactions->change($this->a, $this->group, $id, 'p', 'pin');
    $this->interactions->change($this->a, $this->group, $id, 'p2', 'pin');
    expect(DB::table('collab_chat_pins')->count())->toBe(1);
    config(['teamchat.interactions.pin_limit' => 1]);
    $other = $this->chat->send($this->a, $this->group, 'n', 'Other');
    expect(fn () => $this->interactions->change($this->a, $this->group, $other, 'p3', 'pin'))->toThrow(ContractException::class);
    $this->interactions->change($this->a, $this->group, $id, 'unpin', 'unpin');
    expect(DB::table('collab_chat_pins')->count())->toBe(0);
});
it('searches paginated authorized fragments without marking read', function () {
    $one = $this->chat->send($this->a, $this->direct, 'one', 'Un fragmento único');
    $two = $this->chat->send($this->a, $this->direct, 'two', 'Otro fragmento único');
    interactionHttp($this, 1);
    $base = '/api/v1/collaboration/conversations/'.$this->direct;
    $page = $this->getJson($base.'/search?q=fragmento&limit=1')->assertOk()->json('data');
    expect($page['messages'][0]['id'])->toBe($two);
    $next = $this->getJson($base.'/search?q=fragmento&limit=1&cursor='.urlencode($page['next_cursor']))->assertOk()->json('data');
    expect($next['messages'][0]['id'])->toBe($one)->and($next['next_cursor'])->toBeNull();
    expect(DB::table('collab_chat_receipts')->count())->toBe(0);
    $this->getJson($base.'/search?q=diferente&cursor='.urlencode($page['next_cursor']))->assertUnprocessable();
    $this->getJson($base.'/search?q=%25%25')->assertOk()->assertJsonCount(0, 'data.messages');
    $this->interactions->change($this->a, $this->direct, $two, 'delete', 'delete', ['version' => 1]);
    $this->getJson($base.'/search?q=fragmento')->assertOk()->assertJsonCount(1, 'data.messages');
});
it('protects search pins and references after reentry and in private channels', function () {
    $id = $this->chat->send($this->a, $this->group, 'old', 'Secret historical');
    $this->interactions->change($this->a, $this->group, $id, 'pin', 'pin');
    $this->spaces->member($this->a, 'conversation', $this->group, 'join', $this->d->userId, 'add');
    interactionHttp($this, 3);
    $base = '/api/v1/collaboration/conversations/'.$this->group;
    $this->getJson($base.'/search?q=Secret')->assertOk()->assertJsonCount(0, 'data.messages');
    $this->getJson($base.'/pins')->assertOk()->assertJsonCount(0, 'data.messages');
    $team = $this->spaces->create($this->a, 'team', 't', 'Team', '', [$this->b->userId, $this->c->userId]);
    $private = $this->spaces->create($this->a, 'channel', 'p', 'Private', '', [$this->b->userId], $team, 'private');
    $this->getJson('/api/v1/collaboration/conversations/'.$private.'/search?q=Secret')->assertNotFound();
    $this->spaces->member($this->a, 'conversation', $this->group, 'remove', $this->d->userId, 'remove');
    $this->getJson($base.'/search?q=Secret')->assertNotFound();
    $this->getJson($base.'/pins')->assertNotFound();
});
it('keeps interaction events free of text and rejects cross institution operations', function () {
    $id = $this->chat->send($this->a, $this->direct, 'm', 'Sensitive body');
    $this->interactions->change($this->a, $this->direct, $id, 'e', 'edit', ['version' => 1, 'body' => 'Changed secret']);
    expect(DB::table('collab_events')->pluck('envelope')->implode(''))->not->toContain('Sensitive body')->not->toContain('Changed secret');
    $foreign = realtimeActor(User::factory()->create(['institution_id' => Institution::factory()->create()->id, 'role' => 'member']));
    expect(fn () => $this->interactions->change($foreign, $this->direct, $id, 'r', 'react', ['emoji' => '👍']))->toThrow(ContractException::class);
});

it('supports replies to real voice image and document attachments', function () {
    $pcm = str_repeat(pack('v', 0), 1600);
    $wav = 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($pcm)).$pcm;
    $files = ['voice' => UploadedFile::fake()->createWithContent('voice.wav', $wav), 'image' => UploadedFile::fake()->image('image.png', 20, 20), 'document' => UploadedFile::fake()->createWithContent('notes.txt', 'Private document')];
    $service = app(\Modules\TeamChat\Services\ChatAttachments::class);
    foreach ($files as $kind => $file) {
        $upload = $service->prepare($this->a, $this->direct, 'upload-'.$kind, $file->getClientOriginalName(), $kind, $file->getClientMimeType(), $file->getSize(), hash_file('sha256', $file->getPathname()));
        $service->upload($this->a, $upload['id'], $file);
        $id = $this->chat->send($this->a, $this->direct, 'message-'.$kind, '', [$upload['id']]);
        $reply = $this->chat->send($this->b, $this->direct, 'reply-'.$kind, 'Reply', [], $id);
        expect(interactionMessage($this->b, $this->direct, $reply)['reply']['attachment'])->toBe($kind);
        expect(fn () => $this->interactions->change($this->a, $this->direct, $id, 'edit-'.$kind, 'edit', ['version' => 1, 'body' => 'Cannot replace media']))->toThrow(ContractException::class);
    }
});
it('authorizes channel pins with space roles instead of CRM roles', function () {
    $team = $this->spaces->create($this->a, 'team', 'team', 'Team', '', [$this->b->userId, $this->c->userId]);
    $standard = DB::table('collab_chat_conversations')->where('team_id', $team)->where('visibility', 'standard')->value('id');
    $private = $this->spaces->create($this->a, 'channel', 'private', 'Private', '', [$this->b->userId], $team, 'private');
    foreach ([$standard, $private] as $channel) {
        $id = $this->chat->send($this->a, $channel, 'message-'.$channel, 'Pin policy');
        expect(fn () => $this->interactions->change($this->b, $channel, $id, 'pin-'.$channel, 'pin'))->toThrow(ContractException::class);
        $this->interactions->change($this->a, $channel, $id, 'owner-'.$channel, 'pin');
        expect(interactionMessage($this->b, $channel, $id)['pinned'])->toBeTrue();
    }
    $this->spaces->member($this->a, 'team', $team, 'promote', $this->b->userId, 'role', 'admin');
    $id = $this->chat->send($this->a, $standard, 'admin-message', 'Admin pin');
    $this->interactions->change($this->b, $standard, $id, 'admin-pin', 'pin');
    expect(interactionMessage($this->b, $standard, $id)['pinned'])->toBeTrue();
});
it('suppresses mention navigation after membership device or message revocation', function () {
    $id = $this->chat->send($this->a, $this->group, 'mention', '@['.$this->b->userId.']');
    $intent = DB::table('collab_notification_intents')->where('type', 'teamchat.message.mentioned')->first();
    interactionHttp($this, 1);
    $url = '/api/v1/collaboration/conversations/'.$this->group.'/mention-target/'.$intent->source_event_id;
    $this->getJson($url)->assertOk()->assertJsonPath('data.id', $id);
    $this->interactions->change($this->a, $this->group, $id, 'delete', 'delete', ['version' => 1]);
    $this->getJson($url)->assertNotFound();
    $this->spaces->member($this->a, 'conversation', $this->group, 'remove', $this->b->userId, 'remove');
    expect(fn () => $this->interactions->message($this->b, $this->group, $id))->toThrow(ContractException::class);
    $this->getJson($url)->assertNotFound();
});
it('keeps API message writes versioned and rejects forged actors', function () {
    $id = $this->chat->send($this->a, $this->direct, 'm', 'API original');
    interactionHttp($this);
    $url = '/api/v1/collaboration/conversations/'.$this->direct.'/messages/'.$id.'/actions/edit';
    $this->postJson($url, ['command_id' => 'e', 'body' => 'Missing version'])->assertConflict();
    $this->postJson($url, ['command_id' => 'e', 'body' => 'API edited', 'version' => 1, 'user_id' => $this->b->userId])->assertUnprocessable();
    $this->postJson($url, ['command_id' => 'e', 'body' => 'API edited', 'version' => 1])->assertOk()->assertJsonPath('data.version', 2);
    $this->postJson($url, ['command_id' => 'e', 'body' => 'API edited', 'version' => 1])->assertOk();
    $this->postJson($url, ['command_id' => 'other', 'body' => 'Stale', 'version' => 1])->assertConflict();
});

it('suppresses queued mention delivery when its recipient is expelled or its device is revoked', function () {
    $id = $this->chat->send($this->a, $this->group, 'queued', '@['.$this->b->userId.'] @['.$this->c->userId.']');
    $this->spaces->member($this->a, 'conversation', $this->group, 'expel', $this->b->userId, 'remove');
    app(\Modules\CollaborationCore\Contracts\DeviceRegistry::class)->revoke($this->c, $this->c->deviceId);
    app(\Modules\CollaborationRealtime\Services\NotificationDelivery::class)->reconcile($this->tenant->id, 100);
    $ids = DB::table('collab_notification_intents')->where('type', 'teamchat.message.mentioned')->pluck('id');
    expect($ids)->toHaveCount(2);
    expect(DB::table('collab_notification_delivery')->whereIn('intent_id', $ids)->pluck('status')->all())->toBe(['suppressed', 'suppressed']);
    expect(fn () => app(NotificationInbox::class)->current($this->c))->toThrow(ContractException::class);
    expect(interactionMessage($this->a, $this->group, $id)['mentions'])->toHaveCount(1);
});
