<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use Modules\Social\Exceptions\UnsupportedSocialProviderException;
use Modules\Social\Livewire\Inbox;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;
use Modules\Social\Services\SocialOutboundService;

/**
 * Salida (Bloque 4): responder desde el CRM hacia Meta para Instagram + Messenger, con
 * Http::fake (sin Meta real). Verifica el armado de la petición (endpoint/versión vigente,
 * token del canal, recipient, body), los estados (sent/failed/failed_window), la exclusión
 * de WhatsApp y el scoping por institución.
 */
beforeEach(function () {
    config(['social.graph_version' => 'v26.0']);
});

/**
 * @return array{0: Institution, 1: User, 2: SocialConversation, 3: SocialConversation, 4: SocialConversation}
 */
function outboundCtx(): array
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);
    $user = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin']);

    $mCh = SocialChannel::factory()->create(['provider' => 'messenger', 'external_id' => 'PAGE_1', 'credentials' => ['token' => 'MSGR_TOKEN']]);
    $iCh = SocialChannel::factory()->create(['provider' => 'instagram', 'external_id' => 'IGU_1', 'credentials' => ['token' => 'IG_TOKEN']]);
    $wCh = SocialChannel::factory()->create(['provider' => 'whatsapp', 'external_id' => 'WA_1', 'credentials' => ['token' => 'WA_TOKEN']]);

    $mConv = SocialConversation::factory()->create(['social_channel_id' => $mCh->id, 'provider' => 'messenger', 'external_conversation_id' => 'PSID_1', 'contact_external_id' => 'PSID_1', 'unread_count' => 2]);
    $iConv = SocialConversation::factory()->create(['social_channel_id' => $iCh->id, 'provider' => 'instagram', 'external_conversation_id' => 'IGSID_1', 'contact_external_id' => 'IGSID_1', 'unread_count' => 1]);
    $wConv = SocialConversation::factory()->create(['social_channel_id' => $wCh->id, 'provider' => 'whatsapp', 'external_conversation_id' => 'WA_USER', 'contact_external_id' => 'WA_USER']);

    return [$institution, $user, $mConv, $iConv, $wConv];
}

function outbound(): SocialOutboundService
{
    return app(SocialOutboundService::class);
}

// ----------------------------------------------------------------------------------
// Messenger: petición correcta + OK → 'sent'
// ----------------------------------------------------------------------------------
it('Messenger: arma la petición correcta y marca el mensaje como enviado', function () {
    [, $user, $mConv] = outboundCtx();
    Http::fake(['graph.facebook.com/*' => Http::response(['recipient_id' => 'PSID_1', 'message_id' => 'mid.MSGR_OUT_1'], 200)]);

    $message = outbound()->send($mConv, 'Hola, gracias por escribir 🙂', $user);

    expect($message->status)->toBe('sent');
    expect($message->direction)->toBe('outbound');
    expect($message->sender_type)->toBe('agent');
    expect($message->sent_by)->toBe($user->id);
    expect($message->external_message_id)->toBe('mid.MSGR_OUT_1');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://graph.facebook.com/v26.0/me/messages'
            && $request->hasHeader('Authorization', 'Bearer MSGR_TOKEN')
            && $request['recipient']['id'] === 'PSID_1'
            && $request['messaging_type'] === 'RESPONSE'
            && $request['message']['text'] === 'Hola, gracias por escribir 🙂';
    });

    // La conversación actualiza preview/last_message_at; unread NO cambia (sigue en 2).
    $mConv->refresh();
    expect($mConv->last_message_preview)->toBe('Hola, gracias por escribir 🙂');
    expect($mConv->unread_count)->toBe(2);
});

// ----------------------------------------------------------------------------------
// Instagram: host graph.facebook.com + nodo 'me' (la Página la determina el Page Access Token)
// ----------------------------------------------------------------------------------
it('Instagram: envía por graph.facebook.com al nodo me/messages con el token del canal', function () {
    [, $user, , $iConv] = outboundCtx();
    Http::fake(['graph.facebook.com/*' => Http::response(['recipient_id' => 'IGSID_1', 'message_id' => 'mid.IG_OUT_1'], 200)]);

    $message = outbound()->send($iConv, 'Te paso la info por aquí', $user);

    expect($message->status)->toBe('sent');
    expect($message->external_message_id)->toBe('mid.IG_OUT_1');

    // El nodo es 'me' (no el Page ID ni el IG User ID); recipient = IGSID; payload básico.
    Http::assertSent(function ($request) {
        return $request->url() === 'https://graph.facebook.com/v26.0/me/messages'
            && $request->hasHeader('Authorization', 'Bearer IG_TOKEN')
            && $request['recipient']['id'] === 'IGSID_1'
            && $request['message']['text'] === 'Te paso la info por aquí';
    });
});

// ----------------------------------------------------------------------------------
// Error genérico → 'failed' (sin romper)
// ----------------------------------------------------------------------------------
it('un error genérico de Meta deja el mensaje en failed', function () {
    [, $user, $mConv] = outboundCtx();
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid parameter', 'code' => 100]], 400)]);

    $message = outbound()->send($mConv, 'texto', $user);

    expect($message->status)->toBe('failed');
    expect($message->external_message_id)->toBeNull();
});

// ----------------------------------------------------------------------------------
// Fuera de ventana 24h (code 10 / subcode 2018278) → 'failed_window'
// ----------------------------------------------------------------------------------
it('fuera de la ventana de 24h deja el mensaje en failed_window', function () {
    [, $user, $mConv] = outboundCtx();
    Http::fake(['graph.facebook.com/*' => Http::response([
        'error' => ['message' => '(#10) This message is sent outside of allowed window.', 'type' => 'OAuthException', 'code' => 10, 'error_subcode' => 2018278],
    ], 400)]);

    $message = outbound()->send($mConv, 'respuesta tardía', $user);

    expect($message->status)->toBe('failed_window');
});

// ----------------------------------------------------------------------------------
// WhatsApp: NO se envía en este bloque
// ----------------------------------------------------------------------------------
it('WhatsApp no se envía en este bloque (no soportado) y no llama a la red', function () {
    [, $user, , , $wConv] = outboundCtx();
    Http::fake();

    expect(fn () => outbound()->send($wConv, 'hola', $user))
        ->toThrow(UnsupportedSocialProviderException::class);

    Http::assertNothingSent();
    expect(SocialMessage::query()->where('social_conversation_id', $wConv->id)->count())->toBe(0);
});

// ----------------------------------------------------------------------------------
// UI: burbuja saliente aparece; unread no cambia
// ----------------------------------------------------------------------------------
it('desde la bandeja, enviar agrega la burbuja saliente enviada', function () {
    [, $user, $mConv] = outboundCtx();
    Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'mid.UI_1'], 200)]);

    Livewire::actingAs($user)->test(Inbox::class)
        ->call('select', $mConv->id)
        ->set('draft', 'Respuesta desde el panel')
        ->call('send')
        ->assertSet('draft', '')
        ->assertSee('Respuesta desde el panel');

    $msg = SocialMessage::query()->where('social_conversation_id', $mConv->id)->where('direction', 'outbound')->first();
    expect($msg)->not->toBeNull();
    expect($msg->status)->toBe('sent');
});

// ----------------------------------------------------------------------------------
// Scoping: un agente de OTRA institución no puede enviar en esta conversación
// ----------------------------------------------------------------------------------
it('un agente de otra institución no puede enviar en la conversación', function () {
    [, , $mConv] = outboundCtx();
    Http::fake();

    // Agente de OTRA institución.
    $otra = Institution::factory()->create();
    app(CurrentInstitution::class)->set($otra->id);
    $intruso = User::factory()->create(['institution_id' => $otra->id, 'role' => 'admin']);

    Livewire::actingAs($intruso)->test(Inbox::class)
        ->set('selectedId', $mConv->id)   // intenta apuntar a una conversación ajena
        ->set('draft', 'no debería salir')
        ->call('send');

    Http::assertNothingSent();
    // No se creó ningún saliente en la conversación de la otra institución.
    app(CurrentInstitution::class)->set($mConv->institution_id);
    expect(SocialMessage::query()->where('social_conversation_id', $mConv->id)->where('direction', 'outbound')->count())->toBe(0);
});
