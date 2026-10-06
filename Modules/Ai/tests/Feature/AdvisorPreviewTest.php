<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Ai\Livewire\Advisor\Form;
use Modules\Ai\Livewire\Advisor\Preview;
use Modules\Ai\Models\AdvisorFeedback;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Ai\Services\AdvisorPreviewLinkService;
use Modules\Ai\Services\AiChatClient;
use Modules\Ai\Tests\Support\FakeAiChatClient;
use Modules\Catalog\Models\Program;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Models\Event;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\Message;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;
use Modules\Integrations\Models\AiProcessConfig;
use Modules\Integrations\Models\Integration;

/**
 * «Probar asesor»: enlace privado por asesor que conversa con el asesor REAL por el canal de
 * prueba (is_test), sin tocar producción, con valoración de respuestas.
 *
 * @return array{0: Institution, 1: Bot, 2: string} institución, asesor (INACTIVO), token
 */
function pvCtx(array $botAttrs = []): array
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);

    $bot = Bot::factory()->create($botAttrs + [
        'assistant_name' => 'Sofía', 'status' => 'inactive', 'default_language' => 'es',
        'widget_welcome_es' => 'Hola 👋 Soy Sofía. ¿Te ayudo a elegir tu programa?', 'widget_button_es' => '¡Conversemos!',
    ]);
    $integration = Integration::factory()->create(['type' => 'ai_provider', 'provider' => 'qwen', 'status' => 'active']);
    AiProcessConfig::factory()->create(['bot_id' => $bot->id, 'process' => 'conversation', 'integration_id' => $integration->id, 'model' => 'qwen-plus-prueba', 'status' => 'active']);

    $own = KnowledgeSource::factory()->create(['bot_id' => null, 'code' => 'KB-SOFIA-1', 'status' => 'active', 'priority' => 10,
        'content_es' => "## Duracion\nCada Diploma Avanzado dura 16 semanas a ritmo propio."]);
    $bot->knowledgeSources()->attach($own->id, ['is_active' => true]);
    // Fuente de OTRO asesor: nunca debe entrar en el prompt de este.
    $otherBot = Bot::factory()->create(['assistant_name' => 'Otro']);
    $other = KnowledgeSource::factory()->create(['bot_id' => null, 'code' => 'KB-OTRO-1', 'status' => 'active',
        'content_es' => "## Duracion\nTexto secreto del otro asesor sobre la duracion."]);
    $otherBot->knowledgeSources()->attach($other->id, ['is_active' => true]);

    $token = app(AdvisorPreviewLinkService::class)->generate($bot);

    return [$inst, $bot, $token];
}

function pvFake(string $content = '{"reply": "Dura 16 semanas.", "action": "answer"}'): FakeAiChatClient
{
    $fake = new FakeAiChatClient($content);
    app()->instance(AiChatClient::class, $fake);

    return $fake;
}

/** Abre la página y la conversación de prueba. */
function pvChat(string $token): Testable
{
    return Livewire::test(Preview::class, ['token' => $token])->call('start');
}

// --- Enlace -------------------------------------------------------------------------------

it('el enlace válido abre la página de prueba con la identidad y los textos del asesor', function () {
    [, $bot, $token] = pvCtx();

    test()->get('/asesores/prueba/'.$token)->assertOk()
        ->assertSee('Modo de prueba')->assertSee('Sofía')
        ->assertSee('Hola 👋 Soy Sofía. ¿Te ayudo a elegir tu programa?')
        ->assertSee('¡Conversemos!')
        ->assertSee('<meta name="referrer" content="no-referrer">', false)
        ->assertSee('noindex', false);
});

it('rechaza enlaces inválidos, revocados o sustituidos (404 sin pistas)', function () {
    [, $bot, $token] = pvCtx();
    $links = app(AdvisorPreviewLinkService::class);

    test()->get('/asesores/prueba/'.str_repeat('A', 48))->assertNotFound();
    test()->get('/asesores/prueba/'.substr($token, 0, 47))->assertNotFound();

    $new = $links->generate($bot);            // regenerar: el anterior deja de valer
    test()->get('/asesores/prueba/'.$token)->assertNotFound();
    test()->get('/asesores/prueba/'.$new)->assertOk();

    $component = Livewire::test(Preview::class, ['token' => $new]);
    $links->revoke($bot->fresh());            // revocar: deja de funcionar al instante
    test()->get('/asesores/prueba/'.$new)->assertNotFound();
    $component->call('start')->assertStatus(404);
});

it('no permite enumerar asesores: sin ids en la URL y el token solo se guarda como hash', function () {
    [, $bot, $token] = pvCtx();

    test()->get('/asesores/prueba/'.$bot->id)->assertNotFound();
    test()->get('/asesores/prueba/'.$bot->public_key)->assertNotFound();

    $url = (string) app(AdvisorPreviewLinkService::class)->url($bot->fresh());
    expect($url)->toEndWith('/asesores/prueba/'.$token)
        ->not->toContain('/'.$bot->id.'/')->not->toContain($bot->public_key);

    $row = \Illuminate\Support\Facades\DB::table('bots')->where('id', $bot->id)->first();
    expect($row->preview_token_hash)->toBe(hash('sha256', $token))
        ->and($row->preview_token)->not->toBe($token)->not->toContain($token)   // copia cifrada
        ->and($bot->fresh()->toArray())->not->toHaveKey('preview_token');        // nunca serializado
});

it('un enlace solo abre su asesor, aunque sea de otra institución', function () {
    [, $botA, $tokenA] = pvCtx();
    $instB = Institution::factory()->create();
    [$botB, $tokenB] = app(CurrentInstitution::class)->runFor($instB->id, function () {
        $b = Bot::factory()->create(['assistant_name' => 'Asesor B', 'status' => 'inactive']);

        return [$b, app(AdvisorPreviewLinkService::class)->generate($b)];
    });
    app(CurrentInstitution::class)->forget();

    test()->get('/asesores/prueba/'.$tokenB)->assertOk()->assertSee('Asesor B')->assertDontSee('Sofía');
    test()->get('/asesores/prueba/'.$tokenA)->assertOk()->assertSee('Sofía')->assertDontSee('Asesor B');
});

// --- Conversación real por el canal de prueba ------------------------------------------------

it('inicia y continúa una conversación de prueba con el asesor, su prompt, modelo y fuentes', function () {
    [, $bot, $token] = pvCtx();
    $fake = pvFake();

    $chat = pvChat($token)
        ->assertSee('Sofía') // saludo plantilla del asesor (sin IA)
        ->set('draft', '¿Cuánto dura un diploma?')->call('send')->assertHasNoErrors()
        ->assertSee('Dura 16 semanas.');

    $fake->willReturn('{"reply": "Es a ritmo propio.", "action": "answer"}');
    $chat->set('draft', '¿Y es online?')->call('send')->assertSee('Es a ritmo propio.');

    expect($fake->calls)->toHaveCount(2);
    $call = $fake->calls[1];
    $system = $call['messages'][0]['content'];
    expect($call['model'])->toBe('qwen-plus-prueba')
        ->and($call['context']?->botId)->toBe($bot->id)
        ->and($call['context']?->process)->toBe('conversation_test')
        ->and($system)->toContain('REGLAS INQUEBRANTABLES')                // mismo system prompt
        ->and($system)->toContain('16 semanas a ritmo propio')             // fuente del asesor
        ->and($system)->not->toContain('Texto secreto del otro asesor')   // nunca la de otro
        ->and(collect($call['messages'])->pluck('content')->all())->toContain('¿Cuánto dura un diploma?', 'Dura 16 semanas.'); // memoria

    $reply = Message::query()->where('sender_type', 'celia')->where('message_type', 'ai')->latest('id')->first();
    expect($reply->meta['knowledge_sources'])->toBe(['KB-SOFIA-1']);
});

it('la conversación queda como prueba (channel=preview, is_test) y separada de producción', function () {
    [, $bot, $token] = pvCtx();
    pvFake();

    $chat = pvChat($token)->set('draft', 'Hola')->call('send');

    $conversation = Conversation::query()->sole();
    expect($conversation->channel)->toBe('preview')
        ->and($conversation->is_test)->toBeTrue()
        ->and($conversation->external_id)->toBe($chat->get('sessionKey'))
        ->and($conversation->contact_id)->toBeNull()
        ->and($conversation->bot_id)->toBe($bot->id);

    // El dashboard (métricas de conversaciones) no la cuenta.
    expect(Conversation::query()->where('is_test', false)->count())->toBe(0);
});

it('no crea leads, contactos ni eventos aunque el mensaje dispare reglas comerciales', function () {
    [, $bot, $token] = pvCtx();
    $program = Program::factory()->create(['url' => 'https://mcaschool.test/programa-x', 'status' => 'active']);
    pvFake('{"reply": "Mira https://mcaschool.test/programa-x", "action": "unresolved"}');

    pvChat($token)
        ->set('draft', 'Quiero capacitar a los empleados de mi empresa con un plan corporativo')->call('send')
        ->set('draft', '¿Precio?')->call('send');

    expect(Lead::query()->count())->toBe(0)
        ->and(Contact::query()->count())->toBe(0)
        ->and(Event::query()->count())->toBe(0); // ni corporate_interest, ni unresolved, ni program_interest, ni started_celia
});

it('no envía nada a canales externos ni dispara correos, colas o webhooks', function () {
    [, , $token] = pvCtx();
    pvFake();
    Http::fake();
    Mail::fake();
    Notification::fake();
    Queue::fake();

    pvChat($token)->set('draft', 'Hola')->call('send')->call('restart');

    Http::assertNothingSent();
    Mail::assertNothingSent();
    Notification::assertNothingSent();
    Queue::assertNothingPushed();
});

it('reiniciar empieza otra conversación de prueba y cierra la anterior', function () {
    [, , $token] = pvCtx();
    pvFake();

    $chat = pvChat($token)->set('draft', 'Primera pregunta')->call('send')->assertSee('Primera pregunta');
    $first = $chat->get('sessionKey');

    $chat->call('restart')->assertSet('sessionKey', null)->assertDontSee('Primera pregunta')->assertSee('¡Conversemos!');
    $chat->call('start');

    expect($chat->get('sessionKey'))->not->toBe($first)
        ->and(Conversation::query()->where('external_id', $first)->value('status'))->toBe('closed')
        ->and(Conversation::query()->where('status', 'open')->count())->toBe(1);
});

it('la conversación sobrevive a recargar la página (misma sesión del navegador)', function () {
    [, , $token] = pvCtx();
    pvFake();

    pvChat($token)->set('draft', 'Pregunta persistente')->call('send');

    Livewire::test(Preview::class, ['token' => $token])->assertSee('Pregunta persistente')->assertSee('Dura 16 semanas.');
});

it('un error del proveedor se explica sin claves, prompts ni trazas', function () {
    [, , $token] = pvCtx();
    pvFake()->willThrow();

    pvChat($token)->set('draft', 'Hola')->call('send')
        ->assertSee('El proveedor de IA no respondió')
        ->assertDontSee('Fallo simulado')->assertDontSee('REGLAS INQUEBRANTABLES');
});

// --- Valoración ---------------------------------------------------------------------------

it('registra la valoración de una respuesta con institución, asesor, sesión, mensaje, usuario y observación', function () {
    [$inst, $bot, $token] = pvCtx();
    pvFake();
    $evaluator = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);

    $chat = pvChat($token)->set('draft', 'Hola')->call('send');
    $reply = Message::query()->where('sender_type', 'celia')->where('message_type', 'ai')->sole();

    $this->actingAs($evaluator);
    $chat->call('rate', $reply->id, 'correct');
    expect(AdvisorFeedback::query()->sole()->only(['rating', 'comment']))->toBe(['rating' => 'correct', 'comment' => null]);

    $chat->call('rate', $reply->id, 'needs_improvement')->assertSet('noteFor', $reply->id)
        ->set('note', 'Faltó el enlace a inscripciones.')->call('saveNote');

    $fb = AdvisorFeedback::query()->sole();
    expect($fb->institution_id)->toBe($inst->id)
        ->and($fb->bot_id)->toBe($bot->id)
        ->and($fb->conversation_id)->toBe($reply->conversation_id)
        ->and($fb->message_id)->toBe($reply->id)
        ->and($fb->rating)->toBe('needs_improvement')
        ->and($fb->comment)->toBe('Faltó el enlace a inscripciones.')
        ->and($fb->user_id)->toBe($evaluator->id)
        ->and($fb->created_at)->not->toBeNull();

    // Es evidencia: no cambia la configuración del asesor ni su conocimiento.
    expect(AiProcessConfig::query()->where('bot_id', $bot->id)->value('model'))->toBe('qwen-plus-prueba')
        ->and($bot->knowledgeSources()->pluck('code')->all())->toBe(['KB-SOFIA-1']);

    // La ficha muestra el resumen.
    Livewire::actingAs($evaluator)->test(Form::class, ['bot' => $bot])
        ->assertSee('Valoraciones del equipo')->assertSee('Faltó el enlace a inscripciones.');
});

it('la valoración se aísla por institución y no admite mensajes ajenos a la conversación', function () {
    [$instA, $botA, $tokenA] = pvCtx();
    pvFake();
    $chat = pvChat($tokenA)->set('draft', 'Hola')->call('send');
    $reply = Message::query()->where('sender_type', 'celia')->where('message_type', 'ai')->sole();
    $chat->call('rate', $reply->id, 'needs_improvement')->set('note', 'Observación de A')->call('saveNote');

    // Un mensaje de OTRA conversación (forzando su id) no se puede valorar desde este enlace.
    $instB = Institution::factory()->create();
    [$tokenB, $foreign] = app(CurrentInstitution::class)->runFor($instB->id, function () {
        $b = Bot::factory()->create(['status' => 'inactive', 'assistant_name' => 'B']);
        $c = Conversation::factory()->create(['bot_id' => $b->id]);
        $m = Message::factory()->create(['conversation_id' => $c->id, 'sender_type' => 'celia']);

        return [app(AdvisorPreviewLinkService::class)->generate($b), $m];
    });
    Livewire::test(Preview::class, ['token' => $tokenB])->call('start')->call('rate', $reply->id, 'correct');
    pvChat($tokenA)->call('rate', $foreign->id, 'correct');

    app(CurrentInstitution::class)->set($instB->id);
    expect(AdvisorFeedback::query()->count())->toBe(0);
    app(CurrentInstitution::class)->set($instA->id);
    expect(AdvisorFeedback::query()->sole()->only(['rating', 'comment']))->toBe(['rating' => 'needs_improvement', 'comment' => 'Observación de A']);
});

// --- Ficha: «Probar asesor» -------------------------------------------------------------------

it('la ficha genera, muestra (nueva pestaña), regenera y revoca el enlace; solo un administrador', function () {
    [$inst, $bot] = pvCtx();
    app(AdvisorPreviewLinkService::class)->revoke($bot);
    $admin = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);

    $form = Livewire::actingAs($admin)->test(Form::class, ['bot' => $bot->fresh()])
        ->assertSee('Probar asesor')->assertSee('Generar enlace de prueba')
        ->call('generatePreviewLink');
    $url = (string) app(AdvisorPreviewLinkService::class)->url($bot->fresh());
    $form->assertSee('Abrir prueba en nueva pestaña')->assertSeeHtml('href="'.e($url).'" target="_blank" rel="noopener noreferrer"');

    $form->call('generatePreviewLink');
    expect(app(AdvisorPreviewLinkService::class)->url($bot->fresh()))->not->toBe($url);

    $form->call('revokePreviewLink');
    expect($bot->fresh()->preview_token_hash)->toBeNull();

    $marketing = User::factory()->create(['institution_id' => $inst->id, 'role' => 'marketing']);
    Livewire::actingAs($marketing)->test(Form::class, ['bot' => $bot->fresh()])->assertForbidden();
});
