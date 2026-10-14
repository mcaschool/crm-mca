<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Livewire\Agenda\VideoRooms;
use Modules\Crm\Livewire\Video\Connections;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\ActivityGuest;
use Modules\Crm\Models\MeetingProfile;
use Modules\Crm\Models\MeetingType;
use Modules\Crm\Models\VideoRoom;
use Modules\Crm\Services\Agenda\ActivityService;
use Modules\Crm\Services\Agenda\ScheduleConflictException;
use Modules\Crm\Services\Booking\BookingSlots;
use Modules\Crm\Services\Video\VideoProviderException;
use Modules\Crm\Services\Video\VideoRoomService;
use Modules\Institutions\Models\Institution;
use Modules\Integrations\Models\Integration;

/*
| Videoconferencias institucionales (Fase 2). TODAS las respuestas de Google y Zoom son simuladas
| (Http::fake + preventStrayRequests): ninguna prueba toca cuentas ni credenciales reales.
| Lunes 19/10/2026 07:00 UTC; asesores en America/Bogota de 09:00 a 12:00 (14:00–17:00 UTC).
*/

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-19 07:00:00', 'UTC'));
    Mail::fake();
    Http::preventStrayRequests();
    config(['crm.video.retries' => 0]);
    // licensed: correos de asesores con licencia en la cuenta · hosts: anfitrión de cada reunión creada
    $GLOBALS['vc'] = (object) ['calls' => [], 'faked' => false, 'opts' => [], 'n' => 0, 'licensed' => [], 'hosts' => [], 'settings' => []];
});

/** Da al asesor una licencia en la cuenta Zoom simulada (será el anfitrión de su reunión). */
function vcLicense(User $u): void
{
    $GLOBALS['vc']->licensed[] = strtolower($u->email);
}

function vcZoomId(User $u): string
{
    return 'ADV'.substr(md5(strtolower($u->email)), 0, 6);
}

/** Simula Google (OAuth + Meet v2) y Zoom (S2S + API v2). Registra cada llamada en $this->calls. */
function vcFake(array $o = []): void
{
    $t = $GLOBALS['vc'];
    $t->opts = $o;
    if ($t->faked) {
        return; // Http::fake acumula stubs: se registra una vez y lee las opciones vigentes
    }
    $t->faked = true;
    $t->n = 0;
    Http::fake(function (Request $r) use ($t) {
        $o = $t->opts;
        $url = $r->url();
        $m = $r->method();
        $t->calls[] = $m.' '.preg_replace('/\?.*/', '', $url);
        $is = fn (string $p) => str_contains($url, $p);

        // Google
        if ($is('oauth2.googleapis.com/token')) {
            if (($o['google_refresh'] ?? 'ok') === 'invalid_grant' && $r['grant_type'] === 'refresh_token') {
                return Http::response(['error' => 'invalid_grant'], 400);
            }

            return Http::response(['access_token' => 'ya29.NEW', 'refresh_token' => 'rt-NEW', 'expires_in' => 3599, 'token_type' => 'Bearer',
                'scope' => 'openid https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/meetings.space.created']);
        }
        if ($is('oauth2.googleapis.com/revoke')) {
            return Http::response([], 200);
        }
        if ($is('openidconnect.googleapis.com/v1/userinfo')) {
            return Http::response(['email' => 'salas@mca.test', 'email_verified' => true]);
        }
        if ($is('meet.googleapis.com/v2/spaces') && $m === 'POST' && ! $is('/members')) {
            $n = ++$t->n;
            $code = ['abc-defg-hij', 'kkk-llll-mmm', 'nnn-oooo-ppp'][$n - 1] ?? 'zzz-zzzz-zzz';

            return Http::response($o['google_space'] ?? ['name' => 'spaces/sp'.$n, 'meetingUri' => 'https://meet.google.com/'.$code, 'meetingCode' => $code, 'config' => ['accessType' => 'TRUSTED']]);
        }
        if ($is('/members') && $m === 'GET') {
            return Http::response(['members' => []]);
        }
        if ($is('/members') && $m === 'POST') {
            return ($o['cohost'] ?? 'ok') === 'ok'
                ? Http::response(['name' => 'spaces/sp1/members/m1', 'email' => $r['email'], 'role' => 'COHOST'])
                : Http::response(['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED']], 403);
        }
        if (preg_match('~meet\.googleapis\.com/v2/(spaces/sp\d+)$~', $url, $mm) && $m === 'GET') {
            if (($o['space_missing'] ?? false)) {
                return Http::response(['error' => ['code' => 404, 'status' => 'NOT_FOUND']], 404);
            }
            $code = ['sp1' => 'abc-defg-hij', 'sp2' => 'kkk-llll-mmm', 'sp3' => 'nnn-oooo-ppp'][substr($mm[1], 7)] ?? 'zzz-zzzz-zzz';

            return Http::response(['name' => $mm[1], 'meetingUri' => 'https://meet.google.com/'.$code, 'meetingCode' => $code]);
        }

        // Zoom
        if ($is('zoom.us/oauth/token')) {
            return ($o['zoom_token'] ?? 'ok') === 'ok'
                ? Http::response(['access_token' => 'zoom-AT', 'token_type' => 'bearer', 'expires_in' => 3599, 'scope' => 'meeting:write:meeting:admin'])
                : Http::response(['reason' => 'Invalid client_id or client_secret', 'error' => 'invalid_client'], 401);
        }
        if ($is('zoom.us/oauth/revoke')) {
            return Http::response(['status' => 'success']);
        }
        if (preg_match('~api\.zoom\.us/v2/users/([^/?]+)$~', preg_replace('/\?.*/', '', $url), $mm)) {
            $ref = rawurldecode($mm[1]);
            if (($o['flaky'] ?? false) && ! ($t->flakyDone ?? false)) {
                $t->flakyDone = true;

                return Http::response(['message' => 'unavailable'], 503);
            }
            if ($ref === 'me' || $ref === 'HOSTINST' || $ref === 'host@mca.test') {
                return Http::response(['id' => 'HOSTINST', 'email' => 'host@mca.test', 'type' => 2, 'status' => 'active', 'account_id' => 'ACC1']);
            }
            if (in_array($ref, $t->licensed, true)) {
                return Http::response(['id' => 'ADV'.substr(md5($ref), 0, 6), 'email' => $ref, 'type' => 2, 'status' => 'active', 'account_id' => 'ACC1']);
            }
            if (in_array($ref, $o['basic'] ?? [], true)) { // usuario de la cuenta SIN licencia
                return Http::response(['id' => 'BAS'.substr(md5($ref), 0, 6), 'email' => $ref, 'type' => 1, 'status' => 'active', 'account_id' => 'ACC1']);
            }

            return Http::response(['code' => 1001, 'message' => 'User does not exist'], 404);
        }
        if ($is('/meetings') && $m === 'GET' && $is('/users/') && $is('type=live')) { // reuniones en curso del anfitrión
            return Http::response(['meetings' => array_map(fn ($id) => ['id' => (int) $id, 'type' => 3], $o['live'] ?? []), 'next_page_token' => '']);
        }
        if ($is('/meetings') && $m === 'GET' && $is('/users/')) {
            return Http::response(['meetings' => $o['zoom_existing'] ?? [], 'next_page_token' => '']);
        }
        // Lo que la cuenta aplica realmente: puede bloquear la sala de espera o «unirse antes».
        $applied = function (array $s) use ($o): array {
            $waiting = ($o['account_no_waiting_room'] ?? false) ? false : (($o['account_locks_jbh'] ?? false) ? true : (bool) $s['waiting_room']);
            $jbh = ($o['account_locks_jbh'] ?? false) ? false : (bool) $s['join_before_host'];

            return ['join_before_host' => $jbh, 'waiting_room' => $waiting];
        };
        if ($is('/meetings') && $m === 'POST') {
            if (($o['zoom_create'] ?? 'ok') === '500') {
                return Http::response(['message' => 'internal'], 500);
            }
            if (($o['zoom_create'] ?? 'ok') === '400') { // restricción real de la cuenta, con su motivo
                return Http::response(['code' => 3161, 'message' => 'Meeting hosting and scheduling capabilities are not allowed for your user account. See https://support.zoom.us/x'], 400);
            }
            $n = ++$t->n;
            $host = (string) preg_replace('~.*/users/([^/]+)/meetings.*~', '$1', $url);
            $join = $o['zoom_join'] ?? 'https://us02web.zoom.us/j/8123456789'.$n.'?pwd=abc'.$n;
            $t->hosts['8123456789'.$n] = $host;
            $t->settings['8123456789'.$n] = $applied($r['settings']);

            return Http::response(['id' => (int) ('8123456789'.$n), 'host_id' => $host, 'type' => 3, 'topic' => $r['topic'], 'agenda' => $r['agenda'],
                'join_url' => $join, 'start_url' => 'https://us02web.zoom.us/s/8123456789'.$n.'?zak=SECRETZAK', 'password' => 'abc'.$n,
                'settings' => $t->settings['8123456789'.$n]], 201);
        }
        if (preg_match('~api\.zoom\.us/v2/meetings/(\d+)$~', $url, $mm) && $m === 'PATCH') {
            $t->settings[$mm[1]] = $applied($r['settings']);

            return Http::response(null, 204);
        }
        if (preg_match('~api\.zoom\.us/v2/meetings/(\d+)$~', $url, $mm)) {
            $k = substr($mm[1], -1);
            if ($o['meeting_missing'] ?? false) {
                return Http::response(['code' => 3001, 'message' => 'Meeting does not exist.'], 404);
            }
            if ($o['meeting_get'] ?? null) { // error de Zoom al consultar (con tokens en la respuesta, que nunca deben mostrarse)
                return Http::response(['code' => 3000, 'message' => 'Request blocked for token zak=LEAKZAK'], (int) $o['meeting_get']);
            }

            return Http::response(['id' => (int) $mm[1], 'host_id' => $o['meeting_host'] ?? ($t->hosts[$mm[1]] ?? 'HOSTINST'), 'type' => 3,
                'status' => in_array($mm[1], $o['started'] ?? [], true) ? 'started' : 'waiting', 'password' => 'abc'.$k,
                'join_url' => 'https://us02web.zoom.us/j/'.$mm[1].'?pwd=abc'.$k, 'start_url' => $o['start_url'] ?? 'https://us02web.zoom.us/s/'.$mm[1].'?zak=SECRETZAK'.$mm[1],
                'settings' => $o['meeting_settings'] ?? ($t->settings[$mm[1]] ?? ['join_before_host' => false, 'waiting_room' => true])]);
        }

        return Http::response(['unexpected' => $url], 599);
    });
}

/** @return array{inst:Institution,admin:User,advisor:User} */
function vcInst(string $advisorName = 'Ana García'): array
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);
    $admin = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin', 'status' => 'active']);
    $advisor = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admissions', 'department' => 'Admisiones', 'status' => 'active', 'name' => $advisorName]);
    vcProfile($advisor);

    return ['inst' => $inst, 'admin' => $admin, 'advisor' => $advisor];
}

function vcProfile(User $u, array $over = []): MeetingProfile
{
    $p = MeetingProfile::query()->create(array_merge(['user_id' => $u->id, 'timezone' => 'America/Bogota'], $over));
    foreach ([1, 2, 3, 4, 5] as $wd) {
        \Modules\Crm\Models\AvailabilityRule::query()->create(['user_id' => $u->id, 'weekday' => $wd, 'start_time' => '09:00:00', 'end_time' => '12:00:00']);
    }

    return $p;
}

function vcGoogle(string $status = 'active'): Integration
{
    $i = new Integration;
    $i->type = 'google_meet';
    $i->name = 'Google Meet';
    $i->status = $status;
    $i->replaceSecrets(['client_id' => 'cid.apps.googleusercontent.com', 'client_secret' => 'GOCSPX-secret', 'refresh_token' => 'rt-OLD',
        'access_token' => 'ya29.OLD', 'access_token_expires_at' => time() + 3000, 'account_email' => 'salas@mca.test']);
    $i->save();

    return $i;
}

function vcZoom(int $concurrency = 1): Integration
{
    $i = new Integration;
    $i->type = 'zoom';
    $i->name = 'Zoom';
    $i->status = 'active';
    $i->replaceSecrets(['account_id' => 'ACC1', 'client_id' => 'zcid', 'client_secret' => 'zsecret', 'host_user_id' => 'HOSTINST',
        'access_token' => 'zoom-OLD', 'access_token_expires_at' => time() + 3000, 'concurrency' => $concurrency]);
    $i->save();

    return $i;
}

function vcRooms(): VideoRoomService
{
    return app(VideoRoomService::class);
}

function vcCount(string $needle, bool $exact = false): int
{
    return count(array_filter($GLOBALS['vc']->calls, fn (string $c) => $exact ? $c === $needle : str_contains($c, $needle)));
}

/** @param  array{inst:Institution,profile:MeetingProfile,type:MeetingType}  $s */
function vcPost(array $s, array $over = []): \Illuminate\Testing\TestResponse
{
    return test()->post(route('booking.store', [$s['inst']->slug, $s['profile']->booking_slug, $s['type']->slug]), array_merge([
        'start' => '2026-10-19T15:00:00Z', 'timezone' => 'Europe/Madrid', 'first_name' => 'Laura', 'last_name' => 'Pérez',
        'email' => 'laura@example.org', 'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        'form_token' => \Illuminate\Support\Facades\Crypt::encryptString((string) (now()->getTimestamp() - 10)), 'website' => '', 'lang' => 'es',
    ], $over));
}

/** @param  array<string,mixed>  $over */
function vcActivity(User $u, string $medium, string $start, array $over = [], bool $confirm = false): Activity
{
    return app(ActivityService::class)->create(array_merge([
        'title' => 'Orientación', 'type' => 'video_call', 'medium' => $medium,
        'starts_at' => Carbon::parse($start, 'UTC'), 'ends_at' => Carbon::parse($start, 'UTC')->addMinutes(30),
        'timezone' => 'UTC', 'assigned_to_user_id' => $u->id,
    ], $over), $u, $confirm);
}

// 1 · Conexión y desconexión ----------------------------------------------------------------------

it('conecta Google Meet con OAuth (state de un solo uso + PKCE S256), guarda tokens cifrados y desconecta revocando', function () {
    ['admin' => $admin] = vcInst();
    vcFake();
    config(['auth.two_factor.exempt_emails' => [mb_strtolower($admin->email)]]);
    Livewire::actingAs($admin)->test(Connections::class)
        ->set('googleClientId', 'cid.apps.googleusercontent.com')->set('googleClientSecret', 'GOCSPX-secret')
        ->call('saveGoogle')->assertHasNoErrors();
    $i = Integration::query()->where('type', 'google_meet')->sole();
    expect($i->status)->toBe('pending')->and(json_encode($i->config_preview))->not->toContain('GOCSPX-secret');

    $start = $this->actingAs($admin)->get(route('integrations.video.google.connect'))->assertRedirect();
    $location = (string) $start->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
    expect($location)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth')
        ->and($q['code_challenge_method'])->toBe('S256')->and($q['access_type'])->toBe('offline')
        ->and($q['scope'])->toBe('openid email https://www.googleapis.com/auth/meetings.space.created')
        ->and($q)->not->toHaveKey('client_secret');

    // state manipulado → rechazado y se consume (un solo uso)
    $this->get(route('integrations.video.google.callback', ['state' => 'otro', 'code' => 'c']))->assertRedirect(route('integrations.video'));
    expect($i->fresh()->status)->toBe('pending');
    $this->get(route('integrations.video.google.callback', ['state' => $q['state'], 'code' => 'c']))->assertRedirect();
    expect($i->fresh()->status)->toBe('pending'); // el state ya se consumió

    // flujo correcto
    $start = $this->get(route('integrations.video.google.connect'));
    parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $q);
    $this->get(route('integrations.video.google.callback', ['state' => $q['state'], 'code' => 'auth-code']))
        ->assertRedirect(route('integrations.video'))->assertSessionHas('video_status');
    $i = $i->fresh();
    expect($i->status)->toBe('active')->and($i->secret('refresh_token'))->toBe('rt-NEW')->and($i->secret('account_email'))->toBe('salas@mca.test');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/token') && $r['grant_type'] === 'authorization_code' && strlen((string) $r['code_verifier']) >= 43);
    $raw = (string) DB::table('integrations')->where('id', $i->id)->value('config');
    expect($raw)->not->toContain('rt-NEW')->not->toContain('ya29');

    Livewire::actingAs($admin)->test(Connections::class)->assertSee('salas@mca.test')->assertDontSee('rt-NEW')->assertDontSee('GOCSPX-secret')
        ->call('disconnectGoogle');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com/revoke'));
    expect($i->fresh()->status)->toBe('inactive')->and($i->fresh()->secret('refresh_token'))->toBeNull()->and($i->fresh()->secret('client_id'))->not->toBeNull();
});

it('conecta y prueba Zoom con Server-to-Server OAuth, limita la capacidad y desconecta sin dejar el secreto', function () {
    ['admin' => $admin] = vcInst();
    vcFake();
    Livewire::actingAs($admin)->test(Connections::class)
        ->set('zoomAccountId', 'ACC1')->set('zoomClientId', 'zcid')->set('zoomClientSecret', 'zsecret')->set('zoomConcurrency', 3)
        ->call('saveZoom')->assertHasErrors(['zoomConcurrency'])
        ->set('zoomConcurrency', 2)->call('saveZoom')->assertHasNoErrors()->assertSee('host@mca.test');
    $i = Integration::query()->where('type', 'zoom')->sole();
    expect($i->status)->toBe('active')->and($i->secret('host_user_id'))->toBe('HOSTINST')->and((int) $i->secret('concurrency'))->toBe(2);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'zoom.us/oauth/token') && $r['grant_type'] === 'account_credentials'
        && $r->hasHeader('Authorization', 'Basic '.base64_encode('zcid:zsecret')));

    Livewire::actingAs($admin)->test(Connections::class)->call('disconnectZoom');
    expect($i->fresh()->status)->toBe('inactive')->and($i->fresh()->secret('client_secret'))->toBeNull()->and($i->fresh()->secret('access_token'))->toBeNull();
});

// 2 · Aislamiento ---------------------------------------------------------------------------------

it('aísla conexiones y salas por institución', function () {
    ['admin' => $adminA, 'advisor' => $advA] = vcInst();
    vcFake();
    vcGoogle();
    $room = vcRooms()->generate($advA, 'google_meet', $adminA);

    ['admin' => $adminB, 'advisor' => $advB] = vcInst('Beto');
    expect(Integration::query()->where('type', 'google_meet')->exists())->toBeFalse()
        ->and(VideoRoom::query()->count())->toBe(0);
    expect(fn () => vcRooms()->generate($advB, 'google_meet', $adminB))->toThrow(VideoProviderException::class);
    // Un asesor de otra institución no es asesor válido aquí, aunque A esté conectada.
    vcGoogle();
    expect(fn () => vcRooms()->generate($advA, 'google_meet', $adminB))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect(fn () => Livewire::actingAs($adminB)->test(VideoRooms::class, ['ownerId' => $advB->id])->call('checkRoom', $room->id))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

// 3 · Google Meet: generación y reutilización -----------------------------------------------------

it('genera la sala de Meet una vez, asigna coanfitrión, la reutiliza y regenera solo a petición', function () {
    ['admin' => $admin, 'advisor' => $adv] = vcInst();
    vcFake();
    vcGoogle();

    $room = vcRooms()->generate($adv, 'google_meet', $admin);
    expect($room->remote_id)->toBe('spaces/sp1')->and($room->join_url)->toBe('https://meet.google.com/abc-defg-hij')
        ->and($room->host_status)->toBe('assigned')->and($room->institution_id)->toBe($adv->institution_id)
        ->and(MeetingProfile::forUser($adv->id)->meet_url)->toBe('https://meet.google.com/abc-defg-hij');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/members') && $r->method() === 'POST' && $r['role'] === 'COHOST' && $r['email'] === strtolower($adv->email));

    $again = vcRooms()->generate($adv, 'google_meet', $admin);
    expect($again->id)->toBe($room->id)->and(vcCount('POST https://meet.googleapis.com/v2/spaces', true))->toBe(1);

    $new = vcRooms()->generate($adv, 'google_meet', $admin, true);
    expect($new->id)->not->toBe($room->id)->and($room->fresh()->status)->toBe('replaced')
        ->and(MeetingProfile::forUser($adv->id)->meet_url)->toBe('https://meet.google.com/kkk-llll-mmm');
});

it('si Google no permite el coanfitrión lo indica sin bloquear la sala; una sala borrada se detecta', function () {
    ['admin' => $admin, 'advisor' => $adv] = vcInst();
    vcFake(['cohost' => 'denied']);
    vcGoogle();
    $room = vcRooms()->generate($adv, 'google_meet', $admin);
    expect($room->status)->toBe('active')->and($room->host_status)->toBe('failed')->and($room->host_note)->toContain('coanfitrión');

    vcFake(['space_missing' => true]);
    expect(vcRooms()->check($room, $admin)->status)->toBe('missing');
});

// 4 · Zoom: generación y reutilización ------------------------------------------------------------

it('asesor con licencia: es el anfitrión real de su reunión sin hora fija (sala de espera, sin unirse antes, nunca la PMI) y no se guarda el enlace de inicio', function () {
    ['admin' => $admin, 'advisor' => $adv] = vcInst();
    vcLicense($adv);
    vcFake();
    vcZoom();
    $room = vcRooms()->generate($adv, 'zoom', $admin);
    expect($room->join_url)->toBe('https://us02web.zoom.us/j/81234567891?pwd=abc1')->and($room->host_key)->toBe('zoom:'.vcZoomId($adv))
        ->and($room->status)->toBe('active')->and($room->host_status)->toBe('advisor_host')->and($room->advisorCanHost())->toBeTrue()
        ->and($room->host_note)->toContain('sala de espera')
        ->and(MeetingProfile::forUser($adv->id)->zoom_url)->toBe($room->join_url);
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/users/'.vcZoomId($adv).'/meetings')
        && $r['type'] === 3 && ! isset($r['settings']['use_pmi']) && ! isset($r['settings']['jbh_time'])
        && $r['settings']['waiting_room'] === true && $r['settings']['join_before_host'] === false && str_contains((string) $r['agenda'], 'crm-room-'));
    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/users/HOSTINST/meetings'));
    $everything = json_encode(VideoRoom::query()->get()->map->getAttributes()).json_encode(VideoRoom::query()->get()->pluck('join_url'));
    expect($everything)->not->toContain('zak')->not->toContain('/s/');

    // Reutilización: la revisión confirma que sigue siendo su anfitrión y no crea otra
    expect(vcRooms()->generate($adv, 'zoom', $admin)->id)->toBe($room->id)->and(vcCount('POST https://api.zoom.us'))->toBe(1)
        ->and($room->fresh()->status)->toBe('active');
});

it('sin licencia individual: cada usuario recibe su propia reunión del anfitrión institucional (id y enlace distintos), reutilizada y sin privilegios de anfitrión', function () {
    ['admin' => $admin, 'advisor' => $adv] = vcInst();
    $other = User::factory()->create(['institution_id' => $adv->institution_id, 'role' => 'admissions', 'department' => 'Admisiones', 'status' => 'active']);
    vcProfile($other);
    vcFake(['basic' => [strtolower($adv->email)]]); // uno es Basic en la cuenta; el otro ni siquiera es usuario de Zoom
    vcZoom();

    $a = vcRooms()->generate($adv, 'zoom', $admin);
    $b = vcRooms()->generate($other, 'zoom', $admin);
    expect($a->status)->toBe('active')->and($a->host_status)->toBe('institutional_host')->and($a->advisorCanHost())->toBeFalse()
        ->and($a->host_key)->toBe('zoom:HOSTINST')->and($a->host_note)->toContain('Anfitrión técnico')->toContain('sala de espera')->and($a->secure)->toBeTrue()
        ->and($b->remote_id)->not->toBe($a->remote_id)->and($b->join_url)->not->toBe($a->join_url)
        ->and(MeetingProfile::forUser($adv->id)->zoom_url)->toBe($a->join_url)
        ->and(MeetingProfile::forUser($other->id)->zoom_url)->toBe($b->join_url);
    // Creadas con el anfitrión institucional, sala de espera y sin «unirse antes» (modalidad por defecto).
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/users/HOSTINST/meetings')
        && $r['type'] === 3 && $r['default_password'] === true && $r['settings']['waiting_room'] === true && $r['settings']['join_before_host'] === false);
    expect(vcCount('POST https://api.zoom.us'))->toBe(2);

    // Reutilización: la sala válida no se vuelve a crear.
    expect(vcRooms()->generate($adv, 'zoom', $admin)->id)->toBe($a->id)->and(vcCount('POST https://api.zoom.us'))->toBe(2);

    // Integración con el Calendario: la actividad nueva copia el enlace del usuario y cuenta para el anfitrión institucional.
    $act = vcActivity($adv, 'zoom', '2026-10-19 15:00');
    expect($act->meeting_link)->toBe($a->join_url)->and($act->video_host_key)->toBe('zoom:HOSTINST');

    // En el perfil se ve que no dirige la reunión (sin atribuirle privilegios).
    Livewire::actingAs($adv)->test(VideoRooms::class, ['ownerId' => $adv->id])
        ->assertSee('Mi sala de Zoom')->assertSee('Identidad técnica institucional (sin licencia individual)')->assertSee('Copiar enlace para invitados');
});

it('con un enlace manual en el perfil crea la sala sin sustituirlo hasta que un administrador lo decida', function () {
    ['admin' => $admin, 'advisor' => $adv] = vcInst();
    $manual = 'https://us02web.zoom.us/j/99999999999?pwd=manual';
    MeetingProfile::forUser($adv->id)->forceFill(['zoom_url' => $manual])->save();
    vcFake();
    vcZoom();
    $room = vcRooms()->generate($adv, 'zoom', $admin);
    expect($room->status)->toBe('active')->and(MeetingProfile::forUser($adv->id)->zoom_url)->toBe($manual);
    vcRooms()->assign($room, $admin);
    expect(MeetingProfile::forUser($adv->id)->zoom_url)->toBe($room->join_url);
});

it('una sala heredada del anfitrión institucional sigue siendo válida; con licencia se regenera como propia y al perderla deja de serlo', function () {
    ['admin' => $admin, 'advisor' => $adv] = vcInst();
    vcZoom();
    $legacy = 'https://us02web.zoom.us/j/81234567895?pwd=abc5';
    $room = VideoRoom::query()->create(['user_id' => $adv->id, 'provider' => 'zoom', 'remote_id' => '81234567895', 'join_url' => $legacy,
        'join_url_hash' => Activity::roomHash($legacy), 'host_key' => 'zoom:HOSTINST', 'status' => 'error', 'host_status' => 'not_host']);
    MeetingProfile::forUser($adv->id)->forceFill(['zoom_url' => $legacy])->save();
    vcFake(['meeting_host' => 'HOSTINST']);

    $checked = vcRooms()->check($room, $admin);
    expect($checked->status)->toBe('active')->and($checked->host_status)->toBe('institutional_host')->and($checked->advisorCanHost())->toBeFalse()
        ->and($checked->last_error)->toBeNull()->and(MeetingProfile::forUser($adv->id)->zoom_url)->toBe($legacy);

    // Una reunión de otra persona (ni del usuario ni del anfitrión institucional) no es operativa.
    vcFake(['meeting_host' => 'OTRAPERSONA']);
    expect(vcRooms()->check($room, $admin)->status)->toBe('error')->and($room->fresh()->host_status)->toBe('not_host');

    // Con licencia, la regeneración crea SU reunión (anfitrión real) y reemplaza la heredada.
    vcLicense($adv);
    vcFake();
    $new = vcRooms()->generate($adv, 'zoom', $admin, true);
    expect($new->host_status)->toBe('advisor_host')->and($new->status)->toBe('active')->and($room->fresh()->status)->toBe('replaced')
        ->and(MeetingProfile::forUser($adv->id)->zoom_url)->toBe($new->join_url);

    // Si pierde la licencia, su reunión deja de considerarse operativa (no se le siguen atribuyendo privilegios).
    $GLOBALS['vc']->licensed = [];
    expect(vcRooms()->check($new, $admin)->status)->toBe('error');
});

it('modalidad segura: las salas se crean con sala de espera, sin «unirse antes» y con contraseña; las existentes se protegen sin cambiar su enlace', function () {
    ['admin' => $admin, 'advisor' => $adv] = vcInst();
    $licensed = User::factory()->create(['institution_id' => $adv->institution_id, 'role' => 'admissions', 'department' => 'Admisiones', 'status' => 'active']);
    vcProfile($licensed);
    vcLicense($licensed);
    vcFake();
    vcZoom();
    $own = vcRooms()->generate($licensed, 'zoom', $admin);
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/meetings') && $r['default_password'] === true
        && $r['settings']['waiting_room'] === true && $r['settings']['join_before_host'] === false && $r['settings']['mute_upon_entry'] === true
        && ! isset($r['settings']['jbh_time']));
    expect($own->secure)->toBeTrue();

    // Sala institucional heredada, sin verificar y con «unirse antes» activado en Zoom.
    $legacy = 'https://us02web.zoom.us/j/81234567895?pwd=abc5';
    $old = VideoRoom::query()->create(['user_id' => $adv->id, 'provider' => 'zoom', 'remote_id' => '81234567895', 'join_url' => $legacy,
        'join_url_hash' => Activity::roomHash($legacy), 'host_key' => 'zoom:HOSTINST', 'status' => 'active', 'host_status' => 'institutional_host']);
    $GLOBALS['vc']->settings['81234567895'] = ['join_before_host' => true, 'waiting_room' => false];
    expect(vcRooms()->check($old, $admin)->secure)->toBeFalse()->and($old->fresh()->host_note)->toContain('no mantiene la modalidad segura');

    $c = Livewire::actingAs($admin)->test(Connections::class)->assertSee('Seguridad de las reuniones de Zoom')->assertSee('Sin proteger')
        ->assertDontSee('Acceso sin anfitrión')
        ->call('secureZoomRooms')->assertSee('Salas protegidas: 2');
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/meetings/81234567895')
        && $r['settings'] === ['waiting_room' => true, 'join_before_host' => false]);
    expect($old->fresh()->secure)->toBeTrue()->and($old->fresh()->join_url)->toBe($legacy)->and($old->fresh()->host_key)->toBe('zoom:HOSTINST')
        ->and(vcCount('POST https://api.zoom.us'))->toBe(1) // no se regenera nada
        ->and(\Modules\Audit\Models\AuditLog::query()->where('action', 'video_room.secured')->exists())->toBeTrue();

    // Si la cuenta de Zoom no mantiene la sala de espera, se informa y la sala no se presenta como protegida.
    vcFake(['account_no_waiting_room' => true]);
    $c->call('secureZoomRooms')->assertSee('Sin proteger:')->assertSet('videoNoticeError', true);
    expect($old->fresh()->secure)->toBeFalse();
});

it('salas para todos los usuarios activos con acceso al Calendario (no solo asesores), respetando permisos e institución', function () {
    ['admin' => $admin, 'advisor' => $adv] = vcInst();
    $marketing = User::factory()->create(['institution_id' => $adv->institution_id, 'role' => 'marketing', 'status' => 'active', 'name' => 'Marta Marketing']);
    $inactive = User::factory()->create(['institution_id' => $adv->institution_id, 'role' => 'admissions', 'status' => 'inactive', 'name' => 'Ignacio Inactivo']);
    vcFake();
    vcZoom();

    Livewire::actingAs($admin)->test(Connections::class)
        ->assertSee('Salas permanentes por usuario')->assertSee('Marta Marketing')->assertDontSee('Ignacio Inactivo')
        ->call('generateRoom', $marketing->id, 'zoom')
        ->assertSet('videoTarget', $marketing->id.'|zoom')->assertSet('videoNoticeError', false);
    expect(vcRooms()->current($marketing->id, 'zoom')?->status)->toBe('active');

    // Inactivo o de otra institución: no.
    expect(fn () => vcRooms()->generate($inactive, 'zoom', $admin))->toThrow(\Illuminate\Validation\ValidationException::class);
    Livewire::actingAs($admin)->test(Connections::class)->call('generateRoom', $inactive->id, 'zoom')->assertNotFound();
    $foreignInst = Institution::factory()->create();
    $foreign = User::factory()->create(['institution_id' => $foreignInst->id, 'role' => 'admin', 'status' => 'active']);
    Livewire::actingAs($admin)->test(Connections::class)->call('generateRoom', $foreign->id, 'zoom')->assertNotFound();

    // Solo la administración genera salas o decide la modalidad de acceso.
    Livewire::actingAs($adv)->test(Connections::class)->assertForbidden();
    expect(fn () => vcRooms()->secureExistingRooms($adv))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

it('una restricción real de Zoom se comunica con su motivo junto al usuario y su botón, sin crear sala', function () {
    ['admin' => $admin, 'advisor' => $adv] = vcInst();
    vcFake(['zoom_create' => '400']);
    vcZoom();

    $c = Livewire::actingAs($admin)->test(Connections::class)->call('generateRoom', $adv->id, 'zoom')
        ->assertSet('videoTarget', $adv->id.'|zoom')->assertSet('videoNoticeError', true);
    $html = $c->html();
    // El aviso va en la celda del usuario (junto a «Generar sala»), no en la cabecera de la página.
    expect($html)->toMatch('~vc-adv-'.$adv->id.'.*?data-testid="vc-row-notice".*?Meeting hosting and scheduling capabilities are not allowed~s')
        ->not->toContain('support.zoom.us')
        ->and(substr_count($html, 'Respuesta del proveedor'))->toBe(1);
    expect(VideoRoom::query()->count())->toBe(0);

    // Lo mismo en el bloque del perfil de reuniones.
    Livewire::actingAs($admin)->test(VideoRooms::class, ['ownerId' => $adv->id])->call('generateFor', 'zoom')
        ->assertSeeHtml('data-testid="vr-notice"')->assertSee('Meeting hosting and scheduling capabilities');
});

it('si la cuenta no aplica la sala de espera solicitada, se avisa y la sala no se da por protegida', function () {
    ['admin' => $admin, 'advisor' => $adv] = vcInst();
    vcLicense($adv);
    vcFake(['account_no_waiting_room' => true]);
    vcZoom();
    $room = vcRooms()->generate($adv, 'zoom', $admin);
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/meetings') && $r['settings']['waiting_room'] === true);
    expect($room->host_status)->toBe('advisor_host')->and($room->secure)->toBeFalse()->and($room->canStartFromCrm())->toBeFalse()
        ->and($room->host_note)->toContain('no mantiene la modalidad segura');
});

it('tras perder la respuesta de Zoom recupera la reunión creada por su marca (sin duplicar)', function () {
    ['admin' => $admin, 'advisor' => $adv] = vcInst();
    vcLicense($adv);
    vcZoom();
    // 1er intento: la creación falla (500) → no se guarda nada
    vcFake(['zoom_create' => '500']);
    expect(fn () => vcRooms()->generate($adv, 'zoom', $admin))->toThrow(VideoProviderException::class);
    expect(VideoRoom::query()->count())->toBe(0);
    $marker = collect(Http::recorded())->map(fn ($p) => $p[0])->first(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/meetings'))['agenda'];

    // 2º intento: Zoom sí la había creado → se encuentra por la marca y no se vuelve a crear
    $GLOBALS['vc']->calls = [];
    vcFake(['zoom_existing' => [['id' => 81234567897, 'type' => 3, 'topic' => 'Sala', 'agenda' => $marker]], 'meeting_host' => vcZoomId($adv)]);
    $room = vcRooms()->generate($adv, 'zoom', $admin);
    expect($room->remote_id)->toBe('81234567897')->and(vcCount('POST https://api.zoom.us'))->toBe(0);
    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), '/users/'.vcZoomId($adv).'/meetings'));
});

// 5 · Asignación al asesor ------------------------------------------------------------------------

it('conserva el enlace manual hasta que un administrador lo reemplaza', function () {
    ['admin' => $admin, 'advisor' => $adv] = vcInst();
    MeetingProfile::forUser($adv->id)->forceFill(['meet_url' => 'https://meet.google.com/man-ualx-xxx'])->save();
    vcFake();
    vcGoogle();
    $room = vcRooms()->generate($adv, 'google_meet', $admin);
    expect(MeetingProfile::forUser($adv->id)->meet_url)->toBe('https://meet.google.com/man-ualx-xxx')->and(vcRooms()->isProfileLink($room))->toBeFalse();

    Livewire::actingAs($admin)->test(VideoRooms::class, ['ownerId' => $adv->id])
        ->assertSee('enlace manual')->call('assignRoom', $room->id)->assertDispatched('video-room-assigned');
    expect(MeetingProfile::forUser($adv->id)->meet_url)->toBe($room->join_url);
});

// 6 · Enlaces de actividades existentes -----------------------------------------------------------

it('nunca cambia actividades existentes; solo las futuras sin enlace con la operación explícita', function () {
    ['admin' => $admin, 'advisor' => $adv] = vcInst();
    vcFake();
    vcGoogle();
    $room = vcRooms()->generate($adv, 'google_meet', $admin);
    $withLink = vcActivity($adv, 'google_meet', '2026-10-19 15:00');
    expect($withLink->meeting_link)->toBe($room->join_url)->and($withLink->meeting_room_hash)->toBe($room->join_url_hash);

    // Una actividad futura sin enlace (creada cuando el perfil no tenía) y una pasada sin enlace.
    $noLink = vcActivity($adv, 'google_meet', '2026-10-20 15:00');
    $noLink->forceFill(['meeting_link' => null, 'meeting_room_hash' => null])->save();
    ActivityGuest::query()->create(['activity_id' => $noLink->id, 'name' => 'Inv', 'email' => 'inv@example.org', 'language' => 'es', 'status' => 'sent']);
    $past = vcActivity($adv, 'google_meet', '2026-10-21 15:00');
    $past->forceFill(['meeting_link' => null, 'meeting_room_hash' => null, 'starts_at' => Carbon::parse('2026-10-18 15:00', 'UTC'), 'ends_at' => Carbon::parse('2026-10-18 15:30', 'UTC')])->save();

    $new = vcRooms()->generate($adv, 'google_meet', $admin, true);
    expect($withLink->fresh()->meeting_link)->toBe($room->join_url)        // regenerar no la toca
        ->and($noLink->fresh()->meeting_link)->toBeNull();

    $seq = $noLink->fresh()->ics_sequence;
    $r = vcRooms()->applyToFutureActivities($new, $admin);
    expect($r)->toBe(['updated' => 1, 'skipped' => 0])
        ->and($noLink->fresh()->meeting_link)->toBe($new->join_url)->and($noLink->fresh()->ics_sequence)->toBe($seq + 1)
        ->and($withLink->fresh()->meeting_link)->toBe($room->join_url)
        ->and($past->fresh()->meeting_link)->toBeNull();
});

// 7 · Capacidad simultánea de Zoom ----------------------------------------------------------------

/** Otra reunión del MISMO anfitrión de Zoom que $host, asignada al perfil de $u (comparten capacidad). */
function vcSharedHostRoom(User $u, VideoRoom $host, string $id): VideoRoom
{
    $url = 'https://us02web.zoom.us/j/'.$id.'?pwd=sh';
    MeetingProfile::forUser($u->id)->forceFill(['zoom_url' => $url])->save();

    return VideoRoom::query()->create(['user_id' => $u->id, 'provider' => 'zoom', 'remote_id' => $id, 'join_url' => $url,
        'join_url_hash' => Activity::roomHash($url), 'host_key' => $host->host_key, 'status' => 'active', 'host_status' => 'advisor_host']);
}

it('respeta la capacidad simultánea por anfitrión: cada asesor con licencia tiene la suya y un mismo anfitrión no se excede', function () {
    ['admin' => $admin, 'advisor' => $a] = vcInst();
    $b = User::factory()->create(['institution_id' => $a->institution_id, 'role' => 'admissions', 'department' => 'Admisiones', 'status' => 'active']);
    $c = User::factory()->create(['institution_id' => $a->institution_id, 'role' => 'admissions', 'department' => 'Admisiones', 'status' => 'active']);
    vcProfile($b);
    vcProfile($c);
    vcLicense($a);
    vcLicense($b);
    vcFake();
    vcZoom(1);
    $roomA = vcRooms()->generate($a, 'zoom', $admin);
    $roomB = vcRooms()->generate($b, 'zoom', $admin);
    expect($roomA->host_key)->toBe('zoom:'.vcZoomId($a))->and($roomB->host_key)->toBe('zoom:'.vcZoomId($b));

    $actA = vcActivity($a, 'zoom', '2026-10-19 15:00');
    expect($actA->video_host_key)->toBe($roomA->host_key);
    $slots = fn (User $u, VideoRoom $r) => array_map(fn ($s) => $s->format('H:i'), app(BookingSlots::class)->between($u->id, Carbon::parse('2026-10-19 14:00', 'UTC'), Carbon::parse('2026-10-19 17:00', 'UTC'), 30, $r->join_url_hash));
    expect($slots($b, $roomB))->toContain('15:00'); // anfitriones distintos: capacidades independientes

    // Dos reuniones del mismo anfitrión sí comparten su capacidad.
    $roomC = vcSharedHostRoom($c, $roomA, '81234567800');
    expect($slots($c, $roomC))->not->toContain('15:00')->toContain('14:30')->toContain('15:30');
    expect(app(BookingSlots::class)->offers($c->id, Carbon::parse('2026-10-19 15:00', 'UTC'), 30, null))->toBeTrue(); // Meet: sin capacidad compartida

    // En el Calendario interno, superar la capacidad exige confirmación explícita (queda registrada).
    expect(fn () => vcActivity($c, 'zoom', '2026-10-19 15:00'))->toThrow(ScheduleConflictException::class);
    $forced = vcActivity($c, 'zoom', '2026-10-19 15:00', [], true);
    expect($forced->exception_reason)->toContain('capacidad');
    $forced->forceFill(['status' => 'cancelled'])->save();

    // Capacidad 2 (licencia que lo permite): cabe una segunda simultánea, no una tercera.
    $z = Integration::query()->where('type', 'zoom')->sole();
    $z->replaceSecrets(['concurrency' => 2]);
    $z->save();
    expect($slots($c, $roomC))->toContain('15:00');
    vcActivity($c, 'zoom', '2026-10-19 15:00');
    $d = User::factory()->create(['institution_id' => $a->institution_id, 'role' => 'admissions', 'department' => 'Admisiones', 'status' => 'active']);
    vcProfile($d);
    $roomD = vcSharedHostRoom($d, $roomA, '81234567801');
    expect(app(BookingSlots::class)->offers($d->id, Carbon::parse('2026-10-19 15:00', 'UTC'), 30, $roomD->join_url_hash))->toBeFalse();
});

// 8 · Reservas públicas ---------------------------------------------------------------------------

it('las reservas públicas de Zoom usan la sala del asesor y no superan la capacidad de un mismo anfitrión', function () {
    ['inst' => $inst, 'admin' => $admin, 'advisor' => $a] = vcInst();
    \Modules\Notifications\Models\EmailSender::factory()->create();
    \Modules\Institutions\Models\Bot::factory()->create();
    $b = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admissions', 'department' => 'Admisiones', 'status' => 'active']);
    $c = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admissions', 'department' => 'Admisiones', 'status' => 'active']);
    vcProfile($b);
    vcProfile($c);
    vcLicense($a);
    vcLicense($b);
    vcFake();
    vcZoom(1);
    $roomA = vcRooms()->generate($a, 'zoom', $admin);
    $roomB = vcRooms()->generate($b, 'zoom', $admin);
    vcSharedHostRoom($c, $roomA, '81234567802');
    $s = [];
    foreach (['ana' => $a, 'beto' => $b, 'carla' => $c] as $slug => $u) {
        MeetingProfile::forUser($u->id)->forceFill(['booking_enabled' => true, 'booking_slug' => $slug])->save();
        $s[$slug] = ['inst' => $inst, 'profile' => MeetingProfile::forUser($u->id),
            'type' => MeetingType::query()->create(['user_id' => $u->id, 'name' => 'Consulta Zoom', 'slug' => 'zoom', 'duration_minutes' => 30, 'medium' => 'zoom', 'is_public' => true])];
    }

    vcPost($s['ana'], ['email' => 'uno@example.org'])->assertRedirect();
    $act = Activity::query()->where('assigned_to_user_id', $a->id)->sole();
    expect($act->meeting_link)->toBe($roomA->join_url)->and($act->video_host_key)->toBe($roomA->host_key);

    // Otro asesor con su propia licencia puede a la misma hora.
    vcPost($s['beto'], ['email' => 'dos@example.org'])->assertRedirect();
    expect(Activity::query()->where('assigned_to_user_id', $b->id)->sole()->video_host_key)->toBe($roomB->host_key);

    // Una reunión que depende del mismo anfitrión que A no se confirma a la vez.
    vcPost($s['carla'], ['email' => 'tres@example.org'])->assertSessionHasErrors('start');
    expect(Activity::query()->where('assigned_to_user_id', $c->id)->count())->toBe(0);
    vcPost($s['carla'], ['email' => 'tres@example.org', 'start' => '2026-10-19T15:30:00Z'])->assertRedirect();
    expect(Activity::query()->where('assigned_to_user_id', $c->id)->sole()->starts_at->format('H:i'))->toBe('15:30');
});

// 9 · Credenciales caducadas y errores del proveedor ----------------------------------------------

it('detecta credenciales caducadas o revocadas, respuestas manipuladas y errores, sin secretos en el registro', function () {
    ['admin' => $admin, 'advisor' => $adv] = vcInst();
    Log::spy();
    $g = vcGoogle();
    $g->replaceSecrets(['access_token_expires_at' => time() - 10]);
    $g->save();
    vcFake(['google_refresh' => 'invalid_grant']);
    try {
        vcRooms()->generate($adv, 'google_meet', $admin);
        $this->fail('debía fallar');
    } catch (VideoProviderException $e) {
        expect($e->kind)->toBe('auth')->and($e->userMessage())->toContain('Vuelve a conectar');
    }
    expect($g->fresh()->status)->toBe('expired')->and(VideoRoom::query()->count())->toBe(0);
    Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx) => $msg === 'video.provider_error'
        && ! str_contains(json_encode($ctx), 'rt-OLD') && ! str_contains(json_encode($ctx), 'GOCSPX'));

    // Respuesta manipulada: un enlace de inicio o de otro dominio nunca se acepta.
    vcLicense($adv);
    vcZoom();
    vcFake(['zoom_join' => 'https://evil.example.com/j/123?pwd=x']);
    expect(fn () => vcRooms()->generate($adv, 'zoom', $admin))->toThrow(VideoProviderException::class);
    vcFake(['zoom_join' => 'https://us02web.zoom.us/s/81234567891?zak=SECRETZAK']);
    expect(fn () => vcRooms()->generate($adv, 'zoom', $admin))->toThrow(VideoProviderException::class);
    vcFake(['google_space' => ['name' => '../../etc', 'meetingUri' => 'https://meet.google.com/abc-defg-hij', 'meetingCode' => 'abc-defg-hij']]);
    $g = $g->fresh();
    $g->replaceSecrets(['refresh_token' => 'rt-OK', 'access_token' => 'ya29.OK', 'access_token_expires_at' => time() + 3000]);
    $g->forceFill(['status' => 'active'])->save();
    try {
        vcRooms()->generate($adv, 'google_meet', $admin);
        $this->fail('debía rechazar la respuesta manipulada');
    } catch (VideoProviderException $e) {
        expect($e->kind)->toBe('invalid_response');
    }
    expect(VideoRoom::query()->count())->toBe(0);

    // Zoom con credenciales revocadas → caducada, mensaje claro en el panel.
    vcFake(['zoom_token' => 'invalid']);
    $z = Integration::query()->where('type', 'zoom')->sole();
    $z->replaceSecrets(['access_token_expires_at' => time() - 10]);
    $z->save();
    Livewire::actingAs($admin)->test(Connections::class)->call('testZoom')->assertSee('Vuelve a conectar');
    expect($z->fresh()->status)->toBe('expired')->and($z->fresh()->last_test_ok)->toBeFalse();
});

it('reintenta una lectura ante un error transitorio, pero nunca repite a ciegas una creación', function () {
    ['admin' => $admin, 'advisor' => $adv] = vcInst();
    config(['crm.video.retries' => 1]);
    \Illuminate\Support\Sleep::fake();
    vcLicense($adv);
    vcZoom();
    vcFake(['zoom_create' => '500', 'flaky' => true]);
    expect(fn () => vcRooms()->generate($adv, 'zoom', $admin))->toThrow(VideoProviderException::class);
    expect(vcCount('GET https://api.zoom.us/v2/users/'.rawurlencode(strtolower($adv->email)), true))->toBe(2) // 503 → reintento
        ->and(vcCount('POST https://api.zoom.us'))->toBe(1);
});

// 10 · Permisos -----------------------------------------------------------------------------------

it('solo la administración gestiona conexiones y salas; el asesor ve y abre su sala sin credenciales', function () {
    ['admin' => $admin, 'advisor' => $adv] = vcInst();
    vcLicense($adv);
    vcFake();
    vcGoogle();
    vcZoom();
    vcRooms()->generate($adv, 'google_meet', $admin);
    vcRooms()->generate($adv, 'zoom', $admin);

    Livewire::actingAs($adv)->test(Connections::class)->assertForbidden();
    config(['auth.two_factor.exempt_emails' => [mb_strtolower($adv->email)]]);
    $this->actingAs($adv)->get(route('integrations.video'))->assertForbidden();
    $this->actingAs($adv)->get(route('integrations.video.google.connect'))->assertForbidden();
    expect(fn () => vcRooms()->generate($adv, 'zoom', $adv))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);

    Livewire::actingAs($adv)->test(VideoRooms::class, ['ownerId' => $adv->id])
        ->assertSee('Abrir Google Meet')->assertSee('Mi sala de Zoom')->assertSee('Copiar enlace para invitados')->assertSee('https://meet.google.com/abc-defg-hij')
        ->assertSee('Tú, con tu licencia de Zoom')
        ->assertDontSee('Regenerar')->assertDontSee('zak')->assertDontSee('ya29')
        ->call('generateFor', 'zoom')->assertForbidden();
    $other = User::factory()->create(['institution_id' => $adv->institution_id, 'role' => 'admissions', 'department' => 'Admisiones', 'status' => 'active']);
    Livewire::actingAs($adv)->test(VideoRooms::class, ['ownerId' => $other->id])->assertForbidden();

    Livewire::actingAs($admin)->test(VideoRooms::class, ['ownerId' => $adv->id])->assertSee('Regenerar')->assertSee('Revisar');
    Livewire::actingAs($admin)->test(Connections::class)->assertOk()->assertSee('salas@mca.test')->assertDontSee('GOCSPX-secret')->assertDontSee('zsecret');
});

// 11 · Inicio como anfitrión desde el perfil ------------------------------------------------------

/** Modo de inicio desde el CRM decidido por la administración (off · pilot · all). */
function vcStart(string $mode, ?int $pilot = null): void
{
    $z = Integration::query()->where('type', 'zoom')->sole();
    $z->replaceSecrets(['host_start' => $mode, 'host_start_pilot' => $pilot]);
    $z->save();
}

/** POST del botón «Iniciar mi reunión como anfitrión» como $u (2FA exento solo en pruebas). */
function vcStartAs(User $u, array $data = []): \Illuminate\Testing\TestResponse
{
    config(['auth.two_factor.exempt_emails' => [mb_strtolower($u->email)]]);

    return test()->actingAs($u)->post(route('crm.video.zoom.start'), $data);
}

it('un usuario inicia SU reunión con un enlace de inicio recién pedido a Zoom, sin crear otra ni cambiar el enlace de invitados', function () {
    ['admin' => $admin, 'advisor' => $adv] = vcInst();
    $logged = [];
    \Illuminate\Support\Facades\Event::listen(\Illuminate\Log\Events\MessageLogged::class, function ($e) use (&$logged) {
        $logged[] = $e->message.json_encode($e->context);
    });
    vcFake();
    vcZoom();
    $room = vcRooms()->generate($adv, 'zoom', $admin);
    vcStart('all');

    $res = vcStartAs($adv);
    $res->assertStatus(303)->assertRedirect('https://us02web.zoom.us/s/'.$room->remote_id.'?zak=SECRETZAK'.$room->remote_id)
        ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('Pragma', 'no-cache');
    expect($res->headers->get('Cache-Control'))->toContain('no-store');
    vcStartAs($adv)->assertStatus(303); // segundo clic: misma reunión

    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_ends_with($r->url(), '/meetings/'.$room->remote_id));
    expect(vcCount('POST https://api.zoom.us'))->toBe(1) // solo la creación inicial
        ->and($room->fresh()->join_url)->toBe($room->join_url)->and(MeetingProfile::forUser($adv->id)->zoom_url)->toBe($room->join_url)
        ->and(\Modules\Audit\Models\AuditLog::query()->where('action', 'video_room.host_started')->count())->toBe(2);

    // El enlace de inicio no queda en ninguna parte: ni en la base de datos ni en registros ni en la página.
    $dump = '';
    foreach (['crm_video_rooms', 'audit_logs', 'crm_meeting_profiles', 'crm_activities', 'sessions'] as $table) {
        $dump .= json_encode(DB::table($table)->get());
    }
    $dump .= json_encode(VideoRoom::query()->get()->map->getAttributes()).json_encode(Integration::query()->get()->map(fn ($i) => $i->getAttribute('config')));
    expect($dump)->not->toContain('SECRETZAK')->not->toContain('/s/'.$room->remote_id);
    expect(implode('
', $logged))->not->toContain('SECRETZAK')->not->toContain('/s/'.$room->remote_id);
    Livewire::actingAs($adv)->test(VideoRooms::class, ['ownerId' => $adv->id])
        ->assertSee('Iniciar mi reunión como anfitrión')->assertSeeHtml('data-testid="zoom-start-form"')
        ->assertDontSee('SECRETZAK')->assertDontSee('/s/'.$room->remote_id);
});

it('las reuniones de usuarios con licencia también se inician desde el CRM con su anfitrión real', function () {
    ['admin' => $admin, 'advisor' => $adv] = vcInst();
    vcLicense($adv);
    vcFake();
    vcZoom();
    $room = vcRooms()->generate($adv, 'zoom', $admin);
    vcStart('all');
    expect($room->host_status)->toBe('advisor_host');
    vcStartAs($adv)->assertStatus(303)->assertRedirect('https://us02web.zoom.us/s/'.$room->remote_id.'?zak=SECRETZAK'.$room->remote_id);

    // Sin licencia ya no coincide su identidad: no se inicia (nunca se le atribuye otra).
    $GLOBALS['vc']->licensed = [];
    vcStartAs($adv)->assertRedirect(route('crm.agenda.profile'))->assertSessionHas('zoom_start_error', fn ($m) => str_contains($m, 'ya no coincide con tu sala'));
});

it('nadie inicia la reunión de otra persona: ni otro usuario ni la administración, ni cambiando ids ni desde otra institución', function () {
    ['admin' => $admin, 'advisor' => $adv] = vcInst();
    $other = User::factory()->create(['institution_id' => $adv->institution_id, 'role' => 'admissions', 'department' => 'Admisiones', 'status' => 'active']);
    vcProfile($other);
    vcFake();
    vcZoom();
    $room = vcRooms()->generate($adv, 'zoom', $admin);
    vcStart('all');

    // El POST no acepta ids: con o sin ellos, siempre es la sala de quien lo pide.
    foreach ([$other, $admin] as $u) {
        vcStartAs($u, ['user_id' => $adv->id, 'room_id' => $room->id, 'ownerId' => $adv->id])
            ->assertRedirect(route('crm.agenda.profile'))->assertSessionHas('zoom_start_error', fn ($m) => str_contains($m, 'Aún no tienes sala'));
    }
    Http::assertNotSent(fn (Request $r) => $r->method() === 'GET' && str_ends_with($r->url(), '/meetings/'.$room->remote_id));

    // Otra institución con su propia conexión: no ve la sala ni la conexión de la primera.
    $instB = Institution::factory()->create();
    app(CurrentInstitution::class)->set($instB->id);
    $foreign = User::factory()->create(['institution_id' => $instB->id, 'role' => 'admin', 'status' => 'active']);
    vcProfile($foreign);
    vcStartAs($foreign)->assertRedirect(route('crm.agenda.profile'))->assertSessionHas('zoom_start_error', fn ($m) => str_contains($m, 'no está activa'));

    // La administración ve el bloque de otra persona, pero sin el botón de inicio.
    app(CurrentInstitution::class)->set($adv->institution_id);
    Livewire::actingAs($admin)->test(VideoRooms::class, ['ownerId' => $adv->id])
        ->assertSee('Revisar')->assertDontSee('Iniciar mi reunión como anfitrión')->assertDontSee('Mi sala de Zoom');

    // Un usuario sin acceso al perfil de reuniones (solo lectura) tampoco puede iniciar.
    $mkt = User::factory()->create(['institution_id' => $adv->institution_id, 'role' => 'marketing', 'status' => 'active']);
    vcStartAs($mkt)->assertRedirect(route('crm.agenda.profile'))->assertSessionHas('zoom_start_error', fn ($m) => str_contains($m, 'no tiene acceso'));
});

it('solo se habilita tras la decisión de la administración: desactivado, piloto con un usuario o todos', function () {
    ['admin' => $admin, 'advisor' => $adv] = vcInst();
    $pilot = User::factory()->create(['institution_id' => $adv->institution_id, 'role' => 'admissions', 'department' => 'Admisiones', 'status' => 'active']);
    vcProfile($pilot);
    vcFake();
    vcZoom();
    vcRooms()->generate($adv, 'zoom', $admin);
    vcRooms()->generate($pilot, 'zoom', $admin);

    // Por defecto, desactivado (hasta la prueba piloto real).
    vcStartAs($adv)->assertSessionHas('zoom_start_error', fn ($m) => str_contains($m, 'aún no ha habilitado'));
    Livewire::actingAs($adv)->test(VideoRooms::class, ['ownerId' => $adv->id])
        ->assertSee('La administración aún no ha habilitado el inicio')->assertDontSeeHtml('data-testid="zoom-start-form"');

    Livewire::actingAs($admin)->test(Connections::class)->set('hostStartMode', 'pilot')->call('saveHostStart')->assertHasErrors('hostStartPilot')
        ->set('hostStartPilot', $pilot->id)->call('saveHostStart')->assertHasNoErrors()->assertSee('solo para el usuario de la prueba piloto');
    vcStartAs($pilot)->assertStatus(303);
    vcStartAs($adv)->assertSessionHas('zoom_start_error', fn ($m) => str_contains($m, 'aún no ha habilitado'));

    Livewire::actingAs($admin)->test(Connections::class)->set('hostStartMode', 'all')->call('saveHostStart');
    vcStartAs($adv)->assertStatus(303);
    expect(\Modules\Audit\Models\AuditLog::query()->where('action', 'video_room.host_start_mode_changed')->count())->toBe(2);
    expect(fn () => vcRooms()->setHostStart('all', null, $adv))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

it('sin sala, con la reunión borrada, con conexión caducada o con un enlace de inicio no legítimo, no se inicia y se explica', function () {
    ['admin' => $admin, 'advisor' => $adv] = vcInst();
    vcFake();
    $zoom = vcZoom();
    vcStart('all');
    vcStartAs($adv)->assertSessionHas('zoom_start_error', fn ($m) => str_contains($m, 'La administración debe generarla'));
    Livewire::actingAs($adv)->test(VideoRooms::class, ['ownerId' => $adv->id])->assertSee('Aún no tienes sala de Zoom. La administración debe generarla');

    $room = vcRooms()->generate($adv, 'zoom', $admin);

    // Enlace de inicio de otro dominio o de otra reunión: rechazado, sin redirigir.
    foreach (['https://evil.example.com/s/'.$room->remote_id.'?zak=X', 'https://us02web.zoom.us/s/99999999999?zak=X', 'http://us02web.zoom.us/s/'.$room->remote_id.'?zak=X', 'https://us02web.zoom.us/j/'.$room->remote_id] as $bad) {
        vcFake(['start_url' => $bad]);
        vcStartAs($adv)->assertRedirect(route('crm.agenda.profile'))->assertSessionHas('zoom_start_error', fn ($m) => str_contains($m, 'respuesta no válida'));
    }

    // Reunión borrada en Zoom: la sala queda «no existe» y se pide regenerarla.
    vcFake(['meeting_missing' => true]);
    vcStartAs($adv)->assertSessionHas('zoom_start_error', fn ($m) => str_contains($m, 'ya no existe en Zoom'));
    expect($room->fresh()->status)->toBe('missing');

    // Conexión caducada: no se inicia.
    $room->forceFill(['status' => 'active'])->save();
    vcFake();
    $zoom->forceFill(['status' => 'expired'])->save();
    vcStartAs($adv)->assertSessionHas('zoom_start_error', fn ($m) => str_contains($m, 'no está activa'));
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'zoom.us/oauth/token'));
});

it('no se inicia si Zoom no mantiene la sala de espera, ni si el anfitrión ya agotó su capacidad simultánea', function () {
    ['admin' => $admin, 'advisor' => $adv] = vcInst();
    $other = User::factory()->create(['institution_id' => $adv->institution_id, 'role' => 'admissions', 'department' => 'Admisiones', 'status' => 'active']);
    vcProfile($other);
    vcFake();
    vcZoom(1);
    $room = vcRooms()->generate($adv, 'zoom', $admin);
    $roomB = vcRooms()->generate($other, 'zoom', $admin);
    vcStart('all');

    // Comprobación en el momento: «unirse antes» activado → no se inicia y la sala deja de estar protegida.
    vcFake(['meeting_settings' => ['join_before_host' => true, 'waiting_room' => false]]);
    vcStartAs($adv)->assertSessionHas('zoom_start_error', fn ($m) => str_contains($m, 'no se inicia hasta que la administración aplique la configuración segura'));
    expect($room->fresh()->secure)->toBeFalse();
    Livewire::actingAs($adv)->test(VideoRooms::class, ['ownerId' => $adv->id])->assertDontSeeHtml('data-testid="zoom-start-form"')->assertSee('Sin proteger');
    $room->fresh()->forceFill(['secure' => true])->save();

    // El anfitrión institucional ya tiene otra reunión en curso (la de otro usuario) y la capacidad es 1.
    vcFake(['live' => [$roomB->remote_id]]);
    vcStartAs($adv)->assertSessionHas('zoom_start_error', fn ($m) => str_contains($m, 'ya tiene 1 reunión en curso') && str_contains($m, 'comparten ese límite'));
    // Si SU reunión ya está en curso, puede volver a entrar como anfitrión.
    vcFake(['live' => [$roomB->remote_id, $room->remote_id], 'started' => [$room->remote_id]]);
    vcStartAs($adv)->assertStatus(303);
    // Con capacidad 2 (plan que lo permite) sí cabe.
    vcZoomCapacity(2);
    vcFake(['live' => [$roomB->remote_id]]);
    vcStartAs($adv)->assertStatus(303);
});

/** Cambia la capacidad simultánea configurada de Zoom. */
function vcZoomCapacity(int $n): void
{
    $z = Integration::query()->where('type', 'zoom')->sole();
    $z->replaceSecrets(['concurrency' => $n]);
    $z->save();
}

it('los errores de Zoom al iniciar se muestran junto al botón sin exponer tokens', function () {
    ['admin' => $admin, 'advisor' => $adv] = vcInst();
    vcFake();
    vcZoom();
    vcRooms()->generate($adv, 'zoom', $admin);
    vcStart('all');

    vcFake(['meeting_get' => 429]);
    $res = vcStartAs($adv)->assertRedirect(route('crm.agenda.profile'));
    $msg = (string) session('zoom_start_error');
    expect($msg)->toContain('limitó temporalmente')->not->toContain('LEAKZAK')->not->toContain('zak=');
    $this->get(route('crm.agenda.profile'))->assertOk()->assertSee('limitó temporalmente')->assertSeeHtml('data-testid="zoom-start-error"')->assertDontSee('LEAKZAK');

    vcFake(['meeting_get' => 400]);
    vcStartAs($adv);
    expect((string) session('zoom_start_error'))->toContain('rechazó')->not->toContain('LEAKZAK');
});
