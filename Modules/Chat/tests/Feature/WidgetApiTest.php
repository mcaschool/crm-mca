<?php

declare(strict_types=1);

use Modules\Catalog\Models\Program;
use Modules\Catalog\Models\ProgramCategory;
use Modules\Chat\Models\ConversationNode;
use Modules\Chat\Models\ConversationOption;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Models\Event;
use Modules\Crm\Models\Lead;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;

function widgetBot(): Bot
{
    $institution = Institution::factory()->create();

    return app(CurrentInstitution::class)->runFor($institution->id, function () {
        $bot = Bot::factory()->create(['public_key' => str_repeat('k', 32), 'assistant_name' => 'Celia']);

        $welcome = ConversationNode::query()->create([
            'bot_id' => $bot->id, 'key' => 'welcome', 'type' => 'menu',
            'content_es' => 'Hola, soy Celia.', 'content_en' => 'Hi, I am Celia.', 'status' => 'active',
        ]);
        ConversationOption::query()->create([
            'node_id' => $welcome->id, 'label_es' => 'Ayudame a elegir', 'label_en' => 'Help me choose',
            'action' => 'start_matcher', 'event_type' => null, 'display_order' => 1,
        ]);
        ConversationOption::query()->create([
            'node_id' => $welcome->id, 'label_es' => 'Ver programas', 'label_en' => 'View programs',
            'action' => null, 'event_type' => 'viewed_program', 'display_order' => 2,
        ]);

        return $bot;
    });
}

function widgetHeaders(Bot $bot): array
{
    return ['X-Bot-Key' => $bot->public_key];
}

it('inicia una sesion, devuelve el nodo de bienvenida y registra widget_opened', function () {
    $bot = widgetBot();

    $res = $this->withHeaders(widgetHeaders($bot))->postJson('/api/v1/widget/session', []);

    $res->assertOk()
        ->assertJsonPath('bot.assistant_name', 'Celia')
        ->assertJsonPath('contact_captured', false)
        ->assertJsonPath('node.key', 'welcome');

    app(CurrentInstitution::class)->runFor($bot->institution_id, function () {
        expect(Conversation::query()->count())->toBe(1);
        expect(Event::query()->where('event_type', 'widget_opened')->count())->toBe(1);
    });
});

it('captura un lead con consentimiento y guarda el idioma; deduplica por correo', function () {
    $bot = widgetBot();
    $session = $this->withHeaders(widgetHeaders($bot))->postJson('/api/v1/widget/session', [])->json('session_id');

    $this->withHeaders(widgetHeaders($bot))->postJson('/api/v1/widget/lead', [
        'session_id' => $session, 'name' => 'Ana', 'email' => 'ANA@example.com', 'consent' => true,
    ])->assertOk()->assertJsonPath('contact_captured', true);

    // Segundo lead, mismo correo (otra caja) -> NO duplica.
    $this->withHeaders(widgetHeaders($bot))->postJson('/api/v1/widget/lead', [
        'session_id' => $session, 'name' => 'Ana Maria', 'email' => 'ana@example.com', 'consent' => true,
    ])->assertOk();

    app(CurrentInstitution::class)->runFor($bot->institution_id, function () {
        expect(Contact::query()->count())->toBe(1);
        $contact = Contact::query()->first();
        expect($contact->consent_at)->not->toBeNull();
        expect($contact->email)->toBe('ana@example.com'); // normalizado
        expect(Event::query()->where('event_type', 'contact_created')->count())->toBeGreaterThan(0);
    });
});

it('rechaza la captura si el consentimiento no esta marcado', function () {
    $bot = widgetBot();
    $session = $this->withHeaders(widgetHeaders($bot))->postJson('/api/v1/widget/session', [])->json('session_id');

    $this->withHeaders(widgetHeaders($bot))->postJson('/api/v1/widget/lead', [
        'session_id' => $session, 'name' => 'Ana', 'email' => 'ana@example.com', 'consent' => false,
    ])->assertStatus(422);
});

it('el honeypot rechaza la captura de un bot', function () {
    $bot = widgetBot();
    $session = $this->withHeaders(widgetHeaders($bot))->postJson('/api/v1/widget/session', [])->json('session_id');

    $this->withHeaders(widgetHeaders($bot))->postJson('/api/v1/widget/lead', [
        'session_id' => $session, 'name' => 'Ana', 'email' => 'ana@example.com', 'consent' => true, 'website' => 'http://spam',
    ])->assertStatus(422);
});

it('navegar por una opcion registra su evento CRM', function () {
    $bot = widgetBot();
    $session = $this->withHeaders(widgetHeaders($bot))->postJson('/api/v1/widget/session', [])->json('session_id');

    $optionId = app(CurrentInstitution::class)->runFor($bot->institution_id, fn () => ConversationOption::query()->where('event_type', 'viewed_program')->value('id'));

    $this->withHeaders(widgetHeaders($bot))->postJson('/api/v1/widget/navigate', [
        'session_id' => $session, 'option_id' => $optionId,
    ])->assertOk();

    app(CurrentInstitution::class)->runFor($bot->institution_id, function () {
        expect(Event::query()->where('event_type', 'viewed_program')->count())->toBe(1);
    });
});

it('el emparejador devuelve programas y registra lead+evento', function () {
    $bot = widgetBot();
    $session = $this->withHeaders(widgetHeaders($bot))->postJson('/api/v1/widget/session', [])->json('session_id');

    // Captura previa (el matcher necesita un contacto para el lead).
    $this->withHeaders(widgetHeaders($bot))->postJson('/api/v1/widget/lead', [
        'session_id' => $session, 'name' => 'Ana', 'email' => 'ana@example.com', 'consent' => true,
    ]);

    $categoryId = app(CurrentInstitution::class)->runFor($bot->institution_id, function () {
        $cat = ProgramCategory::factory()->create();
        Program::factory()->create(['category_id' => $cat->id, 'level' => 'intermedio', 'goal' => 'ascenso', 'status' => 'active']);

        return $cat->id;
    });

    $res = $this->withHeaders(widgetHeaders($bot))->postJson('/api/v1/widget/match', [
        'session_id' => $session,
        'answers' => ['area' => $categoryId, 'meta' => 'ascenso', 'seniority' => 'desarrollo', 'educacion' => 'universitario_completo', 'motivacion' => 'ascender'],
    ]);

    $res->assertOk()->assertJsonPath('tier', 1);
    expect($res->json('programs'))->toHaveCount(1);

    app(CurrentInstitution::class)->runFor($bot->institution_id, function () {
        expect(Lead::query()->where('source', 'widget_matcher')->count())->toBe(1);
        expect(Event::query()->where('event_type', 'used_matcher')->count())->toBe(1);
    });
});

it('deduce la institucion por la public_key; sin llave valida rechaza', function () {
    widgetBot();

    // Sin X-Bot-Key -> 400 (falta la llave).
    $this->postJson('/api/v1/widget/session', [])->assertStatus(400);

    // Llave inexistente -> 404.
    $this->withHeaders(['X-Bot-Key' => 'no-existe-xxxxxxxxxxxxxxxxxxxxxxx'])
        ->postJson('/api/v1/widget/session', [])->assertStatus(404);
});

it('ignora cualquier institution_id enviado por el cliente', function () {
    $bot = widgetBot();
    $otra = Institution::factory()->create();

    $res = $this->withHeaders(widgetHeaders($bot))->postJson('/api/v1/widget/session', [
        'institution_id' => $otra->id, // intento de inyeccion
    ]);

    $res->assertOk();
    // La conversacion se creo en la institucion del bot, no en la inyectada.
    app(CurrentInstitution::class)->runGlobally(function () use ($bot, $otra) {
        expect(Conversation::query()->withoutGlobalScopes()->where('institution_id', $bot->institution_id)->count())->toBe(1);
        expect(Conversation::query()->withoutGlobalScopes()->where('institution_id', $otra->id)->count())->toBe(0);
    });
});
