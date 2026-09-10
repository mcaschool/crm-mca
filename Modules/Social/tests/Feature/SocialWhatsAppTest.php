<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use Modules\Social\Livewire\Inbox;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;
use Modules\Social\Services\NormalizedStatus;
use Modules\Social\Services\SocialIngestService;
use Modules\Social\Services\SocialOutboundService;

/**
 * WhatsApp Cloud API (canal propio del CRM), SIN Meta real (Http::fake):
 *  - envío de texto por /{PHONE_NUMBER_ID}/messages (Bearer, payload oficial, wamid persistido);
 *  - estados sent/delivered/read/failed por wamid, sin regresiones ni mensajes fantasma;
 *  - media entrante (media id → URL temporal → descarga Bearer → disco PRIVADO) con
 *    validación de contenido real, y acceso solo autenticado + misma institución;
 *  - media saliente (subida a la Media API y envío por media id, caption/filename).
 */
const WA_SECRET = 'wa_test_app_secret';

beforeEach(function () {
    config([
        'social.graph_version' => 'v26.0',
        'social.app_secret' => WA_SECRET,
        'social.webhook_verify_token' => 'wa_test_verify',
    ]);
});

/**
 * @return array{0: Institution, 1: User, 2: SocialChannel, 3: SocialConversation}
 */
function waCtx(): array
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);
    $user = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin']);

    $channel = SocialChannel::factory()->create([
        'provider' => 'whatsapp',
        'external_id' => 'PHONE_1',
        'credentials' => ['token' => 'WA_TOKEN'],
    ]);
    $conversation = SocialConversation::factory()->create([
        'social_channel_id' => $channel->id,
        'provider' => 'whatsapp',
        'external_conversation_id' => '5215550001',
        'contact_external_id' => '5215550001',
        'contact_name' => 'Cliente WA',
    ]);

    // Entrante reciente: deja ABIERTA la ventana de 24h (los envíos libres de la bandeja
    // se bloquean cuando el último inbound tiene más de 24 horas).
    $inbound = new SocialMessage;
    $inbound->social_conversation_id = $conversation->id;
    $inbound->external_message_id = 'wamid.CTX_INBOUND';
    $inbound->direction = 'inbound';
    $inbound->type = 'text';
    $inbound->body = 'hola';
    $inbound->status = 'received';
    $inbound->sender_type = 'contact';
    $inbound->provider_timestamp = now()->subMinutes(5);
    $inbound->save();

    return [$institution, $user, $channel, $conversation];
}

/** Mensaje saliente ya enviado, con wamid, para reconciliar estados. */
function waSentMessage(SocialConversation $conversation, string $wamid, string $status = 'sent'): SocialMessage
{
    $message = new SocialMessage;
    $message->social_conversation_id = $conversation->id;
    $message->external_message_id = $wamid;
    $message->direction = 'outbound';
    $message->type = 'text';
    $message->body = 'hola';
    $message->status = $status;
    $message->sender_type = 'agent';
    $message->provider_timestamp = now();
    $message->save();

    return $message;
}

function waPostWebhook(string $raw): TestResponse
{
    return test()->call('POST', '/api/social/webhook/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, WA_SECRET),
    ], $raw);
}

/**
 * Payload de webhook con UN mensaje entrante de media (tipo image/video/audio/document).
 *
 * @param  array<string, mixed>  $mediaExtra
 */
function waMediaWebhookPayload(string $type, string $mediaId, string $mime, string $wamid, array $mediaExtra = []): string
{
    return (string) json_encode([
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => 'WABA_X',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => ['display_phone_number' => '15551230000', 'phone_number_id' => 'PHONE_1'],
                    'contacts' => [['profile' => ['name' => 'Cliente WA'], 'wa_id' => '5215550001']],
                    'messages' => [[
                        'from' => '5215550001',
                        'id' => $wamid,
                        'timestamp' => '1757001000',
                        'type' => $type,
                        $type => array_merge(['id' => $mediaId, 'mime_type' => $mime], $mediaExtra),
                    ]],
                ],
            ]],
        ]],
    ]);
}

/** Bytes REALES por tipo (finfo debe reconocerlos; nunca texto plano disfrazado). */
function waRealBytes(string $kind): string
{
    if ($kind === 'jpg') {
        $img = imagecreatetruecolor(8, 8);
        imagefilledrectangle($img, 0, 0, 8, 8, (int) imagecolorallocate($img, 30, 90, 168));
        ob_start();
        imagejpeg($img, null, 90);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    return match ($kind) {
        'png' => (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true),
        'mp4' => "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2avc1mp41".str_repeat("\x00", 64),
        'mp3' => str_repeat("\xFF\xFB\x90\x44".str_repeat("\x00", 100), 8),
        'pdf' => "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< >>\n%%EOF\n",
        'gif' => (string) base64_decode('R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==', true),
        default => 'no-media',
    };
}

/** Fake de Livewire con CONTENIDO real: el finfo del servicio clasifica por los bytes. */
function waUploadedFile(string $name, string $kind, string $padding = ''): \Illuminate\Http\Testing\File
{
    return UploadedFile::fake()->createWithContent($name, waRealBytes($kind).$padding);
}

/** Http::fake de los 2 pasos del media entrante: metadata del media id + descarga temporal. */
function waFakeInboundMedia(string $mediaId, string $mime, string $bytes): void
{
    Http::fake([
        "graph.facebook.com/v26.0/{$mediaId}" => Http::response([
            'url' => "https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid={$mediaId}",
            'mime_type' => $mime,
            'file_size' => strlen($bytes),
            'id' => $mediaId,
        ], 200),
        'lookaside.fbsbx.com/*' => Http::response($bytes, 200, ['Content-Type' => $mime]),
    ]);
}

/** Busca una parte por nombre en un request multipart grabado por Http::fake. */
function waPart(array $data, string $name): ?array
{
    foreach ($data as $part) {
        if (is_array($part) && ($part['name'] ?? null) === $name) {
            return $part;
        }
    }

    return null;
}

// ==================================================================================
// FASE A — envío de texto
// ==================================================================================

it('WhatsApp: envía texto por /{PHONE_NUMBER_ID}/messages con Bearer y el payload oficial', function () {
    [, $user, , $conversation] = waCtx();
    Http::fake(['graph.facebook.com/*' => Http::response([
        'messaging_product' => 'whatsapp',
        'contacts' => [['input' => '5215550001', 'wa_id' => '5215550001']],
        'messages' => [['id' => 'wamid.OUT00000001']],
    ], 200)]);

    $message = app(SocialOutboundService::class)->send($conversation, 'Hola desde el CRM 🙂', $user);

    expect($message->status)->toBe('sent');
    expect($message->direction)->toBe('outbound');
    expect($message->sender_type)->toBe('agent');
    expect($message->sent_by)->toBe($user->id);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://graph.facebook.com/v26.0/PHONE_1/messages'
            && $request->hasHeader('Authorization', 'Bearer WA_TOKEN')
            && $request['messaging_product'] === 'whatsapp'
            && $request['recipient_type'] === 'individual'
            && $request['to'] === '5215550001'
            && $request['type'] === 'text'
            && $request['text']['body'] === 'Hola desde el CRM 🙂';
    });
});

it('el wamid devuelto se persiste como external_message_id (reconciliación de estados)', function () {
    [, $user, , $conversation] = waCtx();
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT00000001']]], 200)]);

    $message = app(SocialOutboundService::class)->send($conversation, 'hola', $user);

    expect($message->external_message_id)->toBe('wamid.OUT00000001');
});

it('WhatsApp figura en SENDABLE y la bandeja envía texto normalmente', function () {
    [, $user, , $conversation] = waCtx();
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.UI00000001']]], 200)]);

    expect(SocialOutboundService::SENDABLE)->toContain('whatsapp');

    Livewire::actingAs($user)->test(Inbox::class)
        ->call('select', $conversation->id)
        ->set('draft', 'Respuesta al cliente')
        ->call('send')
        ->assertSet('draft', '');

    $msg = SocialMessage::query()->where('social_conversation_id', $conversation->id)->where('direction', 'outbound')->first();
    expect($msg)->not->toBeNull();
    expect($msg->status)->toBe('sent');
    expect($msg->external_message_id)->toBe('wamid.UI00000001');
});

it('canal sin token → failed sin llamar a la red', function () {
    [, $user, $channel, $conversation] = waCtx();
    $channel->credentials = [];
    $channel->save();
    $conversation->refresh();
    Http::fake();

    $message = app(SocialOutboundService::class)->send($conversation, 'hola', $user);

    expect($message->status)->toBe('failed');
    Http::assertNothingSent();
});

it('canal sin Phone Number ID → failed sin llamar a la red', function () {
    [, $user, $channel, $conversation] = waCtx();
    $channel->external_id = null;
    $channel->save();
    $conversation->refresh();
    Http::fake();

    $message = app(SocialOutboundService::class)->send($conversation, 'hola', $user);

    expect($message->status)->toBe('failed');
    Http::assertNothingSent();
});

it('un error de Meta deja failed y el diagnóstico nunca contiene el token', function () {
    [, $user, , $conversation] = waCtx();
    Http::fake(['graph.facebook.com/*' => Http::response([
        'error' => ['message' => '(#131030) Recipient phone number not in allowed list', 'code' => 131030],
    ], 400)]);

    $logged = [];
    Log::listen(function ($event) use (&$logged) {
        $logged[] = $event->message.' '.json_encode($event->context);
    });

    $message = app(SocialOutboundService::class)->send($conversation, 'hola', $user);

    expect($message->status)->toBe('failed');
    expect($message->external_message_id)->toBeNull();
    expect(implode(' ', $logged))->not->toContain('WA_TOKEN');
});

it('fuera de la ventana de servicio (131047) → failed_window (se requiere plantilla)', function () {
    [, $user, , $conversation] = waCtx();
    Http::fake(['graph.facebook.com/*' => Http::response([
        'error' => ['message' => '(#131047) Re-engagement message', 'code' => 131047, 'error_subcode' => 2494049],
    ], 400)]);

    $message = app(SocialOutboundService::class)->send($conversation, 'respuesta tardía', $user);

    expect($message->status)->toBe('failed_window');
});

// ==================================================================================
// FASE B — estados sent/delivered/read/failed
// ==================================================================================

dataset('wa_status_avances', [
    'sent desde pending' => ['pending', 'sent', 'sent'],
    'delivered desde sent' => ['sent', 'delivered', 'delivered'],
    'read desde delivered' => ['delivered', 'read', 'read'],
    'failed desde sent' => ['sent', 'failed', 'failed'],
]);

it('aplica el estado al mensaje localizado por wamid', function (string $inicial, string $entrante, string $esperado) {
    [, , , $conversation] = waCtx();
    $message = waSentMessage($conversation, 'wamid.OUT00000001', $inicial);

    $result = app(SocialIngestService::class)->applyStatus(
        new NormalizedStatus('whatsapp', 'PHONE_1', 'wamid.OUT00000001', $entrante),
    );

    expect($result)->toBe('updated');
    expect($message->refresh()->status)->toBe($esperado);
})->with('wa_status_avances');

dataset('wa_status_regresiones', [
    'delivered + failed tardío => sigue delivered' => ['delivered', 'failed'],
    'read + failed tardío => sigue read' => ['read', 'failed'],
    'delivered + sent tardío => sigue delivered' => ['delivered', 'sent'],
    'read + delivered tardío => sigue read' => ['read', 'delivered'],
    'read + sent tardío => sigue read' => ['read', 'sent'],
    'failed es terminal: sent tardío no lo revive' => ['failed', 'sent'],
    'failed es terminal: delivered tardío no lo revive' => ['failed', 'delivered'],
]);

it('un estado tardío nunca regresa ni revive el estado del mensaje', function (string $actual, string $tardio) {
    [, , , $conversation] = waCtx();
    $message = waSentMessage($conversation, 'wamid.OUT00000001', $actual);

    $result = app(SocialIngestService::class)->applyStatus(
        new NormalizedStatus('whatsapp', 'PHONE_1', 'wamid.OUT00000001', $tardio),
    );

    expect($result)->toBe('ignored');
    expect($message->refresh()->status)->toBe($actual);
})->with('wa_status_regresiones');

it('webhook de estados end-to-end: delivered actualiza el mensaje por wamid', function () {
    [, , $channel, $conversation] = waCtx();
    $channel->external_id = 'demo_wa_phone'; // el fixture usa demo_wa_phone
    $channel->save();
    $message = waSentMessage($conversation, 'wamid.OUT00000001', 'sent');
    Http::fake();

    $raw = (string) file_get_contents(__DIR__.'/../Fixtures/whatsapp_status.json');
    $res = waPostWebhook($raw);

    $res->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('statuses.0', 'updated');
    expect($message->refresh()->status)->toBe('delivered');
});

it('estado para un wamid desconocido: 200, sin excepción y sin mensajes fantasma', function () {
    [, , $channel] = waCtx();
    $channel->external_id = 'demo_wa_phone';
    $channel->save();
    Http::fake();
    $before = SocialMessage::query()->count();

    $raw = (string) file_get_contents(__DIR__.'/../Fixtures/whatsapp_status.json');
    $res = waPostWebhook($raw);

    $res->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('statuses.0', 'unknown');
    expect(SocialMessage::query()->count())->toBe($before);
});

// ==================================================================================
// FASE C — media entrante (media id → descarga privada)
// ==================================================================================

dataset('wa_media_entrante', [
    'image' => ['image', 'png', 'image/png', []],
    'video' => ['video', 'mp4', 'video/mp4', []],
    'audio' => ['audio', 'mp3', 'audio/mpeg', []],
    'document' => ['document', 'pdf', 'application/pdf', ['filename' => 'temario.pdf']],
]);

it('ingiere media entrante: descarga por media id y guarda en el disco privado', function (string $type, string $kind, string $mime, array $extra) {
    waCtx();
    Storage::fake('local');
    $bytes = waRealBytes($kind);
    waFakeInboundMedia('MEDIA_IN_1', $mime, $bytes);

    $res = waPostWebhook(waMediaWebhookPayload($type, 'MEDIA_IN_1', $mime, 'wamid.MEDIA00000001', $extra));

    $res->assertOk()->assertJsonPath('results.0.status', 'created');
    $message = SocialMessage::query()->where('external_message_id', 'wamid.MEDIA00000001')->first();
    expect($message)->not->toBeNull();
    expect($message->type)->toBe($type);

    $att = $message->attachments[0];
    expect($att['provider_media_id'])->toBe('MEDIA_IN_1');
    expect($att['mime'])->toBe($mime);
    expect($att['size'])->toBe(strlen($bytes));
    expect($att['storage_path'])->toStartWith('social-wa/');
    expect(Storage::disk('local')->get($att['storage_path']))->toBe($bytes);
    if ($extra !== []) {
        expect($att['filename'])->toBe($extra['filename']);
    }
})->with('wa_media_entrante');

it('el webhook responde 200 ANTES de descargar: el media se despacha afterResponse', function () {
    waCtx();
    Storage::fake('local');
    Http::fake();
    \Illuminate\Support\Facades\Bus::fake();

    $res = waPostWebhook(waMediaWebhookPayload('image', 'MEDIA_IN_9', 'image/png', 'wamid.MEDIA00000009'));

    // La respuesta ya salió con el mensaje ingerido…
    $res->assertOk()->assertJsonPath('results.0.status', 'created');
    // …sin haber tocado Graph todavía (cero descargas dentro del ciclo del webhook)…
    Http::assertNothingSent();
    // …con el adjunto pendiente (solo provider_media_id)…
    $att = SocialMessage::query()->where('external_message_id', 'wamid.MEDIA00000009')->first()->attachments[0];
    expect($att['provider_media_id'])->toBe('MEDIA_IN_9');
    expect($att)->not->toHaveKey('storage_path');
    // …y la descarga programada para DESPUÉS de la respuesta.
    \Illuminate\Support\Facades\Bus::assertDispatchedAfterResponse(\Modules\Social\Jobs\ProcessWhatsAppInboundMedia::class);
});

it('el media entrante NUNCA toca el disco público ni genera URL pública', function () {
    waCtx();
    Storage::fake('local');
    Storage::fake('public');
    waFakeInboundMedia('MEDIA_IN_2', 'image/png', waRealBytes('png'));

    waPostWebhook(waMediaWebhookPayload('image', 'MEDIA_IN_2', 'image/png', 'wamid.MEDIA00000002'))->assertOk();

    expect(Storage::disk('public')->allFiles())->toBe([]);
    $att = SocialMessage::query()->where('external_message_id', 'wamid.MEDIA00000002')->first()->attachments[0];
    expect(Storage::disk('local')->exists($att['storage_path']))->toBeTrue();
    expect($att)->not->toHaveKey('url');
});

it('si el contenido real no coincide con el tipo declarado, no se guarda (queda solo el media id)', function () {
    waCtx();
    Storage::fake('local');
    waFakeInboundMedia('MEDIA_IN_3', 'image/png', 'esto no es una imagen'); // finfo → text/plain

    $res = waPostWebhook(waMediaWebhookPayload('image', 'MEDIA_IN_3', 'image/png', 'wamid.MEDIA00000003'));

    $res->assertOk()->assertJsonPath('results.0.status', 'created'); // el mensaje SÍ se ingiere
    $att = SocialMessage::query()->where('external_message_id', 'wamid.MEDIA00000003')->first()->attachments[0];
    expect($att['provider_media_id'])->toBe('MEDIA_IN_3');
    expect($att)->not->toHaveKey('storage_path');
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('el media se sirve solo autenticado, con el content-type guardado', function () {
    [, $user] = waCtx();
    Storage::fake('local');
    $bytes = waRealBytes('png');
    waFakeInboundMedia('MEDIA_IN_4', 'image/png', $bytes);
    waPostWebhook(waMediaWebhookPayload('image', 'MEDIA_IN_4', 'image/png', 'wamid.MEDIA00000004'))->assertOk();
    $message = SocialMessage::query()->where('external_message_id', 'wamid.MEDIA00000004')->first();

    // Sin sesión → al login (nunca sirve el archivo).
    $this->get(route('social.media.show', ['message' => $message->id, 'index' => 0]))
        ->assertRedirect();

    // Autenticado de la MISMA institución → 200 con los bytes y el mime correctos.
    $res = $this->actingAs($user)->get(route('social.media.show', ['message' => $message->id, 'index' => 0]));
    $res->assertOk();
    expect($res->headers->get('content-type'))->toBe('image/png');
    expect($res->getFile()->getContent())->toBe($bytes);
});

it('un usuario de OTRA institución no accede al media (404 por scope)', function () {
    waCtx();
    Storage::fake('local');
    waFakeInboundMedia('MEDIA_IN_5', 'image/png', waRealBytes('png'));
    waPostWebhook(waMediaWebhookPayload('image', 'MEDIA_IN_5', 'image/png', 'wamid.MEDIA00000005'))->assertOk();
    $message = SocialMessage::query()->where('external_message_id', 'wamid.MEDIA00000005')->first();

    $otra = Institution::factory()->create();
    app(CurrentInstitution::class)->set($otra->id);
    $intruso = User::factory()->create(['institution_id' => $otra->id, 'role' => 'admin']);

    $this->actingAs($intruso)
        ->get(route('social.media.show', ['message' => $message->id, 'index' => 0]))
        ->assertNotFound();
});

// ==================================================================================
// FASE D — media saliente (Media API: subir binario → enviar por media id)
// ==================================================================================

dataset('wa_media_saliente', [
    'image' => ['foto.jpg', 'jpg', 'image/jpeg', 'image'],
    'video' => ['clip.mp4', 'mp4', 'video/mp4', 'video'],
    'audio' => ['nota.mp3', 'mp3', 'audio/mpeg', 'audio'],
    'document' => ['temario.pdf', 'pdf', 'application/pdf', 'document'],
]);

it('envía media desde la bandeja: sube a la Media API y envía por media id', function (string $name, string $kind, string $mime, string $type) {
    [, $user] = waCtx();
    Storage::fake('local');
    Http::fake([
        'graph.facebook.com/v26.0/PHONE_1/media' => Http::response(['id' => 'MEDIA_UP_1'], 200),
        'graph.facebook.com/v26.0/PHONE_1/messages' => Http::response(['messages' => [['id' => 'wamid.MEDIAOUT0001']]], 200),
    ]);

    $file = waUploadedFile($name, $kind);
    $conversationId = SocialConversation::query()->value('id');

    Livewire::actingAs($user)->test(Inbox::class)
        ->call('select', $conversationId)
        ->set('attachment', $file)
        ->set('draft', 'Te lo comparto')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('attachment', null)
        ->assertSet('draft', '');

    // 1) Subida multipart a /media con messaging_product=whatsapp + file (Bearer, sin URL pública).
    Http::assertSent(function ($request) {
        if ($request->url() !== 'https://graph.facebook.com/v26.0/PHONE_1/media') {
            return false;
        }
        $mp = waPart($request->data(), 'messaging_product');
        $filePart = waPart($request->data(), 'file');

        return $request->hasHeader('Authorization', 'Bearer WA_TOKEN')
            && $mp !== null && $mp['contents'] === 'whatsapp'
            && $filePart !== null;
    });

    // 2) Envío por media id (nunca por URL) con caption donde aplica y filename en document.
    Http::assertSent(function ($request) use ($type, $name) {
        if ($request->url() !== 'https://graph.facebook.com/v26.0/PHONE_1/messages') {
            return false;
        }
        $media = $request[$type] ?? null;
        $captionOk = $type === 'audio'
            ? ! isset($media['caption'])
            : ($media['caption'] ?? null) === 'Te lo comparto';
        $filenameOk = $type !== 'document' || ($media['filename'] ?? null) === $name;

        return $request['messaging_product'] === 'whatsapp'
            && $request['type'] === $type
            && ($media['id'] ?? null) === 'MEDIA_UP_1'
            && $captionOk && $filenameOk;
    });

    // 3) El mensaje queda con wamid, adjunto privado y estado sent.
    $message = SocialMessage::query()->where('direction', 'outbound')->latest('id')->first();
    expect($message->status)->toBe('sent');
    expect($message->type)->toBe($type);
    expect($message->external_message_id)->toBe('wamid.MEDIAOUT0001');
    $att = $message->attachments[0];
    expect($att['provider_media_id'])->toBe('MEDIA_UP_1');
    expect($att['filename'])->toBe($name);
    expect(Storage::disk('local')->exists($att['storage_path']))->toBeTrue();
})->with('wa_media_saliente');

it('un formato no soportado se rechaza con mensaje claro y sin llamar a Meta', function () {
    [, $user] = waCtx();
    Storage::fake('local');
    Http::fake();

    $file = waUploadedFile('animado.gif', 'gif'); // GIF real: fuera de la política de WhatsApp
    $conversationId = SocialConversation::query()->value('id');

    Livewire::actingAs($user)->test(Inbox::class)
        ->call('select', $conversationId)
        ->set('attachment', $file)
        ->call('send')
        ->assertHasErrors('attachment');

    Http::assertNothingSent();
    expect(SocialMessage::query()->where('direction', 'outbound')->count())->toBe(0);
});

it('una imagen que supera los 5 MB de WhatsApp se rechaza antes de llamar a Meta', function () {
    [, $user] = waCtx();
    Storage::fake('local');
    Http::fake();

    // JPEG real con relleno hasta superar los 5 MB (el header sigue siendo image/jpeg).
    $file = waUploadedFile('gigante.jpg', 'jpg', str_repeat('A', 6 * 1024 * 1024));
    $conversationId = SocialConversation::query()->value('id');

    Livewire::actingAs($user)->test(Inbox::class)
        ->call('select', $conversationId)
        ->set('attachment', $file)
        ->call('send')
        ->assertHasErrors('attachment');

    Http::assertNothingSent();
});

// ==================================================================================
// FASE H — los estados conviven con los echoes de coexistencia
// ==================================================================================

it('un delivered sobre un eco de coexistencia también avanza (sin romper el eco)', function () {
    [, , $channel] = waCtx();
    $channel->external_id = 'demo_wa_phone';
    $channel->save();
    Http::fake();

    // Ingesta del eco real (fixture existente) y luego su estado delivered por wamid.
    waPostWebhook((string) file_get_contents(__DIR__.'/../Fixtures/whatsapp_echo.json'))->assertOk();
    $echo = SocialMessage::query()->where('external_message_id', 'wamid.ECHO00000001')->first();
    expect($echo->sender_type)->toBe('app');
    expect($echo->status)->toBe('sent');

    $result = app(SocialIngestService::class)->applyStatus(
        new NormalizedStatus('whatsapp', 'demo_wa_phone', 'wamid.ECHO00000001', 'delivered'),
    );

    expect($result)->toBe('updated');
    expect($echo->refresh()->status)->toBe('delivered');
});
