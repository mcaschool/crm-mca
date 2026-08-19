<?php

declare(strict_types=1);

use Modules\Catalog\Models\Program;
use Modules\Catalog\Models\ProgramCategory;
use Modules\Chat\Database\Seeders\ChatTreeSeeder;
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

    $bot = app(CurrentInstitution::class)->runFor($institution->id, fn () => Bot::factory()->create(['public_key' => str_repeat('k', 32), 'assistant_name' => 'Celia']));

    // Siembra el arbol real bilingue (NODE_WELCOME/NODE_MAIN/...).
    (new ChatTreeSeeder)->run();

    return $bot;
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
        ->assertJsonPath('node.key', 'NODE_WELCOME');

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

    // Opcion "Certificacion y titulacion" del menu principal (event viewed_certification).
    $optionId = app(CurrentInstitution::class)->runFor($bot->institution_id, fn () => ConversationOption::query()->where('event_type', 'viewed_certification')->value('id'));

    $this->withHeaders(widgetHeaders($bot))->postJson('/api/v1/widget/navigate', [
        'session_id' => $session, 'option_id' => $optionId,
    ])->assertOk();

    app(CurrentInstitution::class)->runFor($bot->institution_id, function () {
        expect(Event::query()->where('event_type', 'viewed_certification')->count())->toBe(1);
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

it('al volver TRAS la ventana de reanudacion crea una conversacion NUEVA y marca el re-contacto', function () {
    config(['crm.widget.session_resume_minutes' => 30]);
    $bot = widgetBot();
    $headers = widgetHeaders($bot);

    $session = $this->withHeaders($headers)->postJson('/api/v1/widget/session', [])->json('session_id');
    $this->withHeaders($headers)->postJson('/api/v1/widget/lead', [
        'session_id' => $session, 'name' => 'Alvaro Rivera', 'email' => 'alvaro@example.com', 'consent' => true,
    ])->assertOk();

    // La conversacion previa queda inactiva mas que la ventana.
    app(CurrentInstitution::class)->runFor($bot->institution_id, function () {
        Conversation::query()->update(['last_activity_at' => now()->subHours(2)]);
    });

    // Vuelve con el MISMO session_id (localStorage conservado).
    $res = $this->withHeaders($headers)->postJson('/api/v1/widget/session', ['session_id' => $session]);
    $res->assertOk()
        ->assertJsonPath('contact_captured', true)
        ->assertJsonPath('node.key', 'NODE_MAIN'); // arranca en el menu, no en la captura
    expect($res->json('session_id'))->not->toBe($session); // es una conversacion nueva

    app(CurrentInstitution::class)->runFor($bot->institution_id, function () {
        $contact = Contact::query()->first();
        expect(Conversation::query()->where('contact_id', $contact->id)->count())->toBe(2);
        expect($contact->last_recontacted_at)->not->toBeNull();
        expect($contact->recontacted_seen_at)->toBeNull();
        expect($contact->hasUnseenRecontact())->toBeTrue();
        expect(Event::query()->where('event_type', 'recontacted')->count())->toBe(1);
    });
});

it('al volver DENTRO de la ventana reanuda la MISMA conversacion, sin re-contacto', function () {
    config(['crm.widget.session_resume_minutes' => 30]);
    $bot = widgetBot();
    $headers = widgetHeaders($bot);

    $session = $this->withHeaders($headers)->postJson('/api/v1/widget/session', [])->json('session_id');
    $this->withHeaders($headers)->postJson('/api/v1/widget/lead', [
        'session_id' => $session, 'name' => 'Ana', 'email' => 'ana@example.com', 'consent' => true,
    ])->assertOk();

    // Regreso inmediato (recargar/minimizar): dentro de la ventana.
    $res = $this->withHeaders($headers)->postJson('/api/v1/widget/session', ['session_id' => $session]);
    expect($res->json('session_id'))->toBe($session);

    app(CurrentInstitution::class)->runFor($bot->institution_id, function () {
        $contact = Contact::query()->first();
        expect(Conversation::query()->where('contact_id', $contact->id)->count())->toBe(1);
        expect($contact->last_recontacted_at)->toBeNull();
        expect(Event::query()->where('event_type', 'recontacted')->count())->toBe(0);
    });
});

it('un clic guiado de interes corporativo convierte el contacto en LEAD', function () {
    $bot = widgetBot();
    $headers = widgetHeaders($bot);

    $session = $this->withHeaders($headers)->postJson('/api/v1/widget/session', [])->json('session_id');
    $this->withHeaders($headers)->postJson('/api/v1/widget/lead', [
        'session_id' => $session, 'name' => 'Empresa Guiada', 'email' => 'empresa@example.com', 'consent' => true,
    ])->assertOk();

    $optionId = app(CurrentInstitution::class)->runFor(
        $bot->institution_id,
        fn () => ConversationOption::query()->where('event_type', 'corporate_interest')->value('id'),
    );

    $this->withHeaders($headers)->postJson('/api/v1/widget/navigate', [
        'session_id' => $session, 'option_id' => $optionId,
    ])->assertOk();

    app(CurrentInstitution::class)->runFor($bot->institution_id, function () {
        $contact = Contact::query()->first();
        $lead = Lead::query()->where('contact_id', $contact->id)->first();
        expect($lead)->not->toBeNull();
        expect($lead->source)->toBe('corporate');
    });
});
