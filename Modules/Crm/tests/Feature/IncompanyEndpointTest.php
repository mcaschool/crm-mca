<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Modules\Audit\Models\AuditLog;
use Modules\Catalog\Models\Program;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Livewire\Leads\Show;
use Modules\Crm\Models\Event;
use Modules\Crm\Models\IncompanyLead;
use Modules\Crm\Models\Lead;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;
use Modules\Integrations\Models\Integration;

/**
 * Endpoint público InCompany (n8n → CRM). Puerta pública: la seguridad se prueba con
 * EVIDENCIA (401 sin token, 422 sin crear a medias, 429 por exceso, token acotado) y
 * el flujo real crea el lead corporativo con su perfil enlazado al catálogo + auditoría.
 */
const INCOMPANY_TOKEN = 'TOK_incompany_0123456789abcdef';

function incompanyCtx(): array
{
    $institution = Institution::factory()->create();

    return app(CurrentInstitution::class)->runFor($institution->id, function () use ($institution): array {
        $bot = Bot::factory()->create(['institution_id' => $institution->id, 'status' => 'active']);
        $program = Program::factory()->create([
            'institution_id' => $institution->id,
            'code' => 'MC-050',
            'course_idnumber' => 'CORP-101',
            'name_es' => 'Liderazgo Corporativo',
            'url' => 'https://mcaschool.education/p/liderazgo',
            'status' => 'active',
        ]);
        $integration = Integration::factory()->create(['institution_id' => $institution->id, 'type' => 'n8n', 'status' => 'active']);
        $integration->replaceSecrets([
            'webhook_url' => 'https://n8n.example/webhook',
            'signing_secret' => 'hmac-secret',
            'incompany_inbound_token' => INCOMPANY_TOKEN,
        ]);
        $integration->save();

        return [$institution, $bot, $program, $integration];
    });
}

function validIncompanyPayload(array $overrides = []): array
{
    return array_merge([
        'nombre_empresa' => 'ACME Corp',
        'nombre_contacto' => 'Juan Pérez',
        'email' => 'juan@acme.com',
        'whatsapp' => '+1 809 555 1234',
        'sector' => 'Tecnología',
        'tamano_empresa' => '51-200',
        'modalidad' => 'grupo',
        'cantidad_personas' => 15,
        'programa_1' => 'CORP-101',        // matchea el catálogo
        'programa_2' => 'CORP-999-NOEXISTE', // no matchea → se guarda como referencia
        'programa_3' => null,
        'area_desarrollo' => 'Liderazgo y gestión de equipos técnicos',
    ], $overrides);
}

function postIncompany(?string $token, array $payload)
{
    $headers = $token !== null ? ['Authorization' => 'Bearer '.$token] : [];

    return test()->withHeaders($headers)->postJson('/api/v1/incompany/lead', $payload);
}

// ---------------------------------------------------------------------------
// (a) SEGURIDAD — evidencia
// ---------------------------------------------------------------------------

it('SIN token → 401 y no crea ningún lead', function () {
    [$institution] = incompanyCtx();

    postIncompany(null, validIncompanyPayload())->assertStatus(401);

    app(CurrentInstitution::class)->runFor($institution->id, function () {
        expect(Lead::query()->count())->toBe(0);
        expect(IncompanyLead::query()->count())->toBe(0);
    });
});

it('token INVÁLIDO → 401', function () {
    incompanyCtx();
    postIncompany('token-que-no-existe', validIncompanyPayload())->assertStatus(401);
});

it('JSON incompleto/malformado (con token válido) → 422 y NO crea nada a medias', function () {
    [$institution] = incompanyCtx();

    // Falta email, modalidad inválida, cantidad no numérica.
    $bad = validIncompanyPayload(['email' => '', 'modalidad' => 'otra', 'cantidad_personas' => 'muchas']);
    postIncompany(INCOMPANY_TOKEN, $bad)
        ->assertStatus(422)
        ->assertJsonStructure(['message', 'errors' => ['email', 'modalidad', 'cantidad_personas']]);

    app(CurrentInstitution::class)->runFor($institution->id, function () {
        expect(Lead::query()->count())->toBe(0);
        expect(IncompanyLead::query()->count())->toBe(0);
        expect(\Modules\Crm\Models\Contact::query()->count())->toBe(0);
    });
});

it('exceso de peticiones → 429 (rate limit por IP)', function () {
    incompanyCtx();
    config(['crm.incompany.rate_per_min' => 3]);

    // 3 permitidas (401 por token de más, pero pasan el throttle), la 4ª → 429.
    for ($i = 0; $i < 3; $i++) {
        postIncompany('cualquier-token', validIncompanyPayload())->assertStatus(401);
    }
    postIncompany('cualquier-token', validIncompanyPayload())->assertStatus(429);
});

it('el token NO da acceso a nada más del CRM (panel)', function () {
    incompanyCtx();

    // El panel usa sesión (web), no este token: queda sin autenticar → redirección a login.
    test()->withHeaders(['Authorization' => 'Bearer '.INCOMPANY_TOKEN])
        ->get('/users')
        ->assertRedirect();
});

// ---------------------------------------------------------------------------
// (b) FLUJO REAL — crea el lead InCompany con perfil enlazado + auditoría
// ---------------------------------------------------------------------------

it('lead válido → 201 con id, lead corporativo, perfil InCompany, programa enlazado y auditoría', function () {
    [$institution, $bot, $program] = incompanyCtx();

    $res = postIncompany(INCOMPANY_TOKEN, validIncompanyPayload());
    $res->assertStatus(201)->assertJsonStructure(['id', 'status']);
    $leadId = $res->json('id');

    app(CurrentInstitution::class)->runFor($institution->id, function () use ($leadId, $program) {
        $lead = Lead::query()->findOrFail($leadId);
        // Corporativo, origen InCompany, producto incompany.
        expect($lead->area)->toBe('Corporativo');
        expect($lead->source)->toBe('incompany_web');
        expect($lead->product_type)->toBe('incompany');
        expect($lead->isCorporate())->toBeTrue();
        // Programa principal del lead = primer programa de la ruta (enlazado).
        expect($lead->program_id)->toBe($program->id);

        // Perfil InCompany persistido.
        $inc = IncompanyLead::query()->where('lead_id', $leadId)->firstOrFail();
        expect($inc->nombre_empresa)->toBe('ACME Corp');
        expect($inc->modalidad)->toBe('grupo');
        expect($inc->cantidad_personas)->toBe(15);
        // Programa 1 enlazado al catálogo; programa 2 guardado como código sin enlazar.
        expect($inc->programa_1_program_id)->toBe($program->id);
        expect($inc->programa_1_code)->toBe('CORP-101');
        expect($inc->programa_2_code)->toBe('CORP-999-NOEXISTE');
        expect($inc->programa_2_program_id)->toBeNull();

        // Ruta: programa 1 enlazado (con nombre del catálogo), programa 2 sin enlazar.
        $route = $inc->programRoute();
        expect($route)->toHaveCount(2);
        expect($route[0]['linked'])->toBeTrue();
        expect($route[0]['program']->name)->toBe('Liderazgo Corporativo');
        expect($route[1]['linked'])->toBeFalse();

        // Enciende el KPI corporativo (evento corporate_interest).
        expect(Event::query()->where('event_type', 'corporate_interest')->where('contact_id', $lead->contact_id)->exists())->toBeTrue();

        // Auditoría del lead recibido (con IP la captura el servicio).
        expect(AuditLog::query()->where('action', 'incompany_lead.received')->where('auditable_id', $leadId)->exists())->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// (c) FICHA — el mini-informe se renderiza: nombre de programa enlazado (SIN precio),
//     degradación del no enlazado, y etiqueta corporativa.
// ---------------------------------------------------------------------------

it('la ficha muestra el Perfil InCompany con nombre de programa enlazado, sin precio y marca el no enlazado', function () {
    [$institution, $bot, $program] = incompanyCtx();

    $leadId = postIncompany(INCOMPANY_TOKEN, validIncompanyPayload())->assertStatus(201)->json('id');

    // Un admin de la institución abre la ficha.
    $admin = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin', 'status' => 'active']);
    app(CurrentInstitution::class)->set($institution->id);
    test()->actingAs($admin);

    $lead = Lead::query()->findOrFail($leadId);

    $html = Livewire::test(Show::class, ['lead' => $lead])
        ->assertSee('Perfil InCompany')
        ->assertSee('Empresa')                       // etiqueta corporativa en la tarjeta
        ->assertSee('ACME Corp')
        ->assertSee('Liderazgo Corporativo')         // NOMBRE del programa enlazado (del catálogo)
        ->assertSee('CORP-999-NOEXISTE')             // el no enlazado se muestra tal cual
        ->assertSee('Sin enlazar al catálogo')       // y se marca como tal (degradación)
        ->assertDontSee('Precio')                    // NUNCA se muestra precio
        ->assertDontSee('precio')
        ->html();

    // El nombre del programa aparece; ningún importe (no hay columna de precio en el catálogo).
    expect($html)->not->toContain('US$');
    expect($html)->not->toContain('RD$');
});
