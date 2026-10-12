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
    $GLOBALS['vc'] = (object) ['calls' => [], 'faked' => false, 'opts' => [], 'n' => 0, 'licensed' => [], 'hosts' => []];
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
        if ($is('/meetings') && $m === 'GET' && $is('/users/')) {
            return Http::response(['meetings' => $o['zoom_existing'] ?? [], 'next_page_token' => '']);
        }
        if ($is('/meetings') && $m === 'POST') {
            if (($o['zoom_create'] ?? 'ok') === '500') {
                return Http::response(['message' => 'internal'], 500);
            }
            $n = ++$t->n;
            $host = (string) preg_replace('~.*/users/([^/]+)/meetings.*~', '$1', $url);
            $join = $o['zoom_join'] ?? 'https://us02web.zoom.us/j/8123456789'.$n.'?pwd=abc'.$n;
            $t->hosts['8123456789'.$n] = $host;
            // Una cuenta que bloquea la sala de espera la devuelve desactivada aunque se pida.
            $waiting = ($o['account_no_waiting_room'] ?? false) ? false : $r['settings']['waiting_room'];

            return Http::response(['id' => (int) ('8123456789'.$n), 'host_id' => $host, 'type' => 3, 'topic' => $r['topic'], 'agenda' => $r['agenda'],
                'join_url' => $join, 'start_url' => 'https://us02web.zoom.us/s/8123456789'.$n.'?zak=SECRETZAK', 'password' => 'abc'.$n,
                'settings' => ['join_before_host' => $r['settings']['join_before_host'], 'waiting_room' => $waiting]], 201);
        }
        if (preg_match('~api\.zoom\.us/v2/meetings/(\d+)$~', $url, $mm)) {
            $k = substr($mm[1], -1);

            return Http::response(['id' => (int) $mm[1], 'host_id' => $o['meeting_host'] ?? ($t->hosts[$mm[1]] ?? 'HOSTINST'), 'type' => 3,
                'join_url' => 'https://us02web.zoom.us/j/'.$mm[1].'?pwd=abc'.$k, 'start_url' => 'https://us02web.zoom.us/s/x?zak=SECRETZAK',
                'settings' => $o['meeting_settings'] ?? ['join_before_host' => false, 'waiting_room' => true]]);
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

it('asesor sin licencia: no crea ninguna sala (ni con el anfitrión institucional ni con «unirse antes»), lo informa y conserva el enlace manual', function () {
    ['admin' => $admin, 'advisor' => $adv] = vcInst();
    $manual = 'https://us02web.zoom.us/j/99999999999?pwd=manual';
    MeetingProfile::forUser($adv->id)->forceFill(['zoom_url' => $manual])->save();
    vcFake(['basic' => [strtolower($adv->email)]]); // usuario de la cuenta, pero Basic (sin licencia)
    $zoom = vcZoom();

    try {
        vcRooms()->generate($adv, 'zoom', $admin);
        $this->fail('no debía crear la sala');
    } catch (VideoProviderException $e) {
        expect($e->kind)->toBe('advisor_not_host')->and($e->userMessage())->toContain('no podría iniciar ni dirigir')->toContain('enlace manual');
    }
    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/meetings'));
    expect(VideoRoom::query()->count())->toBe(0)
        ->and(MeetingProfile::forUser($adv->id)->zoom_url)->toBe($manual)
        ->and($zoom->fresh()->status)->toBe('active'); // la conexión institucional sigue sana

    // Tampoco si no existe en la cuenta; y desde el perfil se ve el motivo, con el enlace manual utilizable.
    vcFake();
    Livewire::actingAs($admin)->test(VideoRooms::class, ['ownerId' => $adv->id])
        ->call('generateFor', 'zoom')->assertSee('no podría iniciar ni dirigir')
        ->assertSee($manual)->assertSee('Abrir Zoom');
    expect(VideoRoom::query()->count())->toBe(0);
    $act = vcActivity($adv, 'zoom', '2026-10-19 15:00');
    expect($act->meeting_link)->toBe($manual)->and($act->video_host_key)->toBeNull();
});

it('configuración incompatible: una reunión que el asesor no dirige (sala de espera sin anfitrión) no se da por operativa', function () {
    ['admin' => $admin, 'advisor' => $adv] = vcInst();
    vcZoom();
    // Reunión heredada del anfitrión institucional, con sala de espera: el asesor no podría admitir a nadie.
    $legacy = 'https://us02web.zoom.us/j/81234567895?pwd=abc5';
    $room = VideoRoom::query()->create(['user_id' => $adv->id, 'provider' => 'zoom', 'remote_id' => '81234567895', 'join_url' => $legacy,
        'join_url_hash' => Activity::roomHash($legacy), 'host_key' => 'zoom:HOSTINST', 'status' => 'active', 'host_status' => 'institutional_host']);
    MeetingProfile::forUser($adv->id)->forceFill(['zoom_url' => $legacy])->save();
    vcFake(['meeting_host' => 'HOSTINST', 'meeting_settings' => ['join_before_host' => true, 'waiting_room' => true]]);

    $checked = vcRooms()->check($room, $admin);
    expect($checked->status)->toBe('error')->and($checked->host_status)->toBe('not_host')->and($checked->advisorCanHost())->toBeFalse()
        ->and($checked->host_note)->toContain('no lo convierte en anfitrión')->toContain('no se considera operativa')
        ->and(MeetingProfile::forUser($adv->id)->zoom_url)->toBe($legacy); // nada se borra ni se cambia solo
    expect(fn () => vcRooms()->applyToFutureActivities($checked, $admin))->toThrow(\Illuminate\Validation\ValidationException::class);
    Livewire::actingAs($admin)->test(VideoRooms::class, ['ownerId' => $adv->id])
        ->assertSee('No: el asesor no es el anfitrión')->assertSee('no se considera operativa')
        ->assertDontSee('Aplicar a actividades sin enlace')->assertSee('Generar reunión de Zoom');

    // Con licencia, la regeneración crea SU reunión con sala de espera y la asigna en lugar de la heredada.
    vcLicense($adv);
    vcFake();
    $new = vcRooms()->generate($adv, 'zoom', $admin);
    expect($new->host_status)->toBe('advisor_host')->and($new->status)->toBe('active')->and($room->fresh()->status)->toBe('replaced')
        ->and(MeetingProfile::forUser($adv->id)->zoom_url)->toBe($new->join_url);

    // Si el asesor pierde la licencia, la revisión deja de considerarla operativa.
    $GLOBALS['vc']->licensed = [];
    expect(vcRooms()->check($new, $admin)->status)->toBe('error');
});

it('si la cuenta no aplica la sala de espera solicitada, se avisa (sin desactivarla el CRM)', function () {
    ['admin' => $admin, 'advisor' => $adv] = vcInst();
    vcLicense($adv);
    vcFake(['account_no_waiting_room' => true]);
    vcZoom();
    $room = vcRooms()->generate($adv, 'zoom', $admin);
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/meetings') && $r['settings']['waiting_room'] === true);
    expect($room->host_status)->toBe('advisor_host')->and($room->host_note)->toContain('no aplicó la sala de espera');
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
        ->assertSee('Abrir Google Meet')->assertSee('Abrir Zoom')->assertSee('https://meet.google.com/abc-defg-hij')
        ->assertSee('Sí: el asesor es el anfitrión')
        ->assertDontSee('Regenerar')->assertDontSee('zak')->assertDontSee('ya29')
        ->call('generateFor', 'zoom')->assertForbidden();
    $other = User::factory()->create(['institution_id' => $adv->institution_id, 'role' => 'admissions', 'department' => 'Admisiones', 'status' => 'active']);
    Livewire::actingAs($adv)->test(VideoRooms::class, ['ownerId' => $other->id])->assertForbidden();

    Livewire::actingAs($admin)->test(VideoRooms::class, ['ownerId' => $adv->id])->assertSee('Regenerar')->assertSee('Revisar');
    Livewire::actingAs($admin)->test(Connections::class)->assertOk()->assertSee('salas@mca.test')->assertDontSee('GOCSPX-secret')->assertDontSee('zsecret');
});
