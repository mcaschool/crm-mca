<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Ai\Livewire\Advisor\Form;
use Modules\Ai\Livewire\Advisor\Preview;
use Modules\Ai\Models\AdvisorCorrection;
use Modules\Ai\Models\AdvisorFeedback;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Ai\Services\AdvisorCorrections;
use Modules\Ai\Services\AdvisorPreviewLinkService;
use Modules\Ai\Services\AiChatClient;
use Modules\Ai\Tests\Support\FakeAiChatClient;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Message;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;
use Modules\Integrations\Models\AiProcessConfig;
use Modules\Integrations\Models\Integration;

/**
 * «Correcciones aprendidas»: en «Probar asesor», «Necesita mejora» → «Esta es la respuesta
 * correcta» guarda una respuesta APROBADA que el asesor aplica al instante a preguntas
 * equivalentes del mismo tema (o actualiza el saludo inicial). Cualquier asesor e institución.
 */
const ACR_LINK = 'https://mcaschool.education/es/admisiones/admisiones-diplomas-avanzados/';

/** @return array{0: Institution, 1: Bot, 2: string, 3: FakeAiChatClient} */
function acrCtx(string $mode = Bot::RETRIEVAL_PRECISE, string $name = 'Sophia'): array
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);
    $bot = Bot::factory()->withOwnIdentity(['assistant_name' => $name, 'instructions' => 'Asesora de líneas ejecutivas.', 'knowledge_retrieval' => $mode, 'status' => 'inactive', 'default_language' => 'es'])->create();
    foreach ([['DA-INFO', 'diplomas_avanzados', 'Diplomas Avanzados'], ['MMBA-INFO', 'micro_mba', 'Micro MBA']] as [$code, $line, $label]) {
        $s = KnowledgeSource::factory()->create(['bot_id' => null, 'code' => $code, 'category' => $line, 'type' => 'base_conocimiento', 'priority' => 6, 'status' => 'active',
            'content_es' => "## Información general de {$label}\n{$label} es un programa online.\n\n## Cuándo puedo comenzar {$label}\nCuando quieras."]);
        $s->bots()->attach($bot->id, ['is_active' => true]);
    }
    $integration = Integration::factory()->create(['type' => 'ai_provider', 'provider' => 'qwen', 'status' => 'active']);
    AiProcessConfig::factory()->create(['bot_id' => $bot->id, 'process' => 'conversation', 'integration_id' => $integration->id, 'model' => 'qwen-plus', 'status' => 'active']);
    $fake = new FakeAiChatClient('{"reply": "Respuesta del modelo.", "action": "answer"}');
    app()->instance(AiChatClient::class, $fake);

    return [$inst, $bot, app(AdvisorPreviewLinkService::class)->generate($bot), $fake];
}

function acrChat(string $token): Testable
{
    return Livewire::test(Preview::class, ['token' => $token])->call('start');
}

function acrLastReply(): Message
{
    return Message::query()->where('sender_type', 'celia')->orderByDesc('id')->first();
}

/** Corrección guardada directamente (para las reglas de aplicación). */
function acrCorrection(Bot $bot, string $question, ?string $topic, string $answer = 'Respuesta aprobada.', bool $active = true): AdvisorCorrection
{
    return AdvisorCorrection::query()->create(['institution_id' => $bot->institution_id, 'bot_id' => $bot->id, 'question' => $question, 'topic_line' => $topic, 'answer' => $answer, 'active' => $active]);
}

it('«Esta es la respuesta correcta» (por defecto) guarda la corrección con pregunta, tema, respuesta y autor, y se aplica al instante', function () {
    [$inst, $bot, $token, $fake] = acrCtx();
    $admin = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);
    $this->actingAs($admin);

    $chat = acrChat($token)->set('draft', 'Hola, información de los Diplomas Avanzados')->call('send')
        ->set('draft', '¿Cuáles son las próximas fechas de inicio?')->call('send');
    $reply = acrLastReply();
    $chat->call('rate', $reply->id, 'needs_improvement')->assertSet('noteKind', 'approved')
        ->set('note', 'Los Diplomas Avanzados se pueden iniciar cuando quieras. Admisiones: '.ACR_LINK.' .')->call('saveNote')
        ->assertSee('Respuesta aprobada: el asesor ya la usa');

    $c = AdvisorCorrection::query()->sole();
    expect($c->only(['institution_id', 'bot_id', 'question', 'topic_line', 'active', 'user_id']))->toBe([
        'institution_id' => $inst->id, 'bot_id' => $bot->id, 'question' => '¿Cuáles son las próximas fechas de inicio?',
        'topic_line' => 'diplomas_avanzados', 'active' => true, 'user_id' => $admin->id,
    ])->and($c->feedback_id)->toBe(AdvisorFeedback::query()->sole()->id)
        ->and($c->answer)->toContain(ACR_LINK)
        ->and(AdvisorFeedback::query()->sole()->comment)->toContain('se pueden iniciar cuando quieras');

    // Pregunta EQUIVALENTE en el mismo tema: la corrección va como PRIMER bloque del prompt.
    $fake->willReturn(json_encode(['reply' => 'Puedes empezar cuando quieras: '.ACR_LINK.' .', 'action' => 'answer'], JSON_UNESCAPED_SLASHES));
    $chat->set('draft', '¿Cuándo empiezan?')->call('send');
    $prompt = end($fake->calls)['messages'][0]['content'];
    expect($prompt)->toStartWith('RESPUESTA APROBADA POR EL EQUIPO')->toContain('Pregunta de referencia: ¿Cuáles son las próximas fechas de inicio?')
        ->toContain('Síguela fielmente en su contenido');
    $msg = acrLastReply();
    // LinkGuard (búsqueda precisa) acepta el enlace de la respuesta aprobada aunque no esté en el conocimiento.
    expect($msg->content)->toContain(ACR_LINK)
        ->and($msg->meta['correction'])->toMatchArray(['id' => $c->id, 'score' => 1.0])
        ->and($msg->meta['correction_check'])->toMatchArray(['applied' => true, 'line' => 'diplomas_avanzados']);
    $chat->assertSee('Respuesta aprobada')->assertSee('aplicada');
});

it('«Solo comentario» queda como nota; pasar a comentario o a «Correcta» retira la corrección', function () {
    [, , $token] = acrCtx();
    $chat = acrChat($token)->set('draft', 'cuanto cuesta el diploma avanzado')->call('send');
    $reply = acrLastReply();

    $chat->call('rate', $reply->id, 'needs_improvement')->set('noteKind', 'comment')->set('note', 'Faltó el enlace.')->call('saveNote');
    expect(AdvisorCorrection::query()->count())->toBe(0)->and(AdvisorFeedback::query()->sole()->comment)->toBe('Faltó el enlace.');

    $chat->call('rate', $reply->id, 'needs_improvement')->set('note', 'Consulta el precio en admisiones.')->call('saveNote');
    expect(AdvisorCorrection::query()->count())->toBe(1);
    $chat->call('rate', $reply->id, 'needs_improvement')->set('noteKind', 'comment')->set('note', 'Mejor solo nota.')->call('saveNote');
    expect(AdvisorCorrection::query()->count())->toBe(0);

    $chat->call('rate', $reply->id, 'needs_improvement')->set('note', 'Otra vez aprobada.')->call('saveNote');
    $chat->call('rate', $reply->id, 'correct');
    expect(AdvisorCorrection::query()->count())->toBe(0);

    // La respuesta aprobada no puede ir vacía.
    $chat->call('rate', $reply->id, 'needs_improvement')->set('note', '')->call('saveNote')->assertHasErrors(['note']);
});

it('se aplica a una pregunta equivalente del mismo tema y no a otra distinta, a otro tema ni si está desactivada', function () {
    [, $bot] = acrCtx();
    $c = acrCorrection($bot, '¿Cuáles son las próximas fechas de inicio?', 'diplomas_avanzados');
    $svc = app(AdvisorCorrections::class);
    $da = ['Hola, información de los Diplomas Avanzados'];

    expect($svc->match($bot->id, '¿Cuándo empiezan?', $da, 'es'))->toMatchArray(['applied' => true, 'score' => 1.0])
        ->and($svc->match($bot->id, 'cuando puedo empezar', $da, 'es')['applied'])->toBeTrue()
        ->and($svc->match($bot->id, '¿Cuánto cuesta?', $da, 'es')['applied'])->toBeFalse()             // distinta
        ->and($svc->match($bot->id, '¿Cuándo me entregan el diploma?', $da, 'es')['applied'])->toBeFalse()
        ->and($svc->match($bot->id, '¿Cuándo empiezan?', ['Que es el micro MBA'], 'es'))->toBeNull()      // otro tema
        ->and($svc->match($bot->id, '¿Cuándo empiezan los diplomas avanzados?', [], 'es')['applied'])->toBeTrue();

    $c->update(['active' => false]);
    expect($svc->match($bot->id, '¿Cuándo empiezan?', $da, 'es'))->toBeNull();

    // Una corrección GENERAL (sin tema) vale en cualquier tema.
    acrCorrection($bot, '¿En cuántas cuotas puedo pagar?', null);
    expect($svc->match($bot->id, 'en cuantos pagos', ['Que es el micro MBA'], 'es')['applied'])->toBeTrue();
});

it('el saludo inicial aprobado actualiza bots.greeting_es y se usa en las conversaciones nuevas', function () {
    [, $bot, $token] = acrCtx();
    $chat = acrChat($token);
    $greeting = acrLastReply();   // el saludo: no hay mensaje previo del usuario

    $chat->call('rate', $greeting->id, 'needs_improvement')
        ->set('note', 'Hola, soy Sophia, la asesora académica inteligente de MCA School. ¿En qué te puedo ayudar?')->call('saveNote')
        ->assertSee('Saludo inicial actualizado');

    expect($bot->fresh()->greeting_es)->toBe('Hola, soy Sophia, la asesora académica inteligente de MCA School. ¿En qué te puedo ayudar?')
        ->and(AdvisorCorrection::query()->count())->toBe(0);

    $chat->call('restart')->call('start');
    expect(acrLastReply()->content)->toBe('Hola, soy Sophia, la asesora académica inteligente de MCA School. ¿En qué te puedo ayudar?');
});

it('aislamiento: una corrección no sale de su asesor ni de su institución', function () {
    [$instA, $botA] = acrCtx();
    $otherBot = Bot::factory()->withOwnIdentity(['assistant_name' => 'Otro', 'instructions' => 'x'])->create();
    acrCorrection($botA, '¿Cuáles son las próximas fechas de inicio?', null);
    $svc = app(AdvisorCorrections::class);

    expect($svc->match($otherBot->id, '¿Cuándo empiezan?', [], 'es'))->toBeNull();

    [$instB, $botB] = acrCtx(name: 'Asesor B');
    expect(AdvisorCorrection::query()->count())->toBe(0)                                  // scope de institución
        ->and($svc->match($botB->id, '¿Cuándo empiezan?', [], 'es'))->toBeNull()
        ->and($svc->match($botA->id, '¿Cuándo empiezan?', [], 'es'))->toBeNull();          // desde B, la de A no existe

    app(CurrentInstitution::class)->set($instA->id);
    expect($svc->match($botA->id, '¿Cuándo empiezan?', [], 'es')['applied'])->toBeTrue();
});

it('las correcciones también valen para un asesor con búsqueda clásica', function () {
    [, $bot, $token, $fake] = acrCtx(Bot::RETRIEVAL_CLASSIC);
    acrCorrection($bot, '¿En cuántas cuotas puedo pagar?', null, 'Hasta en 3 pagos.');
    acrChat($token)->set('draft', 'en cuantos pagos')->call('send');

    expect(end($fake->calls)['messages'][0]['content'])->toStartWith('RESPUESTA APROBADA POR EL EQUIPO')->toContain('Hasta en 3 pagos.');
});

it('panel «Correcciones aprendidas»: lista, edita, desactiva y elimina; solo administración y solo de ESTE asesor', function () {
    [$inst, $bot] = acrCtx();
    $admin = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin', 'name' => 'Ana Admin']);
    $c = acrCorrection($bot, '¿Cuáles son las próximas fechas de inicio?', 'diplomas_avanzados', 'Cuando quieras.');
    $c->update(['user_id' => $admin->id]);
    $foreign = acrCorrection(Bot::factory()->create(['assistant_name' => 'Ajeno']), 'Ajena', null);

    $form = Livewire::actingAs($admin)->test(Form::class, ['bot' => $bot])
        ->assertSee('Correcciones aprendidas')->assertSee('¿Cuáles son las próximas fechas de inicio?')
        ->assertSee('Diplomas Avanzados')->assertSee('Cuando quieras.')->assertSee('Ana Admin')->assertDontSee('Ajena');

    $form->call('editCorrection', $c->id)->set('correctionAnswer', 'Puedes empezar cuando quieras.')->set('correctionTopic', '')->call('saveCorrection')->assertHasNoErrors();
    expect($c->fresh()->only(['answer', 'topic_line']))->toBe(['answer' => 'Puedes empezar cuando quieras.', 'topic_line' => null]);

    $form->call('toggleCorrection', $c->id);
    expect($c->fresh()->active)->toBeFalse();
    $form->call('deleteCorrection', $c->id);
    expect(AdvisorCorrection::query()->whereKey($c->id)->exists())->toBeFalse();

    // La de otro asesor no se puede tocar desde esta ficha.
    expect(fn () => $form->call('toggleCorrection', $foreign->id))->toThrow(ModelNotFoundException::class);
    expect($foreign->fresh()->active)->toBeTrue();

    // Sin permiso para editar asesores, la ficha no se abre.
    $marketing = User::factory()->create(['institution_id' => $inst->id, 'role' => 'marketing']);
    Livewire::actingAs($marketing)->test(Form::class, ['bot' => $bot])->assertForbidden();
});

it('la ficha guarda el saludo inicial (vacío = el de por defecto)', function () {
    [$inst, $bot] = acrCtx();
    $admin = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);

    Livewire::actingAs($admin)->test(Form::class, ['bot' => $bot])->set('greetingEs', 'Hola, soy Sophia.')->call('save')->assertHasNoErrors();
    expect($bot->fresh()->greeting('es'))->toBe('Hola, soy Sophia.')->and($bot->fresh()->greeting('en'))->toBeNull();
});
