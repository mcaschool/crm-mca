<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Ai\Models\AdvisorMessageReceipt;
use Modules\Ai\Services\AiChatClient;
use Modules\Ai\Tests\Support\FakeAiChatClient;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;
use Modules\Integrations\Models\AiProcessConfig;
use Modules\Integrations\Models\Integration;
use Modules\Social\Exceptions\AdvisorAssignmentConflict;
use Modules\Social\Jobs\RespondWithAdvisor;
use Modules\Social\Livewire\AdvisorChannels;
use Modules\Social\Livewire\Channels;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;
use Modules\Social\Services\AdvisorChannelAssignment;
use Modules\Social\Services\AdvisorCutoff;
use Modules\Social\Services\SocialAutomationService;
use Modules\Social\Support\AdvisorDispatcher;

/**
 * CORTE determinista del asesor en una CUENTA completa (activar, volver a activar, reasignar) con la
 * MISMA implementación que la reactivación de una conversación (AdvisorCutoff): todo lo recibido
 * antes queda con su recibo «before_activation» y nunca se responde ni se agrupa, aunque ocurra en
 * el mismo segundo. Todos los eventos con el tiempo CONGELADO en un segundo y cola persistente.
 */
const SCUT_EXT = ['whatsapp' => 'demo_wa_phone', 'messenger' => 'demo_fb_page'];
const SCUT_SENDER = ['whatsapp' => '5215559990001', 'messenger' => 'psid_8887701'];

/** @return array{0: Institution, 1: Bot, 2: Bot, 3: FakeAiChatClient, 4: User} */
function scutCtx(bool $enabled = false): array
{
    config(['social.app_secret' => 'scut_secret', 'social.secrets.instagram' => 'scut_secret', 'social.advisor.autoreply_enabled' => true, 'queue.default' => 'database']);
    Http::fake([
        'graph.facebook.com/*/me/messages' => fn () => Http::response(['message_id' => 'm_bot_'.Str::random(10)]),
        'graph.facebook.com/*/messages' => fn () => Http::response(['messages' => [['id' => 'wamid.BOT'.Str::random(10)]]]),
        '*' => Http::response([]),
    ]);
    AdvisorDispatcher::touch(); // worker vivo: activar exige el despacho listo

    $inst = Institution::factory()->create();
    [$botA, $botB] = app(CurrentInstitution::class)->runFor($inst->id, function () use ($enabled): array {
        $integration = Integration::factory()->create(['type' => 'ai_provider', 'provider' => 'qwen', 'status' => 'active']);
        $bots = [];
        foreach (['Sophia', 'Celia'] as $name) {
            $bot = Bot::factory()->create(['status' => 'active', 'assistant_name' => $name, 'typing_delay' => 0]);
            AiProcessConfig::factory()->create(['bot_id' => $bot->id, 'process' => 'conversation', 'integration_id' => $integration->id, 'model' => 'qwen-plus', 'status' => 'active']);
            $bots[] = $bot;
        }
        foreach (SCUT_EXT as $provider => $ext) {
            SocialChannel::factory()->create(['provider' => $provider, 'external_id' => $ext, 'advisor_bot_id' => $bots[0]->id, 'advisor_enabled' => $enabled]);
        }

        return $bots;
    });

    $fake = new FakeAiChatClient('{"reply": "Respuesta del asesor.", "action": "answer"}');
    app()->instance(AiChatClient::class, $fake);

    return [$inst, $botA, $botB, $fake, User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin'])];
}

/** Mensaje entrante real del proveedor con otro texto, id y (opcional) otro contacto. */
function scutPost(string $provider, string $text, ?string $sender = null, string $secret = 'scut_secret', ?string $ext = null): void
{
    $raw = (string) file_get_contents(__DIR__.'/../Fixtures/'.$provider.'.json');
    $raw = str_replace(['1757001000000', '"1757001000"'], [(string) now()->getTimestampMs(), '"'.now()->getTimestamp().'"'], $raw);
    $raw = str_replace(
        ['wamid.HBgTEST00000001', 'm_MSGR00000001', 'Hola! Vi el diploma en liderazgo en Facebook, ¿cuándo inicia el próximo grupo?', 'Hola, ¿la microcredencial en finanzas tiene cupo disponible?'],
        ['wamid.SCUT'.Str::random(12), 'm_SCUT'.Str::random(12), $text, $text],
        $raw,
    );
    if ($sender !== null) {
        $raw = str_replace(SCUT_SENDER[$provider], $sender, $raw);
    }
    if ($ext !== null) {
        $raw = str_replace(SCUT_EXT[$provider], $ext, $raw);
    }
    test()->call('POST', "/api/social/webhook/{$provider}", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, $secret),
    ], $raw)->assertOk();
}

function scutWorker(): void
{
    Artisan::call('social:advisor-worker', ['--stop-when-empty' => true]);
}

function scutIn(Institution $inst, Closure $fn): mixed
{
    return app(CurrentInstitution::class)->runFor($inst->id, $fn);
}

function scutChannel(Institution $inst, string $provider): SocialChannel
{
    return scutIn($inst, fn () => SocialChannel::query()->where('provider', $provider)->firstOrFail());
}

function scutMsg(Institution $inst, string $body): SocialMessage
{
    return scutIn($inst, fn () => SocialMessage::query()->where('direction', 'inbound')->where('body', $body)->sole());
}

/** @return \Illuminate\Support\Collection<int, SocialMessage> */
function scutReplies(Institution $inst): \Illuminate\Support\Collection
{
    return scutIn($inst, fn () => SocialMessage::query()->where('sender_type', 'bot')->orderBy('id')->get());
}

function scutReceipt(Institution $inst, SocialMessage $m): ?string
{
    return scutIn($inst, fn () => AdvisorMessageReceipt::query()->where('external_message_id', $m->external_message_id)->value('status'));
}

/** Llamadas a la IA (texto de usuario enviado), para comprobar que nada anterior al corte se agrupa. */
function scutAiText(FakeAiChatClient $fake): string
{
    return (string) json_encode($fake->calls, JSON_UNESCAPED_UNICODE);
}

it('mensaje A → activar la cuenta → mensaje B: solo responde B (y A nunca se agrupa)', function (string $provider) {
    [$inst, $bot, , $fake, $user] = scutCtx(enabled: false);
    $this->freezeSecond();

    scutPost($provider, 'Mensaje A con la cuenta apagada');
    scutIn($inst, fn () => app(AdvisorChannelAssignment::class)->enable(scutChannel($inst, $provider), $bot, $user));
    scutPost($provider, 'Mensaje B con la cuenta activada');
    scutWorker();

    $a = scutMsg($inst, 'Mensaje A con la cuenta apagada');
    $b = scutMsg($inst, 'Mensaje B con la cuenta activada');
    expect(scutReplies($inst))->toHaveCount(1)
        ->and(scutReplies($inst)->sole()->in_reply_to_id)->toBe($b->id)
        ->and(scutReceipt($inst, $a))->toBe('before_activation')
        ->and($fake->calls)->toHaveCount(1)
        ->and(scutAiText($fake))->toContain('Mensaje B con la cuenta activada')->not->toContain('Mensaje A con la cuenta apagada');

    // Reencolar y repetir el worker no responde A ni duplica B.
    RespondWithAdvisor::dispatch($a->id, $inst->id);
    RespondWithAdvisor::dispatch($b->id, $inst->id);
    scutWorker();
    scutWorker();
    expect(scutReplies($inst))->toHaveCount(1)->and($fake->calls)->toHaveCount(1);
})->with(['whatsapp', 'messenger']);

it('lo mismo desde el formulario del canal (mismo servicio y mismo corte)', function (string $provider) {
    [$inst, $bot, , $fake, $user] = scutCtx(enabled: false);
    $this->freezeSecond();

    scutPost($provider, 'Mensaje A con la cuenta apagada');
    app(CurrentInstitution::class)->set($inst->id);
    Livewire::actingAs($user)->test(Channels::class)->call('editAdvisor', scutChannel($inst, $provider)->id)
        ->set('advisorEnabled', true)->set('advisorBotId', (string) $bot->id)->call('saveAdvisor')->assertHasNoErrors();
    scutPost($provider, 'Mensaje B con la cuenta activada');
    scutWorker();

    expect(scutChannel($inst, $provider)->advisor_enabled)->toBeTrue()
        ->and(scutReplies($inst))->toHaveCount(1)
        ->and(scutReplies($inst)->sole()->in_reply_to_id)->toBe(scutMsg($inst, 'Mensaje B con la cuenta activada')->id)
        ->and(scutReceipt($inst, scutMsg($inst, 'Mensaje A con la cuenta apagada')))->toBe('before_activation')
        ->and(scutAiText($fake))->not->toContain('Mensaje A con la cuenta apagada');
})->with(['whatsapp', 'messenger']);

it('cuenta activa con el asesor A → mensaje pendiente → reasignar al asesor B → mensaje nuevo: B responde solo al nuevo', function (string $provider) {
    [$inst, $botA, $botB, $fake, $user] = scutCtx(enabled: true);
    $this->freezeSecond();

    scutPost($provider, 'Mensaje pendiente para el asesor A');                // su job queda en la cola
    scutIn($inst, fn () => app(AdvisorChannelAssignment::class)->reassign(scutChannel($inst, $provider), $botB, $user, $botA->id));
    scutPost($provider, 'Mensaje nuevo para el asesor B');
    scutWorker();

    $pending = scutMsg($inst, 'Mensaje pendiente para el asesor A');
    $new = scutMsg($inst, 'Mensaje nuevo para el asesor B');
    $reply = scutReplies($inst)->sole();
    expect($reply->in_reply_to_id)->toBe($new->id)
        ->and($reply->ai_bot_id)->toBe($botB->id)
        ->and(scutReceipt($inst, $pending))->toBe('before_activation')
        ->and(scutAiText($fake))->not->toContain('Mensaje pendiente para el asesor A');

    RespondWithAdvisor::dispatch($pending->id, $inst->id);
    scutWorker();
    expect(scutReplies($inst))->toHaveCount(1);
})->with(['whatsapp', 'messenger']);

it('desactivar → recibir → volver a activar → recibir otro: solo responde el posterior (también el job pendiente de antes de desactivar queda omitido)', function (string $provider) {
    [$inst, $bot, , $fake, $user] = scutCtx(enabled: true);
    $this->freezeSecond();
    $assignment = app(AdvisorChannelAssignment::class);

    scutPost($provider, 'Mensaje con job pendiente antes de desactivar');
    scutIn($inst, fn () => $assignment->disable(scutChannel($inst, $provider), $bot, $user));
    scutPost($provider, 'Mensaje durante la desactivación');
    scutIn($inst, fn () => $assignment->enable(scutChannel($inst, $provider), $bot, $user));
    scutPost($provider, 'Mensaje después de volver a activar');
    scutWorker();
    scutWorker();

    expect(scutReplies($inst))->toHaveCount(1)
        ->and(scutReplies($inst)->sole()->in_reply_to_id)->toBe(scutMsg($inst, 'Mensaje después de volver a activar')->id)
        ->and(scutReceipt($inst, scutMsg($inst, 'Mensaje durante la desactivación')))->toBe('before_activation')
        ->and(scutReceipt($inst, scutMsg($inst, 'Mensaje con job pendiente antes de desactivar')))->toBe('before_activation')
        ->and(scutAiText($fake))->not->toContain('durante la desactivación')->not->toContain('antes de desactivar');
})->with(['whatsapp', 'messenger']);

it('varias conversaciones de la misma cuenta: el corte alcanza a todas y cada una responde solo a lo posterior', function (string $provider) {
    [$inst, $bot, , $fake, $user] = scutCtx(enabled: false);
    $this->freezeSecond();
    $second = $provider === 'whatsapp' ? '5215550000002' : 'psid_0000002';

    scutPost($provider, 'Contacto 1 antes');
    scutPost($provider, 'Contacto 2 antes', $second);
    scutIn($inst, fn () => app(AdvisorChannelAssignment::class)->enable(scutChannel($inst, $provider), $bot, $user));
    scutPost($provider, 'Contacto 1 después');
    scutPost($provider, 'Contacto 2 después', $second);
    scutWorker();

    $replies = scutReplies($inst);
    expect(scutIn($inst, fn () => SocialConversation::query()->where('provider', $provider)->count()))->toBe(2)
        ->and($replies)->toHaveCount(2)
        ->and($replies->pluck('in_reply_to_id')->sort()->values()->all())->toBe(collect([scutMsg($inst, 'Contacto 1 después')->id, scutMsg($inst, 'Contacto 2 después')->id])->sort()->values()->all())
        ->and(scutReceipt($inst, scutMsg($inst, 'Contacto 1 antes')))->toBe('before_activation')
        ->and(scutReceipt($inst, scutMsg($inst, 'Contacto 2 antes')))->toBe('before_activation')
        ->and(scutAiText($fake))->not->toContain(' antes');
})->with(['whatsapp', 'messenger']);

it('un worker con turno en CUALQUIER conversación de la cuenta revierte todo: ni asignación ni recibos', function (string $op) {
    [$inst, $botA, $botB, , $user] = scutCtx(enabled: $op === 'reasignar');
    $this->freezeSecond();
    scutPost('messenger', 'Contacto 1 antes');
    scutPost('messenger', 'Contacto 2 antes', 'psid_0000002');
    $channel = scutChannel($inst, 'messenger');
    $fields = ['advisor_enabled', 'advisor_bot_id', 'advisor_assigned_by', 'advisor_assigned_at'];
    $before = $channel->only($fields);

    // El worker tiene el turno de la SEGUNDA conversación (la primera se recorrería antes).
    $busy = scutIn($inst, fn () => SocialConversation::query()->where('provider', 'messenger')->orderByDesc('id')->firstOrFail());
    expect(scutIn($inst, fn () => app(SocialAutomationService::class)->claim($busy)))->toBeTrue();

    $assignment = app(AdvisorChannelAssignment::class);
    expect(fn () => scutIn($inst, fn () => $op === 'activar'
        ? $assignment->enable($channel, $botA, $user)
        : $assignment->reassign($channel, $botB, $user, $botA->id)))->toThrow(AdvisorAssignmentConflict::class, 'Vuelve a intentarlo');

    expect(scutChannel($inst, 'messenger')->only($fields))->toEqual($before)
        ->and(scutIn($inst, fn () => AdvisorMessageReceipt::query()->whereIn('status', ['before_activation', 'before_reactivation'])->count()))->toBe(0);

    // En la ficha del asesor se explica; al terminar el worker, la operación sale bien.
    app(CurrentInstitution::class)->set($inst->id);
    $target = $op === 'activar' ? $botA : $botB;
    $component = Livewire::actingAs($user)->test(AdvisorChannels::class, ['botId' => $target->id]);
    $op === 'activar' ? $component->call('toggle', $channel->id) : $component->call('reassign', $channel->id, $botA->id);
    $component->assertSee('Vuelve a intentarlo en unos segundos');

    app(SocialAutomationService::class)->release($busy);
    $op === 'activar' ? $assignment->enable($channel, $botA, $user) : $assignment->reassign($channel, $botB, $user, $botA->id);
    expect(scutChannel($inst, 'messenger')->advisor_enabled)->toBeTrue()
        ->and((int) scutChannel($inst, 'messenger')->advisor_bot_id)->toBe($target->id)
        ->and(AdvisorMessageReceipt::query()->where('status', 'before_activation')->count())->toBe(2);
})->with(['activar', 'reasignar']);

it('aislamiento: el corte de una cuenta no toca mensajes de otra institución', function () {
    [$inst, $bot, , $fake, $user] = scutCtx(enabled: false);
    $this->freezeSecond();

    // Otra institución con su propia cuenta de Messenger ACTIVADA y un mensaje con job pendiente.
    $other = Institution::factory()->create();
    scutIn($other, function (): void {
        $integration = Integration::factory()->create(['type' => 'ai_provider', 'provider' => 'qwen', 'status' => 'active']);
        $b = Bot::factory()->create(['status' => 'active', 'assistant_name' => 'Otra', 'typing_delay' => 0]);
        AiProcessConfig::factory()->create(['bot_id' => $b->id, 'process' => 'conversation', 'integration_id' => $integration->id, 'model' => 'qwen-plus', 'status' => 'active']);
        SocialChannel::factory()->create(['provider' => 'messenger', 'external_id' => 'other_fb_page', 'advisor_bot_id' => $b->id, 'advisor_enabled' => true]);
    });
    scutPost('messenger', 'Mensaje de la otra institución', 'psid_otra', 'scut_secret', 'other_fb_page');
    scutPost('messenger', 'Mensaje A con la cuenta apagada');

    scutIn($inst, fn () => app(AdvisorChannelAssignment::class)->enable(scutChannel($inst, 'messenger'), $bot, $user));
    scutWorker();

    expect(scutReceipt($inst, scutMsg($inst, 'Mensaje A con la cuenta apagada')))->toBe('before_activation')
        ->and(scutIn($other, fn () => SocialMessage::query()->where('sender_type', 'bot')->count()))->toBe(1)   // la otra institución sí responde a lo suyo
        ->and(scutIn($other, fn () => AdvisorMessageReceipt::query()->whereIn('status', ['before_activation', 'before_reactivation'])->count()))->toBe(0)
        ->and(scutReplies($inst))->toHaveCount(0);
});

it('la reactivación individual de una conversación sigue funcionando con el mismo corte', function (string $provider) {
    [$inst, $bot, , $fake, $user] = scutCtx(enabled: true);
    $this->freezeSecond();

    scutPost($provider, 'Primer mensaje');
    scutWorker();
    $conversation = scutIn($inst, fn () => SocialConversation::query()->where('provider', $provider)->firstOrFail());
    scutIn($inst, fn () => app(SocialAutomationService::class)->takeOver($conversation, $user));
    scutPost($provider, 'Mensaje mientras atiende una persona');
    expect(scutIn($inst, fn () => app(SocialAutomationService::class)->returnToAdvisor($conversation->fresh(), $user)))->toBeTrue();
    scutPost($provider, 'Mensaje tras reactivar');
    scutWorker();

    expect(scutReplies($inst))->toHaveCount(2)
        ->and(scutReplies($inst)->last()->in_reply_to_id)->toBe(scutMsg($inst, 'Mensaje tras reactivar')->id)
        ->and(scutReceipt($inst, scutMsg($inst, 'Mensaje mientras atiende una persona')))->toBe('before_reactivation')
        ->and(scutAiText($fake))->not->toContain('mientras atiende una persona');
})->with(['whatsapp', 'messenger']);

it('una única implementación: la reactivación y la cuenta usan AdvisorCutoff y ninguna ruta compara horas para decidir antes/después', function () {
    $responder = (string) file_get_contents(base_path('Modules/Social/app/Services/SocialAdvisorResponder.php'));
    $automation = (string) file_get_contents(base_path('Modules/Social/app/Services/SocialAutomationService.php'));
    $assignment = (string) file_get_contents(base_path('Modules/Social/app/Services/AdvisorChannelAssignment.php'));

    expect($responder)->not->toContain('->lt($channel->advisor_assigned_at')->not->toContain('->lt($conversation->automation_changed_at')
        ->not->toContain('automation_changed_at->gt(')
        ->and($automation)->toContain('$this->cutoff->conversation(')
        ->and($assignment)->toContain('$this->cutoff->channel(')
        ->and(DB::getSchemaBuilder()->hasTable('advisor_message_receipts'))->toBeTrue()
        ->and(AdvisorCutoff::BEFORE_ACTIVATION)->toBe('before_activation')
        ->and(AdvisorCutoff::BEFORE_REACTIVATION)->toBe('before_reactivation');
});
