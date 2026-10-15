<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;
use Modules\Social\Services\NormalizedMessage;
use Modules\Social\Services\SocialIngestService;

/*
| Perfiles de Messenger (nombre, apellidos y foto) con Meta simulado (Http::fake): User Profile
| API cuando la app tiene acceso; si Meta la rechaza (#2018247, sin «Business Asset User Profile
| Access»), el nombre llega por la Conversations API. Se completan los campos que falten por
| separado, nunca se pisan datos válidos con null, hay espera entre consultas y una recuperación
| programada, limitada e idempotente, por institución.
*/

beforeEach(function () {
    config(['social.graph_version' => 'v26.0']);
    $GLOBALS['mp'] = null; // respuesta vigente del Meta simulado (se registra una sola vez por prueba)
});

const MP_PAGE = '1020304050';

function mpChannel(string $token = 'PAGE_TOKEN_A', string $page = MP_PAGE, bool $active = true): SocialChannel
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);

    return SocialChannel::factory()->create([
        'provider' => 'messenger', 'external_id' => $page, 'is_active' => $active, 'credentials' => ['token' => $token],
    ]);
}

function mpInbound(string $psid, string $mid, string $page = MP_PAGE): NormalizedMessage
{
    return new NormalizedMessage(
        provider: 'messenger', channelExternalId: $page, conversationExternalId: $psid, contactName: null,
        contactExternalId: $psid, contactAvatarUrl: null, messageExternalId: $mid, type: 'text', body: 'Hola',
        attachments: null, providerTimestamp: null,
    );
}

/** Meta simulado: perfil (ok | 400 sin acceso | 500) y participantes de la conversación. */
function mpFake(array $profile = [], int $profileStatus = 200, ?string $participant = null): void
{
    $registered = $GLOBALS['mp'] !== null;
    $GLOBALS['mp'] = compact('profile', 'profileStatus', 'participant');
    if ($registered) {
        return; // Http::fake acumula stubs: se cambia solo la respuesta vigente
    }
    Http::fake(function (Request $r) {
        ['profile' => $profile, 'profileStatus' => $profileStatus, 'participant' => $participant] = $GLOBALS['mp'];
        if (str_contains($r->url(), '/conversations')) {
            $psid = (string) ($r['user_id'] ?? '');

            return Http::response(['data' => $participant === null ? [] : [['id' => 't_1', 'participants' => ['data' => [
                ['name' => 'Página MCA', 'id' => MP_PAGE], ['name' => $participant, 'email' => $psid.'@facebook.com', 'id' => $psid],
            ]]]]]);
        }
        if ($profileStatus === 400) {
            return Http::response(['error' => ['message' => 'Insufficient permission to access user profile.', 'type' => 'OAuthException', 'code' => 2018247, 'fbtrace_id' => 'Ax1']], 400);
        }
        if ($profileStatus >= 500) {
            return Http::response(['error' => ['message' => 'Unknown', 'code' => 2, 'is_transient' => true]], $profileStatus);
        }

        return Http::response($profile);
    });
}

function mpConv(string $psid): SocialConversation
{
    return SocialConversation::query()->where('contact_external_id', $psid)->sole();
}

it('obtiene nombre, apellidos y foto con los campos oficiales de la User Profile API', function () {
    mpChannel();
    mpFake(['first_name' => 'Ana', 'last_name' => 'López', 'name' => 'Ana López', 'profile_pic' => 'https://platform-lookaside.fbsbx.com/pic.jpg', 'id' => 'PSID_1']);

    app(SocialIngestService::class)->ingest(mpInbound('PSID_1', 'm1'));

    $c = mpConv('PSID_1');
    expect($c->contact_name)->toBe('Ana López')->and($c->contact_first_name)->toBe('Ana')->and($c->contact_last_name)->toBe('López')
        ->and($c->contact_avatar_url)->toBe('https://platform-lookaside.fbsbx.com/pic.jpg')
        ->and($c->profile_status)->toBe('complete')->and($c->profile_error)->toBeNull();
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://graph.facebook.com/v26.0/PSID_1')
        && $r['fields'] === 'first_name,last_name,name,profile_pic' && $r->hasHeader('Authorization', 'Bearer PAGE_TOKEN_A'));
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/conversations'));
});

it('con HTTP 400 de Meta (#2018247, sin acceso al perfil) registra el código sin datos sensibles, obtiene el nombre por la conversación y no bloquea el mensaje', function () {
    mpChannel();
    Log::spy();
    mpFake(profileStatus: 400, participant: 'Carlos Ruiz');

    $result = app(SocialIngestService::class)->ingest(mpInbound('PSID_2', 'm1'));

    expect($result->status)->toBe('created')->and(SocialMessage::query()->count())->toBe(1);
    $c = mpConv('PSID_2');
    expect($c->contact_name)->toBe('Carlos Ruiz')->and($c->contact_avatar_url)->toBeNull()->and($c->unread_count)->toBe(1)
        ->and($c->profile_status)->toBe('partial')->and($c->profile_error)->toBe('http400 #2018247');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v26.0/'.MP_PAGE.'/conversations')
        && $r['platform'] === 'messenger' && $r['user_id'] === 'PSID_2' && $r['fields'] === 'participants');
    Log::shouldHaveReceived('info')->withArgs(fn ($msg, $ctx = []) => $msg === 'social.contact.resolve: Meta no resolvió el perfil'
        && $ctx['status'] === 400 && $ctx['code'] === 2018247 && $ctx['type'] === 'OAuthException'
        && ! str_contains(json_encode($ctx), 'PAGE_TOKEN') && ! str_contains(json_encode($ctx), 'Insufficient'));
});

it('si Meta no da nada, la conversación sigue con nombre y avatar de respaldo y no se reintenta en cada mensaje', function () {
    mpChannel();
    mpFake(profileStatus: 400);

    app(SocialIngestService::class)->ingest(mpInbound('PSID_3', 'm1'));
    app(SocialIngestService::class)->ingest(mpInbound('PSID_3', 'm2'));
    app(SocialIngestService::class)->ingest(mpInbound('PSID_3', 'm3'));

    $c = mpConv('PSID_3');
    expect($c->contact_name)->toBeNull()->and($c->profile_status)->toBe('unavailable')->and($c->unread_count)->toBe(3);
    // Una sola consulta (perfil + conversación) para los tres mensajes: hay espera entre intentos.
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), '/PSID_3'))->count())->toBe(1);
    expect(SocialMessage::query()->count())->toBe(3);
});

it('completa por separado la foto que falta conservando el nombre, y el nombre que falta conservando la foto', function () {
    $channel = mpChannel();
    // Con nombre y sin foto (consultado hace 1 día): aún no toca reintentar.
    $withName = SocialConversation::factory()->create(['social_channel_id' => $channel->id, 'provider' => 'messenger', 'external_conversation_id' => 'PSID_4', 'contact_external_id' => 'PSID_4',
        'contact_name' => 'Nombre Bueno', 'contact_avatar_url' => null, 'profile_status' => 'partial', 'profile_checked_at' => now()->subDay(), 'profile_attempts' => 1]);
    mpFake(['name' => 'Otro Nombre', 'profile_pic' => 'https://platform-lookaside.fbsbx.com/nueva.jpg']);
    app(SocialIngestService::class)->ingest(mpInbound('PSID_4', 'm1'));
    Http::assertNothingSent();

    // A los 7 días se reintenta: la foto se completa y el nombre válido NO se sustituye.
    $this->travel(8)->days();
    app(SocialIngestService::class)->ingest(mpInbound('PSID_4', 'm2'));
    $withName->refresh();
    expect($withName->contact_name)->toBe('Nombre Bueno')->and($withName->contact_avatar_url)->toBe('https://platform-lookaside.fbsbx.com/nueva.jpg')
        ->and($withName->profile_status)->toBe('complete');

    // Con foto y sin nombre: se completa el nombre y la foto existente no se borra.
    $withPic = SocialConversation::factory()->create(['social_channel_id' => $channel->id, 'provider' => 'messenger', 'external_conversation_id' => 'PSID_5', 'contact_external_id' => 'PSID_5',
        'contact_name' => null, 'contact_avatar_url' => 'https://platform-lookaside.fbsbx.com/vieja.jpg']);
    mpFake(['name' => 'Lucía Gómez']);
    app(SocialIngestService::class)->ingest(mpInbound('PSID_5', 'm1'));
    $withPic->refresh();
    expect($withPic->contact_name)->toBe('Lucía Gómez')->and($withPic->contact_avatar_url)->toBe('https://platform-lookaside.fbsbx.com/vieja.jpg');
});

it('un fallo transitorio de Meta se reintenta con espera creciente, sin reconsultar antes de tiempo', function () {
    mpChannel();
    mpFake(profileStatus: 500);
    app(SocialIngestService::class)->ingest(mpInbound('PSID_6', 'm1'));
    $c = mpConv('PSID_6');
    expect($c->profile_status)->toBe('error')->and($c->profile_attempts)->toBe(1);

    $this->travel(5)->minutes();
    app(SocialIngestService::class)->ingest(mpInbound('PSID_6', 'm2'));
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), '/PSID_6'))->count())->toBe(1);

    $this->travel(11)->minutes(); // 16 min > 15 min de espera
    mpFake(['name' => 'Recuperado', 'profile_pic' => 'https://platform-lookaside.fbsbx.com/r.jpg']);
    app(SocialIngestService::class)->ingest(mpInbound('PSID_6', 'm3'));
    expect($c->fresh()->contact_name)->toBe('Recuperado')->and($c->fresh()->profile_status)->toBe('complete');
});

it('la recuperación programada completa conversaciones antiguas por institución, con su token, limitada e idempotente', function () {
    // Institución A (3 incompletas) e institución B (2 incompletas + 1 de un canal inactivo).
    $chA = mpChannel('TOKEN_A', '111111');
    $instA = $chA->institution_id;
    foreach (['A1', 'A2', 'A3'] as $p) {
        SocialConversation::factory()->create(['social_channel_id' => $chA->id, 'provider' => 'messenger', 'external_conversation_id' => $p, 'contact_external_id' => $p, 'contact_name' => null, 'contact_avatar_url' => null]);
    }
    SocialConversation::factory()->create(['social_channel_id' => $chA->id, 'provider' => 'whatsapp', 'external_conversation_id' => 'W1', 'contact_external_id' => 'W1', 'contact_name' => null]);
    $chB = mpChannel('TOKEN_B', '222222');
    foreach (['B1', 'B2'] as $p) {
        SocialConversation::factory()->create(['social_channel_id' => $chB->id, 'provider' => 'messenger', 'external_conversation_id' => $p, 'contact_external_id' => $p, 'contact_name' => null, 'contact_avatar_url' => null]);
    }
    $chOff = SocialChannel::factory()->create(['provider' => 'messenger', 'external_id' => '333333', 'is_active' => false, 'credentials' => ['token' => 'TOKEN_OFF']]);
    SocialConversation::factory()->create(['social_channel_id' => $chOff->id, 'provider' => 'messenger', 'external_conversation_id' => 'OFF1', 'contact_external_id' => 'OFF1', 'contact_name' => null]);

    Http::fake(fn (Request $r) => Http::response(['name' => 'Perfil '.basename(parse_url($r->url(), PHP_URL_PATH)), 'profile_pic' => 'https://platform-lookaside.fbsbx.com/'.basename(parse_url($r->url(), PHP_URL_PATH)).'.jpg']));

    $this->artisan('social:refresh-contact-profiles --limit=3')->assertSuccessful();
    expect(Http::recorded()->count())->toBe(3);
    $this->artisan('social:refresh-contact-profiles')->assertSuccessful();
    expect(Http::recorded()->count())->toBe(5);
    $this->artisan('social:refresh-contact-profiles')->assertSuccessful(); // idempotente: nada pendiente
    expect(Http::recorded()->count())->toBe(5);

    // Cada conversación con el token de SU canal; ni WhatsApp ni el canal inactivo.
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/A1') && $r->hasHeader('Authorization', 'Bearer TOKEN_A'));
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/B2') && $r->hasHeader('Authorization', 'Bearer TOKEN_B'));
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/W1') || str_contains($r->url(), '/OFF1') || $r->hasHeader('Authorization', 'Bearer TOKEN_OFF'));

    app(CurrentInstitution::class)->set($instA);
    expect(SocialConversation::query()->where('provider', 'messenger')->pluck('contact_name')->sort()->values()->all())->toBe(['Perfil A1', 'Perfil A2', 'Perfil A3']);
    app(CurrentInstitution::class)->set($chB->institution_id);
    expect(SocialConversation::query()->where('contact_external_id', 'B1')->value('contact_name'))->toBe('Perfil B1')
        ->and(SocialConversation::query()->where('contact_external_id', 'OFF1')->value('contact_name'))->toBeNull();
});
