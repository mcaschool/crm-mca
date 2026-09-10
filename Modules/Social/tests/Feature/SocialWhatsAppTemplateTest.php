<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use Modules\Social\Livewire\Inbox;
use Modules\Social\Livewire\WhatsAppTemplates;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;
use Modules\Social\Models\SocialWhatsAppTemplate;
use Modules\Social\Services\SocialOutboundService;
use Modules\Social\Services\WhatsAppTemplateService;
use Modules\Social\Services\WhatsAppTemplateValidator;

/**
 * Plantillas de WhatsApp SIN Meta real (Http::fake): sync paginado, creación con
 * validación local, webhooks de estado/categoría, selección solo-APPROVED en la bandeja,
 * envío type=template con wamid, y la ventana de 24h (UI preventiva + backend).
 */
const WT_SECRET = 'wt_test_secret';

beforeEach(function () {
    config([
        'social.graph_version' => 'v26.0',
        'social.app_secret' => WT_SECRET,
        'social.webhook_verify_token' => 'wt_verify',
    ]);
});

/**
 * @return array{0: Institution, 1: User, 2: SocialChannel, 3: SocialConversation}
 */
function wtCtx(): array
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);
    $user = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin']);

    $channel = SocialChannel::factory()->create([
        'provider' => 'whatsapp',
        'external_id' => 'PHONE_T1',
        'credentials' => ['token' => 'WA_TOKEN', 'waba_id' => 'WABA_T1'],
    ]);
    $conversation = SocialConversation::factory()->create([
        'social_channel_id' => $channel->id,
        'provider' => 'whatsapp',
        'external_conversation_id' => '5215550009',
        'contact_external_id' => '5215550009',
        'contact_name' => 'Cliente Plantillas',
    ]);

    return [$institution, $user, $channel, $conversation];
}

/** Entrante con la antigüedad dada (controla la ventana de 24h). */
function wtInbound(SocialConversation $conversation, \DateTimeInterface $at, string $wamid = 'wamid.WT_IN_1'): SocialMessage
{
    $m = new SocialMessage;
    $m->social_conversation_id = $conversation->id;
    $m->external_message_id = $wamid;
    $m->direction = 'inbound';
    $m->type = 'text';
    $m->body = 'hola';
    $m->status = 'received';
    $m->sender_type = 'contact';
    $m->provider_timestamp = $at;
    $m->save();

    return $m;
}

function wtPostWebhook(string $raw): TestResponse
{
    return test()->call('POST', '/api/social/webhook/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, WT_SECRET),
    ], $raw);
}

/**
 * @param  array<string, mixed>  $value
 */
function wtTemplateWebhook(string $field, array $value): string
{
    return (string) json_encode([
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => 'WABA_T1',
            'changes' => [['field' => $field, 'value' => $value]],
        ]],
    ]);
}

/**
 * @return array<string, mixed>
 */
function wtMetaRow(string $id, string $name, string $status = 'APPROVED', string $category = 'UTILITY'): array
{
    return [
        'id' => $id,
        'name' => $name,
        'status' => $status,
        'category' => $category,
        'language' => 'es',
        'parameter_format' => 'POSITIONAL',
        'quality_score' => ['score' => 'GREEN', 'date' => 1757001000],
        'components' => [
            ['type' => 'BODY', 'text' => 'Hola {{1}}, tu tramite {{2}} avanza.', 'example' => ['body_text' => [['Carlos', 'MBA']]]],
        ],
    ];
}

// ==================================================================================
// Sync
// ==================================================================================

it('sincroniza plantillas de Meta: crea nuevas, actualiza existentes y NUNCA borra locales', function () {
    [, , $channel] = wtCtx();
    $existing = SocialWhatsAppTemplate::factory()->create([
        'social_channel_id' => $channel->id,
        'meta_template_id' => '111',
        'name' => 'bienvenida',
        'status' => 'PENDING',
    ]);
    $localOnly = SocialWhatsAppTemplate::factory()->create([
        'social_channel_id' => $channel->id,
        'name' => 'solo_local',
    ]);
    Http::fake(['graph.facebook.com/*' => Http::response([
        'data' => [wtMetaRow('111', 'bienvenida'), wtMetaRow('222', 'recordatorio', 'PENDING')],
        'paging' => ['cursors' => ['after' => 'ZZZ']],
    ], 200)]);

    $result = app(WhatsAppTemplateService::class)->sync($channel);

    expect($result['synced'])->toBe(2);
    expect($existing->refresh()->status)->toBe('APPROVED');
    expect($existing->quality_score)->toBe('GREEN');
    expect(SocialWhatsAppTemplate::query()->where('name', 'recordatorio')->exists())->toBeTrue();
    expect($localOnly->refresh()->id)->toBe($localOnly->id); // sigue existiendo

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://graph.facebook.com/v26.0/WABA_T1/message_templates')
        && $request->hasHeader('Authorization', 'Bearer WA_TOKEN')
        && ! str_contains($request->url(), 'WA_TOKEN'));
});

it('sincroniza siguiendo la paginación por cursor (sin arrastrar paging.next crudo)', function () {
    [, , $channel] = wtCtx();
    Http::fake(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        if (($query['after'] ?? null) === 'CUR1') {
            return Http::response(['data' => [wtMetaRow('333', 'pagina_dos')]], 200);
        }

        return Http::response([
            'data' => [wtMetaRow('111', 'pagina_uno')],
            'paging' => ['cursors' => ['after' => 'CUR1'], 'next' => 'https://graph.facebook.com/next'],
        ], 200);
    });

    $result = app(WhatsAppTemplateService::class)->sync($channel);

    expect($result)->toBe(['synced' => 2, 'pages' => 2]);
    expect(SocialWhatsAppTemplate::query()->count())->toBe(2);
});

it('quality_score tolera null, string y estructura v26 {score,date,reasons}', function () {
    [, , $channel] = wtCtx();
    $rowNull = wtMetaRow('511', 'calidad_null');
    $rowNull['quality_score'] = null;
    $rowString = wtMetaRow('512', 'calidad_string');
    $rowString['quality_score'] = 'yellow'; // compatibilidad: string plano
    $rowObject = wtMetaRow('513', 'calidad_objeto');
    $rowObject['quality_score'] = ['score' => 'RED', 'date' => 1757001000, 'reasons' => ['USER_BLOCKS'], 'otro_campo' => 'no_persistir'];
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => [$rowNull, $rowString, $rowObject]], 200)]);

    app(WhatsAppTemplateService::class)->sync($channel);

    $null = SocialWhatsAppTemplate::query()->where('name', 'calidad_null')->first();
    $string = SocialWhatsAppTemplate::query()->where('name', 'calidad_string')->first();
    $object = SocialWhatsAppTemplate::query()->where('name', 'calidad_objeto')->first();

    expect($null->quality_score)->toBeNull();
    expect($null->quality_details)->toBeNull();
    expect($string->quality_score)->toBe('YELLOW');
    expect($string->quality_details)->toBeNull();
    expect($object->quality_score)->toBe('RED'); // SOLO el score en la columna string
    expect($object->quality_details)->toBe(['date' => 1757001000, 'reasons' => ['USER_BLOCKS']]);
    expect($object->quality_details)->not->toHaveKey('otro_campo'); // jamás la respuesta completa
});

// ==================================================================================
// Creación
// ==================================================================================

it('crea una plantilla UTILITY: payload oficial y estado inicial de Meta', function () {
    [, , $channel] = wtCtx();
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '900', 'status' => 'PENDING', 'category' => 'UTILITY'], 200)]);

    $template = app(WhatsAppTemplateService::class)->create($channel, [
        'name' => 'confirmacion_solicitud',
        'language' => 'es',
        'category' => 'UTILITY',
        'headerFormat' => '',
        'headerText' => '',
        'body' => 'Hola {{1}}, recibimos tu solicitud para {{2}}.',
        'footer' => 'MCA School',
        'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Más información', 'url' => '', 'phone' => '']],
        'examples' => [1 => 'Carlos', 2 => 'MBA'],
        'headerExample' => '',
    ]);

    expect($template->status)->toBe('PENDING');
    expect($template->meta_template_id)->toBe('900');

    Http::assertSent(function ($request) {
        $body = collect($request['components'])->firstWhere('type', 'BODY');

        return $request->url() === 'https://graph.facebook.com/v26.0/WABA_T1/message_templates'
            && $request['name'] === 'confirmacion_solicitud'
            && $request['language'] === 'es'
            && $request['category'] === 'UTILITY'
            && $request['allow_category_change'] === true // v26: Meta recategoriza en vez de rechazar
            && $request['parameter_format'] === 'POSITIONAL'
            && $body['example']['body_text'][0] === ['Carlos', 'MBA']
            && collect($request['components'])->firstWhere('type', 'FOOTER')['text'] === 'MCA School'
            && collect($request['components'])->firstWhere('type', 'BUTTONS')['buttons'][0]['type'] === 'QUICK_REPLY';
    });
});

it('crea MARKETING y respeta la RECATEGORIZACIÓN que devuelva Meta', function () {
    [, , $channel] = wtCtx();
    // Se solicita UTILITY pero Meta la recategoriza a MARKETING: se persiste MARKETING.
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '901', 'status' => 'PENDING', 'category' => 'MARKETING'], 200)]);

    $template = app(WhatsAppTemplateService::class)->create($channel, [
        'name' => 'promo_becas',
        'language' => 'es',
        'category' => 'UTILITY',
        'headerFormat' => '',
        'headerText' => '',
        'body' => 'Aprovecha la beca disponible.',
        'footer' => '',
        'buttons' => [],
        'examples' => [],
        'headerExample' => '',
    ]);

    expect($template->category)->toBe('MARKETING');
});

// ==================================================================================
// Validación local (sin llamar a Meta)
// ==================================================================================

it('rechaza localmente un nombre inválido sin llamar a Meta', function () {
    wtCtx();
    Http::fake();

    $errors = app(WhatsAppTemplateValidator::class)->validate([
        'name' => 'Nombre Con Mayusculas!',
        'language' => 'es',
        'category' => 'UTILITY',
        'body' => 'Hola.',
        'examples' => [],
    ]);

    expect($errors)->toContain(__('El nombre debe ir en minúsculas, solo letras a-z, números y guiones bajos (máx. 512).'));
    Http::assertNothingSent();
});

it('rechaza un duplicado de nombre+idioma en el mismo canal (diseñador)', function () {
    [, $user, $channel] = wtCtx();
    SocialWhatsAppTemplate::factory()->create(['social_channel_id' => $channel->id, 'name' => 'repetida', 'language' => 'es']);
    Http::fake();

    Livewire::actingAs($user)->test(WhatsAppTemplates::class)
        ->call('startCreate')
        ->set('name', 'repetida')
        ->set('language', 'es')
        ->set('body', 'Hola de nuevo.')
        ->call('submit');

    Http::assertNothingSent();
    expect(SocialWhatsAppTemplate::query()->where('name', 'repetida')->count())->toBe(1);
});

it('detecta las variables y rechaza dos variables adyacentes', function () {
    $validator = app(WhatsAppTemplateValidator::class);

    expect($validator->variables('Hola {{1}}, tu curso {{2}} inicia {{3}}.'))->toBe([1, 2, 3]);

    $errors = $validator->validate([
        'name' => 'variables_pegadas',
        'language' => 'es',
        'category' => 'UTILITY',
        'body' => 'Datos: {{1}}{{2}}',
        'examples' => [1 => 'a', 2 => 'b'],
    ]);
    expect($errors)->toContain(__('No puede haber dos variables seguidas sin texto o puntuación entre ellas.'));
});

it('exige un valor de ejemplo por cada variable', function () {
    $errors = app(WhatsAppTemplateValidator::class)->validate([
        'name' => 'sin_ejemplos',
        'language' => 'es',
        'category' => 'UTILITY',
        'body' => 'Hola {{1}}, tu tramite {{2}} avanza.',
        'examples' => [1 => 'Carlos'], // falta el de {{2}}
    ]);

    expect($errors)->toContain(__('Falta el valor de ejemplo de la variable {{:n}} (Meta lo exige para la revisión).', ['n' => 2]));
});

it('AUTHENTICATION no puede crearse desde el diseñador (solo sync)', function () {
    $errors = app(WhatsAppTemplateValidator::class)->validate([
        'name' => 'codigo_acceso',
        'language' => 'es',
        'category' => 'AUTHENTICATION',
        'body' => 'Tu código es {{1}}.',
        'examples' => [1 => '123456'],
    ]);

    expect(implode(' ', $errors))->toContain('AUTHENTICATION');
});

// ==================================================================================
// Selección en la bandeja (solo APPROVED) + envío
// ==================================================================================

it('solo una plantilla APPROVED es seleccionable; PENDING y REJECTED no', function () {
    [, $user, $channel, $conversation] = wtCtx();
    $approved = SocialWhatsAppTemplate::factory()->approved()->create(['social_channel_id' => $channel->id, 'name' => 'aprobada']);
    $pending = SocialWhatsAppTemplate::factory()->pending()->create(['social_channel_id' => $channel->id, 'name' => 'pendiente']);
    $rejected = SocialWhatsAppTemplate::factory()->create(['social_channel_id' => $channel->id, 'name' => 'rechazada', 'status' => 'REJECTED']);

    $component = Livewire::actingAs($user)->test(Inbox::class)
        ->call('select', $conversation->id)
        ->call('openTemplates')
        ->call('chooseTemplate', $pending->id)
        ->assertSet('templateId', null)
        ->call('chooseTemplate', $rejected->id)
        ->assertSet('templateId', null)
        ->call('chooseTemplate', $approved->id)
        ->assertSet('templateId', $approved->id);

    $component->assertSee('aprobada');
});

it('envía la plantilla: payload oficial type=template, wamid persistido y metadata segura', function () {
    [, $user, $channel, $conversation] = wtCtx();
    $template = SocialWhatsAppTemplate::factory()->approved()->create([
        'social_channel_id' => $channel->id,
        'name' => 'confirmacion_solicitud',
        'language' => 'es',
        'meta_template_id' => '900',
    ]);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TPL_OUT_1']]], 200)]);

    $message = app(SocialOutboundService::class)->sendWhatsAppTemplate($conversation, $template, [1 => 'Carlos'], $user);

    expect($message->status)->toBe('sent');
    expect($message->type)->toBe('template');
    expect($message->external_message_id)->toBe('wamid.TPL_OUT_1'); // reconciliable por statuses
    expect($message->body)->toBe('Hola Carlos, tu solicitud fue recibida.'); // renderizado
    $meta = $message->attachments[0];
    expect($meta['template_name'])->toBe('confirmacion_solicitud');
    expect($meta['template_language'])->toBe('es');
    expect($meta['template_id'])->toBe('900');
    expect($meta['template_parameters'])->toBe([1 => 'Carlos']);
    expect(json_encode($message->attachments))->not->toContain('WA_TOKEN');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://graph.facebook.com/v26.0/PHONE_T1/messages'
            && $request['messaging_product'] === 'whatsapp'
            && $request['type'] === 'template'
            && $request['template']['name'] === 'confirmacion_solicitud'
            && $request['template']['language']['code'] === 'es'
            && $request['template']['components'][0]['type'] === 'body'
            && $request['template']['components'][0]['parameters'][0] === ['type' => 'text', 'text' => 'Carlos'];
    });
});

it('una plantilla no aprobada no se envía (backend)', function () {
    [, $user, $channel, $conversation] = wtCtx();
    $pending = SocialWhatsAppTemplate::factory()->pending()->create(['social_channel_id' => $channel->id]);
    Http::fake();

    expect(fn () => app(SocialOutboundService::class)->sendWhatsAppTemplate($conversation, $pending, [1 => 'x'], $user))
        ->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

// ==================================================================================
// Webhooks de plantillas
// ==================================================================================

dataset('wt_status_events', [
    'approved' => ['APPROVED', null],
    'rejected' => ['REJECTED', 'INVALID_FORMAT'],
    'paused' => ['PAUSED', null],
    'disabled' => ['DISABLED', null],
]);

it('el webhook de estado actualiza la plantilla local sin duplicarla', function (string $event, ?string $reason) {
    [, , $channel] = wtCtx();
    $template = SocialWhatsAppTemplate::factory()->pending()->create([
        'social_channel_id' => $channel->id,
        'meta_template_id' => '900',
        'name' => 'confirmacion_solicitud',
        'language' => 'es',
    ]);
    Http::fake();

    $value = [
        'event' => $event,
        'message_template_id' => '900',
        'message_template_name' => 'confirmacion_solicitud',
        'message_template_language' => 'es',
        'reason' => $reason ?? 'NONE',
    ];
    $res = wtPostWebhook(wtTemplateWebhook('message_template_status_update', $value));

    $res->assertOk()->assertJsonPath('templates', 1);
    expect(SocialWhatsAppTemplate::query()->count())->toBe(1); // sin duplicados
    $template->refresh();
    expect($template->status)->toBe($event);
    expect($template->rejection_reason)->toBe($reason);
})->with('wt_status_events');

it('PENDING_DELETION se tolera vía webhook y esa plantilla NO puede seleccionarse ni enviarse', function () {
    [, $user, $channel, $conversation] = wtCtx();
    $template = SocialWhatsAppTemplate::factory()->approved()->create([
        'social_channel_id' => $channel->id,
        'meta_template_id' => '955',
        'name' => 'por_borrar',
        'language' => 'es',
    ]);
    Http::fake();

    wtPostWebhook(wtTemplateWebhook('message_template_status_update', [
        'event' => 'PENDING_DELETION',
        'message_template_id' => '955',
        'message_template_name' => 'por_borrar',
        'message_template_language' => 'es',
    ]))->assertOk()->assertJsonPath('templates', 1);

    expect($template->refresh()->status)->toBe('PENDING_DELETION');

    // No seleccionable en la bandeja…
    Livewire::actingAs($user)->test(Inbox::class)
        ->call('select', $conversation->id)
        ->call('openTemplates')
        ->call('chooseTemplate', $template->id)
        ->assertSet('templateId', null);

    // …ni enviable por backend.
    expect(fn () => app(SocialOutboundService::class)->sendWhatsAppTemplate($conversation, $template, [1 => 'x'], $user))
        ->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

it('un status futuro DESCONOCIDO no es error fatal: se guarda tal cual', function () {
    [, , $channel] = wtCtx();
    $template = SocialWhatsAppTemplate::factory()->approved()->create([
        'social_channel_id' => $channel->id,
        'meta_template_id' => '956',
    ]);
    Http::fake();

    wtPostWebhook(wtTemplateWebhook('message_template_status_update', [
        'event' => 'ESTADO_FUTURO_DE_META',
        'message_template_id' => '956',
        'message_template_name' => $template->name,
        'message_template_language' => $template->language,
    ]))->assertOk();

    expect($template->refresh()->status)->toBe('ESTADO_FUTURO_DE_META');
});

it('el webhook de recategorización actualiza la categoría', function () {
    [, , $channel] = wtCtx();
    $template = SocialWhatsAppTemplate::factory()->approved()->create([
        'social_channel_id' => $channel->id,
        'meta_template_id' => '901',
        'category' => 'UTILITY',
    ]);
    Http::fake();

    wtPostWebhook(wtTemplateWebhook('template_category_update', [
        'message_template_id' => '901',
        'message_template_name' => $template->name,
        'message_template_language' => $template->language,
        'previous_category' => 'UTILITY',
        'new_category' => 'MARKETING',
    ]))->assertOk();

    expect($template->refresh()->category)->toBe('MARKETING');
});

it('un evento de plantilla DESCONOCIDA crea un stub seguro (el sync la completa)', function () {
    wtCtx();
    Http::fake();

    wtPostWebhook(wtTemplateWebhook('message_template_status_update', [
        'event' => 'APPROVED',
        'message_template_id' => '777',
        'message_template_name' => 'nueva_desde_meta',
        'message_template_language' => 'en_US',
    ]))->assertOk();

    $stub = SocialWhatsAppTemplate::query()->where('name', 'nueva_desde_meta')->first();
    expect($stub)->not->toBeNull();
    expect($stub->status)->toBe('APPROVED');
    expect($stub->meta_template_id)->toBe('777');
});

it('las plantillas quedan aisladas por institución', function () {
    [, , $channel] = wtCtx();
    $template = SocialWhatsAppTemplate::factory()->approved()->create(['social_channel_id' => $channel->id]);

    $otra = Institution::factory()->create();
    app(CurrentInstitution::class)->set($otra->id);

    expect(SocialWhatsAppTemplate::query()->find($template->id))->toBeNull();
});

it('un error del API de plantillas llega saneado (error_user_msg) y sin token en logs', function () {
    [, , $channel] = wtCtx();
    Http::fake(['graph.facebook.com/*' => Http::response([
        'error' => ['message' => 'Invalid parameter', 'code' => 100, 'error_user_msg' => 'El nombre ya existe en la WABA.'],
    ], 400)]);
    $logged = [];
    Log::listen(function ($event) use (&$logged) {
        $logged[] = $event->message.' '.json_encode($event->context);
    });

    try {
        app(WhatsAppTemplateService::class)->create($channel, [
            'name' => 'duplicada_en_meta', 'language' => 'es', 'category' => 'UTILITY',
            'headerFormat' => '', 'headerText' => '', 'body' => 'Hola.', 'footer' => '',
            'buttons' => [], 'examples' => [], 'headerExample' => '',
        ]);
        $this->fail('Debió lanzar RuntimeException');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('El nombre ya existe en la WABA.');
    }

    expect(implode(' ', $logged))->not->toContain('WA_TOKEN');
});

// ==================================================================================
// Ventana de 24h en la bandeja
// ==================================================================================

it('con el último inbound hace MENOS de 24h se permite el mensaje libre', function () {
    [, $user, , $conversation] = wtCtx();
    wtInbound($conversation, now()->subHours(23));
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.FREE_1']]], 200)]);

    Livewire::actingAs($user)->test(Inbox::class)
        ->call('select', $conversation->id)
        ->set('draft', 'respuesta libre')
        ->call('send')
        ->assertHasNoErrors();

    expect(SocialMessage::query()->where('direction', 'outbound')->where('status', 'sent')->count())->toBe(1);
});

it('con MÁS de 24h el mensaje libre se bloquea en UI y backend', function () {
    [, $user, , $conversation] = wtCtx();
    wtInbound($conversation, now()->subHours(25));
    Http::fake();

    Livewire::actingAs($user)->test(Inbox::class)
        ->call('select', $conversation->id)
        ->assertSee(__('La ventana de atención de 24 horas ha finalizado.'))
        ->assertSee(__('Seleccionar plantilla'))
        ->set('draft', 'no debería salir')
        ->call('send')
        ->assertHasErrors('draft');

    Http::assertNothingSent();
    expect(SocialMessage::query()->where('direction', 'outbound')->count())->toBe(0);
});

it('con MÁS de 24h la plantilla aprobada SÍ se envía desde el selector', function () {
    [, $user, $channel, $conversation] = wtCtx();
    wtInbound($conversation, now()->subDays(3));
    $template = SocialWhatsAppTemplate::factory()->approved()->create(['social_channel_id' => $channel->id]);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TPL_WIN_1']]], 200)]);

    Livewire::actingAs($user)->test(Inbox::class)
        ->call('select', $conversation->id)
        ->call('openTemplates')
        ->call('chooseTemplate', $template->id)
        ->set('templateParams.1', 'Valentina')
        ->call('sendTemplate')
        ->assertHasNoErrors()
        ->assertSet('showTemplates', false);

    $msg = SocialMessage::query()->where('direction', 'outbound')->first();
    expect($msg->status)->toBe('sent');
    expect($msg->external_message_id)->toBe('wamid.TPL_WIN_1');
});

it('frontera exacta de 24h en UTC: 23h59 abierta, 24h01 cerrada', function () {
    [, $user, , $conversation] = wtCtx();
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.EDGE_1']]], 200)]);

    // 23h59: abierta (instantes absolutos en UTC, sin hora local ingenua).
    $inbound = wtInbound($conversation, now('UTC')->subHours(24)->addMinute());
    Livewire::actingAs($user)->test(Inbox::class)
        ->call('select', $conversation->id)
        ->set('draft', 'dentro de ventana')
        ->call('send')
        ->assertHasNoErrors();

    // 24h01: cerrada.
    $inbound->provider_timestamp = now('UTC')->subHours(24)->subMinute();
    $inbound->save();
    Livewire::actingAs($user)->test(Inbox::class)
        ->call('select', $conversation->id)
        ->set('draft', 'fuera de ventana')
        ->call('send')
        ->assertHasErrors('draft');
});

it('los parámetros de la plantilla se persisten de forma segura (sin token ni payload)', function () {
    [, $user, $channel, $conversation] = wtCtx();
    $template = SocialWhatsAppTemplate::factory()->approved()->create(['social_channel_id' => $channel->id]);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.SAFE_1']]], 200)]);

    $message = app(SocialOutboundService::class)->sendWhatsAppTemplate($conversation, $template, [1 => 'Valentina'], $user);

    $raw = json_encode($message->attachments);
    expect($message->attachments[0]['template_parameters'])->toBe([1 => 'Valentina']);
    expect($raw)->not->toContain('WA_TOKEN');
    expect($raw)->not->toContain('Authorization');
    expect($raw)->not->toContain('messaging_product'); // no se guarda el payload completo
});
