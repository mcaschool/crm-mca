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
        'evento' => 'ruta_generada',
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
        // Evento ruta_generada + email nuevo → stage diagnostico, con su marca de tiempo.
        expect($inc->stage)->toBe('diagnostico');
        expect($inc->diagnostico_at)->not->toBeNull();
        expect($inc->solicita_contacto_at)->toBeNull();
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
        ->assertSee('Diagnóstico')                   // estado del embudo (aún no pidió contacto)
        ->assertSee('ACME Corp')
        ->assertSee('Liderazgo Corporativo')         // NOMBRE del programa enlazado (del catálogo)
        ->assertSee('CORP-999-NOEXISTE')             // el no enlazado se muestra tal cual
        ->assertSee('Sin enlazar al catálogo')       // y se marca como tal (degradación)
        ->assertSee('Recomendador InCompany')        // #1 origen correcto (no "Captado por Celia")
        ->assertSee('No tuvo conversación de chat')  // #4 nota compacta (sin panel de chat vacío)
        ->assertDontSee('Este lead aún no tiene mensajes registrados') // #4 ya no aparece el vacío grande
        ->assertDontSee('Precio')                    // NUNCA se muestra precio
        ->assertDontSee('precio')
        ->html();

    // El nombre del programa aparece; ningún importe (no hay columna de precio en el catálogo).
    expect($html)->not->toContain('US$');
    expect($html)->not->toContain('RD$');
});

it('enlaza el programa cuando n8n envía el CODE del catálogo (MC-###), no solo el course_idnumber', function () {
    [$institution, $bot, $program] = incompanyCtx(); // code MC-050, course_idnumber CORP-101

    // n8n manda el CODE del catálogo (como en producción), no el idnumber de Moodle.
    $leadId = postIncompany(INCOMPANY_TOKEN, validIncompanyPayload([
        'programa_1' => 'MC-050',   // el CODE
        'programa_2' => null,
        'programa_3' => null,
    ]))->assertStatus(201)->json('id');

    app(CurrentInstitution::class)->runFor($institution->id, function () use ($leadId, $program) {
        $inc = IncompanyLead::query()->where('lead_id', $leadId)->firstOrFail();
        // Enlaza por code → program_id resuelto y NOMBRE disponible.
        expect($inc->programa_1_program_id)->toBe($program->id);
        expect($inc->programa_1_code)->toBe('MC-050');
        $route = $inc->programRoute();
        expect($route[0]['linked'])->toBeTrue();
        expect($route[0]['program']->name)->toBe('Liderazgo Corporativo');
    });
});

// ---------------------------------------------------------------------------
// (d) UPSERT por email — un email = un lead, con estado de embudo por evento
// ---------------------------------------------------------------------------

/** Cuenta leads e InCompany dentro de la institución. */
function incompanyCounts(int $institutionId): array
{
    return app(CurrentInstitution::class)->runFor($institutionId, fn () => [
        'leads' => Lead::query()->count(),
        'incompany' => IncompanyLead::query()->count(),
    ]);
}

it('(a) ruta_generada con email nuevo → CREA en estado diagnostico (201 created)', function () {
    [$institution] = incompanyCtx();

    $res = postIncompany(INCOMPANY_TOKEN, validIncompanyPayload(['evento' => 'ruta_generada', 'email' => 'nuevo@empresa.com']));
    $res->assertStatus(201)->assertJson(['status' => 'created']);

    app(CurrentInstitution::class)->runFor($institution->id, function () {
        expect(IncompanyLead::query()->count())->toBe(1);
        $inc = IncompanyLead::query()->firstOrFail();
        expect($inc->stage)->toBe('diagnostico');
        expect($inc->solicita_contacto_at)->toBeNull();
    });
});

it('(b) solicita_contacto con el MISMO email → ACTUALIZA el mismo lead a solicita_contacto, NO crea otro', function () {
    [$institution] = incompanyCtx();

    // 1º ruta_generada (crea)
    postIncompany(INCOMPANY_TOKEN, validIncompanyPayload(['evento' => 'ruta_generada', 'email' => 'ana@empresa.com']))
        ->assertStatus(201)->assertJson(['status' => 'created']);
    $after1 = incompanyCounts($institution->id);

    // 2º solicita_contacto (mismo email → actualiza)
    $res = postIncompany(INCOMPANY_TOKEN, validIncompanyPayload(['evento' => 'solicita_contacto', 'email' => 'ana@empresa.com']));
    $res->assertStatus(200)->assertJson(['status' => 'updated']);
    $after2 = incompanyCounts($institution->id);

    // Mismo id, sin duplicar.
    expect($after2['incompany'])->toBe($after1['incompany']); // 1
    expect($after2['leads'])->toBe($after1['leads']);         // 1

    app(CurrentInstitution::class)->runFor($institution->id, function () {
        $inc = IncompanyLead::query()->firstOrFail();
        expect($inc->stage)->toBe('solicita_contacto');
        expect($inc->diagnostico_at)->not->toBeNull();        // vino de ruta_generada
        expect($inc->solicita_contacto_at)->not->toBeNull();  // y ahora pidió contacto
    });
});

it('(c) solicita_contacto con email que NUNCA envió ruta → CREA directo en solicita_contacto', function () {
    [$institution] = incompanyCtx();

    $res = postIncompany(INCOMPANY_TOKEN, validIncompanyPayload(['evento' => 'solicita_contacto', 'email' => 'directo@empresa.com']));
    $res->assertStatus(201)->assertJson(['status' => 'created']);

    app(CurrentInstitution::class)->runFor($institution->id, function () {
        expect(IncompanyLead::query()->count())->toBe(1);
        $inc = IncompanyLead::query()->firstOrFail();
        expect($inc->stage)->toBe('solicita_contacto');
        expect($inc->solicita_contacto_at)->not->toBeNull();
        expect($inc->diagnostico_at)->toBeNull();             // nunca hubo diagnóstico
    });
});

it('(d) dos ruta_generada del MISMO email → UN solo lead, no dos', function () {
    [$institution] = incompanyCtx();

    postIncompany(INCOMPANY_TOKEN, validIncompanyPayload(['evento' => 'ruta_generada', 'email' => 'dup@empresa.com']))
        ->assertStatus(201)->assertJson(['status' => 'created']);
    postIncompany(INCOMPANY_TOKEN, validIncompanyPayload(['evento' => 'ruta_generada', 'email' => 'dup@empresa.com']))
        ->assertStatus(200)->assertJson(['status' => 'updated']);

    $counts = incompanyCounts($institution->id);
    expect($counts['incompany'])->toBe(1);
    expect($counts['leads'])->toBe(1);
});

it('no degrada: ruta_generada que llega DESPUÉS de solicita_contacto mantiene solicita_contacto', function () {
    [$institution] = incompanyCtx();

    postIncompany(INCOMPANY_TOKEN, validIncompanyPayload(['evento' => 'solicita_contacto', 'email' => 'hot@empresa.com']))
        ->assertStatus(201);
    postIncompany(INCOMPANY_TOKEN, validIncompanyPayload(['evento' => 'ruta_generada', 'email' => 'hot@empresa.com']))
        ->assertStatus(200);

    app(CurrentInstitution::class)->runFor($institution->id, function () {
        $inc = IncompanyLead::query()->firstOrFail();
        expect($inc->stage)->toBe('solicita_contacto'); // NO volvió a diagnostico
    });
});

it('la ficha marca «Solicitó contacto» cuando el lead ya pidió contacto (badge caliente)', function () {
    [$institution, $bot, $program] = incompanyCtx();

    // ruta_generada y luego solicita_contacto sobre el mismo email.
    $leadId = postIncompany(INCOMPANY_TOKEN, validIncompanyPayload(['evento' => 'ruta_generada', 'email' => 'hot2@empresa.com']))->json('id');
    postIncompany(INCOMPANY_TOKEN, validIncompanyPayload(['evento' => 'solicita_contacto', 'email' => 'hot2@empresa.com']));

    $admin = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin', 'status' => 'active']);
    app(CurrentInstitution::class)->set($institution->id);
    test()->actingAs($admin);

    Livewire::test(Show::class, ['lead' => Lead::query()->findOrFail($leadId)])
        ->assertSee('Solicitó contacto')
        ->assertSee('Pidió contacto:');   // marca de tiempo del salto
});

it('evento inválido o ausente → 422 (no crea nada)', function () {
    [$institution] = incompanyCtx();

    postIncompany(INCOMPANY_TOKEN, validIncompanyPayload(['evento' => 'otro_evento']))
        ->assertStatus(422)->assertJsonPath('errors.evento.0', 'El valor de «evento» no es válido (usa: ruta_generada o solicita_contacto).');

    $bad = validIncompanyPayload();
    unset($bad['evento']);
    postIncompany(INCOMPANY_TOKEN, $bad)->assertStatus(422)->assertJsonStructure(['errors' => ['evento']]);

    app(CurrentInstitution::class)->runFor($institution->id, fn () => expect(IncompanyLead::query()->count())->toBe(0));
});
