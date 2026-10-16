<?php

declare(strict_types=1);
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\CollaborationCore\Services\WidgetApplications;
use Modules\CollaborationCore\Services\WidgetPreferences;
use Modules\CollaborationCore\Services\WidgetSessions;
use Modules\CollaborationRealtime\Services\NotificationInbox;
use Modules\CollaborationRealtime\Services\WidgetCoordinator;
use Modules\Institutions\Models\Institution;
use Modules\TeamChat\Services\ChatAccess;
use Modules\TeamChat\Services\ChatCommands;
use Modules\TeamChat\Services\SpaceCommands;

require_once __DIR__.'/../../../../tests/RealtimeFixtures.php';
beforeEach(function (): void {
    $this->withoutVite();
    config(['collaboration_widget.allow_local_http' => true, 'collaboration_widget.central_origin' => 'http://localhost', 'collaboration_widget.cache_store' => 'array']);
    $this->tenant = Institution::factory()->create();
    $this->user = User::factory()->create(['institution_id' => $this->tenant->id, 'role' => 'member']);
    $this->actor = realtimeActor($this->user);
    $this->apps = app(WidgetApplications::class);
    $this->sessions = app(WidgetSessions::class);
    $this->apps->register('crm', $this->tenant->id, 'CRM', ['http://localhost:33320'], 'local', ['chat', 'media', 'notifications']);
    $this->apps->register('sigac', $this->tenant->id, 'SIGAC', ['http://localhost:33321'], 'local', ['chat', 'media', 'notifications']);
    $this->apps->register('moodle', $this->tenant->id, 'Moodle', ['http://localhost:33322'], 'local', ['chat', 'media', 'notifications']);
    $this->verifier = str_repeat('a', 64);
    $this->challenge = rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '=');
});
function widgetSession($test, string $app = 'crm', string $origin = 'http://localhost:33320'): array
{
    $code = $test->sessions->issue($test->actor, $app, $origin, $test->challenge);

    return $test->sessions->exchange($app, $origin, $code, $test->verifier);
}
function widgetBinding($test, array $session, string $app = 'crm', string $origin = 'http://localhost:33320'): array
{
    return $test->sessions->authenticate($session['token'], $session['instance_id'], $app, $origin);
}
function collaborationWidgetHeaders(array $session, string $app = 'crm', string $origin = 'http://localhost:33320'): array
{
    return ['Authorization' => 'MCAWidget '.$session['token'], 'X-Widget-Instance' => $session['instance_id'], 'X-Widget-App' => $app, 'X-Widget-Origin' => $origin, 'Origin' => 'http://localhost', 'Accept' => 'application/json'];
}
it('exchanges a one-time challenge-bound grant and stores only hashes', function () {
    $code = $this->sessions->issue($this->actor, 'crm', 'http://localhost:33320', $this->challenge);
    expect(fn () => $this->sessions->exchange('crm', 'http://localhost:33320', $code, str_repeat('b', 64)))->toThrow(ContractException::class);
    $s = $this->sessions->exchange('crm', 'http://localhost:33320', $code, $this->verifier);
    expect(widgetBinding($this, $s)['actor']->deviceId)->toBe($this->actor->deviceId);
    expect(fn () => $this->sessions->exchange('crm', 'http://localhost:33320', $code, $this->verifier))->toThrow(ContractException::class);
    expect(DB::table('collab_widget_grants')->first()->hash)->not->toBe($code);
    expect(DB::table('collab_widget_sessions')->first()->token_hash)->not->toBe($s['token']);
});
it('rejects unknown apps origins cross-app replay and expired grants', function () {
    expect(fn () => $this->apps->resolve('crm', 'https://evil.invalid'))->toThrow(ContractException::class);
    expect(fn () => $this->apps->origin('https://trusted.invalid@evil.invalid'))->toThrow(ContractException::class);
    $s = widgetSession($this);
    expect(fn () => widgetBinding($this, $s, 'sigac', 'http://localhost:33321'))->toThrow(ContractException::class);
    $code = $this->sessions->issue($this->actor, 'crm', 'http://localhost:33320', $this->challenge);
    $this->travel(61)->seconds();
    expect(fn () => $this->sessions->exchange('crm', 'http://localhost:33320', $code, $this->verifier))->toThrow(ContractException::class);
});
it('rotates short credentials and enforces revocation and absolute expiry', function () {
    $s = widgetSession($this);
    $b = widgetBinding($this, $s);
    $new = $this->sessions->renew($this->actor, $b['session']);
    expect(fn () => widgetBinding($this, $s))->toThrow(ContractException::class);
    $s['token'] = $new['token'];
    expect(widgetBinding($this, $s)['actor']->userId)->toBe($this->user->id);
    $this->sessions->revoke($this->actor, $s['instance_id']);
    expect(fn () => widgetBinding($this, $s))->toThrow(ContractException::class);
    $s = widgetSession($this);
    DB::table('collab_widget_sessions')->where('id', $s['instance_id'])->update(['absolute_expires_at' => now()->subSecond()]);
    expect(fn () => widgetBinding($this, $s))->toThrow(ContractException::class);
});
it('revalidates institution permission MFA device and application on every request', function () {
    $s = widgetSession($this);
    DB::table('collab_widget_apps')->where('id', 'crm')->update(['enabled' => false]);
    expect(fn () => widgetBinding($this, $s))->toThrow(ContractException::class);
    DB::table('collab_widget_apps')->where('id', 'crm')->update(['enabled' => true]);
    DB::table('collab_devices')->where('id', $this->actor->deviceId)->update(['revoked_at' => now()]);
    expect(fn () => widgetBinding($this, $s))->toThrow(ContractException::class);
    DB::table('collab_devices')->where('id', $this->actor->deviceId)->update(['revoked_at' => null]);
    $this->user->update(['status' => 'inactive']);
    expect(fn () => widgetBinding($this, $s))->toThrow(ContractException::class);
});
it('rejects another institution and authentication without MFA', function () {
    $other = Institution::factory()->create();
    $this->apps->register('other', $other->id, 'Other', ['http://localhost:33323'], 'local', ['chat']);
    expect(fn () => $this->sessions->issue($this->actor, 'other', 'http://localhost:33323', $this->challenge))->toThrow(ContractException::class);
    $this->user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
    expect(fn () => $this->sessions->issue($this->actor, 'crm', 'http://localhost:33320', $this->challenge))->toThrow(ContractException::class);
});
it('allows delegated API requests without cookies but denies privileged routes and forged origins', function () {
    $s = widgetSession($this);
    $this->withHeaders(collaborationWidgetHeaders($s))->getJson('/api/v1/collaboration/me')->assertOk();
    $this->withHeaders(collaborationWidgetHeaders($s))->postJson('/api/v1/collaboration/widget/heartbeat', ['visible' => true, 'focused' => true, 'interacted' => true])->assertOk();
    $this->withHeaders(collaborationWidgetHeaders($s))->getJson('/api/v1/collaboration/widget/content')->assertOk()->assertSee('teamchat');
    $this->withHeaders(collaborationWidgetHeaders($s))->putJson('/api/v1/collaboration/widget/preferences', ['resource_id' => '', 'visual' => true, 'sound' => true, 'muted' => false])->assertOk()->assertJsonPath('data.sound', true);
    $this->withHeaders(collaborationWidgetHeaders($s))->putJson('/api/v1/collaboration/identity-administrators/'.$this->user->id, ['enabled' => true])->assertForbidden();
    $h = collaborationWidgetHeaders($s);
    $h['Origin'] = 'https://evil.invalid';
    $this->withHeaders($h)->getJson('/api/v1/collaboration/me')->assertForbidden();
});
it('restricts frame ancestors and never exposes credentials in public configuration', function () {
    $response = $this->get('/collaboration/widget/frame?'.http_build_query(['app' => 'crm', 'origin' => 'http://localhost:33320', 'channel' => str_repeat('c', 32)]))->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
    expect($response->headers->get('Content-Security-Policy'))->toContain('frame-ancestors http://localhost:33320')->not->toContain('*');
    $this->getJson('/api/v1/collaboration/widget/config?app=crm&origin=http://localhost:33320')->assertOk()->assertJsonMissingPath('data.token')->assertJsonMissingPath('data.institution_id');
    $this->getJson('/api/v1/collaboration/widget/config?app=crm&origin=https://evil.invalid')->assertForbidden();
});
it('selects one preferred instance and permits lease recovery without duplicating acknowledged events', function () {
    $a = widgetBinding($this, widgetSession($this));
    $b = widgetBinding($this, widgetSession($this, 'sigac', 'http://localhost:33321'), 'sigac', 'http://localhost:33321');
    $sender = User::factory()->create(['institution_id' => $this->tenant->id, 'role' => 'member']);
    $other = realtimeActor($sender);
    $chat = app(ChatCommands::class);
    $conversation = $chat->direct($other, $this->user->id);
    $chat->send($other, $conversation, 'notice', 'Hi');
    $n = app(NotificationInbox::class)->current($this->actor)[0];
    $coordinator = app(WidgetCoordinator::class);
    $coordinator->heartbeat($this->actor, $a['session'], ['visible' => true, 'focused' => true, 'interacted' => true]);
    $coordinator->heartbeat($this->actor, $b['session'], ['visible' => false, 'focused' => false, 'interacted' => false]);
    expect($coordinator->claim($this->actor, $b['session'], $n['id'])['claimed'])->toBeFalse();
    $claim = $coordinator->claim($this->actor, $a['session'], $n['id']);
    expect($claim['claimed'])->toBeTrue();
    expect($coordinator->claim($this->actor, $a['session'], $n['id'])['claimed'])->toBeFalse();
    $coordinator->remove($this->actor, $a['session']->id);
    $claim = $coordinator->claim($this->actor, $b['session'], $n['id']);
    expect($claim['claimed'])->toBeTrue();
    expect(fn () => $coordinator->acknowledge($this->actor, $a['session'], $claim['event_id'], $claim['lease']))->toThrow(ContractException::class);
    $coordinator->acknowledge($this->actor, $b['session'], $claim['event_id'], $claim['lease']);
    expect($coordinator->claim($this->actor, $b['session'], $n['id'])['claimed'])->toBeFalse();
});
it('respects shared preferences and conversation snooze without suppressing message sync', function () {
    $binding = widgetBinding($this, widgetSession($this));
    $sender = User::factory()->create(['institution_id' => $this->tenant->id, 'role' => 'member']);
    $other = realtimeActor($sender);
    $chat = app(ChatCommands::class);
    $c = $chat->direct($other, $this->user->id);
    $chat->send($other, $c, 'mute', 'Still stored');
    $n = app(NotificationInbox::class)->current($this->actor)[0];
    $co = app(WidgetCoordinator::class);
    $co->heartbeat($this->actor, $binding['session'], ['visible' => true, 'focused' => true, 'interacted' => true]);
    app(WidgetPreferences::class)->set($this->actor, $c, ['visual' => true, 'sound' => true, 'muted' => true]);
    expect($co->claim($this->actor, $binding['session'], $n['id'])['claimed'])->toBeFalse();
    expect(app(ChatAccess::class)->unreadCount($this->actor))->toBe(1);
    app(WidgetPreferences::class)->set($this->actor, $c, ['visual' => true, 'sound' => false, 'muted' => false]);
    $claim = $co->claim($this->actor, $binding['session'], $n['id']);
    expect($claim['claimed'])->toBeTrue();
    DB::table('collab_devices')->where('id', $this->actor->deviceId)->update(['revoked_at' => now()]);
    expect(fn () => $co->acknowledge($this->actor, $binding['session'], $claim['event_id'], $claim['lease']))->toThrow(ContractException::class);
});
it('counts group history only within current membership periods', function () {
    $owner = User::factory()->create(['institution_id' => $this->tenant->id, 'role' => 'member']);
    $a = realtimeActor($owner);
    $spaces = app(SpaceCommands::class);
    $chat = app(ChatCommands::class);
    $members = User::factory()->count(2)->create(['institution_id' => $this->tenant->id, 'role' => 'member'])->pluck('id')->all();
    $group = $spaces->create($a, 'group', 'count-group', 'Group', '', $members);
    $chat->send($a, $group, 'old', 'Old');
    $spaces->member($a, 'conversation', $group, 'add', $this->user->id, 'add');
    $id = $chat->send($a, $group, 'current', 'Current');
    expect(app(ChatAccess::class)->unreadCount($this->actor))->toBe(1);
    $spaces->member($a, 'conversation', $group, 'remove', $this->user->id, 'remove');
    expect(app(ChatAccess::class)->unreadCount($this->actor))->toBe(0);
    $spaces->member($a, 'conversation', $group, 'rejoin', $this->user->id, 'add');
    expect(app(ChatAccess::class)->unreadCount($this->actor))->toBe(0);
});

it('rejects expired source sessions changed institutions and missing media capability', function () {
    $s = widgetSession($this);
    DB::table('collab_widget_apps')->where('id', 'crm')->update(['capabilities' => json_encode(['chat'])]);
    $this->withHeaders(collaborationWidgetHeaders($s))->postJson('/api/v1/collaboration/uploads/'.(string) Illuminate\Support\Str::ulid().'/content', [])->assertForbidden();
    $this->withHeaders(collaborationWidgetHeaders($s))->postJson('/api/v1/collaboration/widget/ack', [])->assertForbidden();
    DB::table('collab_devices')->where('id', $this->actor->deviceId)->update(['expires_at' => now()->subSecond()]);
    expect(fn () => widgetBinding($this, $s))->toThrow(ContractException::class);
    DB::table('collab_devices')->where('id', $this->actor->deviceId)->update(['expires_at' => now()->addHour()]);
    config(['session.driver' => 'database']);
    expect(fn () => widgetBinding($this, $s))->toThrow(ContractException::class, 'session_revoked');
    config(['session.driver' => 'array']);
    $this->user->update(['institution_id' => Institution::factory()->create()->id]);
    expect(fn () => widgetBinding($this, $s))->toThrow(ContractException::class);
});
it('does not reveal another users conversation through widget preferences or messages', function () {
    $s = widgetSession($this);
    $users = User::factory()->count(2)->create(['institution_id' => $this->tenant->id, 'role' => 'member']);
    $owner = realtimeActor($users[0]);
    $c = app(ChatCommands::class)->direct($owner, $users[1]->id);
    $this->withHeaders(collaborationWidgetHeaders($s))->getJson('/api/v1/collaboration/conversations/'.$c.'/messages')->assertNotFound();
    $this->withHeaders(collaborationWidgetHeaders($s))->putJson('/api/v1/collaboration/widget/preferences', ['resource_id' => $c, 'visual' => true, 'sound' => false, 'muted' => true])->assertNotFound();
});
it('counts standard and private channel history only during effective admission', function () {
    $owner = realtimeActor(User::factory()->create(['institution_id' => $this->tenant->id, 'role' => 'member']));
    $spaces = app(SpaceCommands::class);
    $chat = app(ChatCommands::class);
    $team = $spaces->create($owner, 'team', 'counter-team', 'Team', '', [$this->actor->userId]);
    $standard = DB::table('collab_chat_conversations')->where('team_id', $team)->where('visibility', 'standard')->value('id');
    $private = $spaces->create($owner, 'channel', 'counter-private', 'Private', '', [], $team, 'private');
    $chat->send($owner, $standard, 'standard-old', 'Visible standard');
    $chat->send($owner, $private, 'private-old', 'Hidden private');
    expect(app(ChatAccess::class)->unreadCount($this->actor))->toBe(1);
    $spaces->member($owner, 'conversation', $private, 'private-add', $this->actor->userId, 'add');
    $chat->send($owner, $private, 'private-new', 'Visible private');
    expect(app(ChatAccess::class)->unreadCount($this->actor))->toBe(2);
    $spaces->member($owner, 'team', $team, 'team-remove', $this->actor->userId, 'remove');
    expect(app(ChatAccess::class)->unreadCount($this->actor))->toBe(0);
    $spaces->member($owner, 'team', $team, 'team-rejoin', $this->actor->userId, 'add');
    expect(app(ChatAccess::class)->unreadCount($this->actor))->toBe(0);
});
it('preserves other preferences and snooze when another instance changes one field', function () {
    $s = widgetSession($this);
    $this->withHeaders(collaborationWidgetHeaders($s))->putJson('/api/v1/collaboration/widget/preferences', ['sound' => true, 'snooze_minutes' => 60])->assertOk()->assertJsonPath('data.muted', true);
    $until = app(WidgetPreferences::class)->get($this->actor)['muted_until'];
    $this->withHeaders(collaborationWidgetHeaders($s))->putJson('/api/v1/collaboration/widget/preferences', ['visual' => false])->assertOk()->assertJsonPath('data.sound', true)->assertJsonPath('data.muted', true);
    expect(app(WidgetPreferences::class)->get($this->actor)['muted_until'])->toBe($until);
    $this->withHeaders(collaborationWidgetHeaders($s))->putJson('/api/v1/collaboration/widget/preferences', ['snooze_minutes' => 0])->assertOk()->assertJsonPath('data.muted', false)->assertJsonPath('data.visual', false)->assertJsonPath('data.sound', true);
});
