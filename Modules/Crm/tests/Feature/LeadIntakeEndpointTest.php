<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Audit\Models\AuditLog;
use Modules\Catalog\Models\Program;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Event;
use Modules\Crm\Models\Lead;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;
use Modules\Integrations\Models\Integration;

/**
 * Endpoint público GENERAL de captación de leads (n8n → CRM, POST /api/v1/leads/intake).
 * Contrato corregido: product_type de taxonomía controlada (nunca microcredencial silencioso),
 * país ISO-2 o nombre, consentimiento explícito, idempotencia obligatoria (Idempotency-Key o
 * request_id) y resolución de programa por code/idnumber/nombre/alias sin asociación errónea.
 */
const LEAD_INTAKE_TOKEN = 'TOK_lead_intake_0123456789abcdef';
const LEAD_INTAKE_INCOMPANY_TOKEN = 'TOK_incompany_only_0123456789abc';

/**
 * Institución + bot activo + programa (code MC-050, idnumber CORP-101, nombre "Liderazgo
 * Corporativo") + integración n8n con el token de intake y (distinto) el de InCompany.
 *
 * @return array{0: Institution, 1: Bot, 2: Program, 3: Integration}
 */
function leadIntakeCtx(string $leadToken = LEAD_INTAKE_TOKEN, string $status = 'active'): array
{
    $institution = Institution::factory()->create();

    return app(CurrentInstitution::class)->runFor($institution->id, function () use ($institution, $leadToken, $status): array {
        $bot = Bot::factory()->create(['institution_id' => $institution->id, 'status' => 'active']);
        $program = Program::factory()->create([
            'institution_id' => $institution->id,
            'code' => 'MC-050',
            'course_idnumber' => 'CORP-101',
            'name_es' => 'Liderazgo Corporativo',
            'url' => 'https://mcaschool.education/p/liderazgo',
            'status' => 'active',
        ]);
        $integration = Integration::factory()->create(['institution_id' => $institution->id, 'type' => 'n8n', 'status' => $status]);
        $integration->replaceSecrets([
            'webhook_url' => 'https://n8n.example/webhook',
            'signing_secret' => 'hmac-secret',
            'lead_intake_token' => $leadToken,
            'incompany_inbound_token' => LEAD_INTAKE_INCOMPANY_TOKEN,
        ]);
        $integration->save();

        return [$institution, $bot, $program, $integration];
    });
}

/**
 * @param  array<string,mixed>  $overrides
 * @return array<string,mixed>
 */
function intakePayload(array $overrides = []): array
{
    return array_merge([
        'request_id' => 'REQ-'.bin2hex(random_bytes(6)),
        'email' => 'lead@empresa.com',
        'first_name' => 'Juan',
        'last_name' => 'Pérez',
        'phone' => '+1 809 555 1234',
        'country' => 'DO',
        'product_type' => 'maestria',
        'program' => 'MC-050',
        'source' => 'website',
        'channel' => 'web',
        'form' => 'maestrias_solicitud_informacion',
    ], $overrides);
}

/**
 * @param  array<string,mixed>  $payload
 * @param  array<string,string>  $headers
 */
function postIntake(?string $token, array $payload, array $headers = [])
{
    if ($token !== null) {
        $headers['Authorization'] = 'Bearer '.$token;
    }

    return test()->withHeaders($headers)->postJson('/api/v1/leads/intake', $payload);
}

/** @return array{leads: int, contacts: int} */
function intakeCounts(int $institutionId): array
{
    return app(CurrentInstitution::class)->runFor($institutionId, fn () => [
        'leads' => Lead::query()->count(),
        'contacts' => Contact::query()->count(),
    ]);
}

// ===========================================================================
// SEGURIDAD (token acotado, aislamiento, secreto)
// ===========================================================================

it('token ausente → 401 y no crea nada', function () {
    [$institution] = leadIntakeCtx();
    postIntake(null, intakePayload())->assertStatus(401);
    expect(intakeCounts($institution->id)['leads'])->toBe(0);
});

it('token inválido → 401', function () {
    [$institution] = leadIntakeCtx();
    postIntake('token-que-no-existe', intakePayload())->assertStatus(401);
    expect(intakeCounts($institution->id)['leads'])->toBe(0);
});

it('integración n8n INACTIVA → 401 (aunque el token coincida)', function () {
    [$institution] = leadIntakeCtx(LEAD_INTAKE_TOKEN, 'inactive');
    postIntake(LEAD_INTAKE_TOKEN, intakePayload())->assertStatus(401);
    expect(intakeCounts($institution->id)['leads'])->toBe(0);
});

it('aislamiento: el token de A crea SOLO en A, nunca en B', function () {
    [$a] = leadIntakeCtx('TOK_lead_A_0123456789abcdefghij');
    [$b] = leadIntakeCtx('TOK_lead_B_0123456789abcdefghij');

    postIntake('TOK_lead_A_0123456789abcdefghij', intakePayload(['email' => 'iso@empresa.com']))->assertStatus(201);

    expect(intakeCounts($a->id)['leads'])->toBe(1);
    expect(intakeCounts($b->id)['leads'])->toBe(0);
});

it('el token InCompany NO autentica el endpoint de intake → 401', function () {
    [$institution] = leadIntakeCtx();
    postIntake(LEAD_INTAKE_INCOMPANY_TOKEN, intakePayload())->assertStatus(401);
    expect(intakeCounts($institution->id)['leads'])->toBe(0);
});

it('el token de intake NO autentica el endpoint InCompany → 401', function () {
    leadIntakeCtx();
    test()->withHeaders(['Authorization' => 'Bearer '.LEAD_INTAKE_TOKEN])
        ->postJson('/api/v1/incompany/lead', ['evento' => 'ruta_generada'])
        ->assertStatus(401);
});

it('el token de intake se guarda CIFRADO y se muestra ENMASCARADO', function () {
    [, , , $integration] = leadIntakeCtx();

    $raw = (string) DB::table('integrations')->where('id', $integration->id)->value('config');
    expect($raw)->not->toBe('')->and($raw)->not->toContain(LEAD_INTAKE_TOKEN);

    $masked = $integration->maskedConfig();
    expect($masked['lead_intake_token'])->not->toBe(LEAD_INTAKE_TOKEN)->toContain('••••');
    expect($integration->secret('lead_intake_token'))->toBe(LEAD_INTAKE_TOKEN);
});

it('rotación: reemplaza el token; vacío conserva el anterior', function () {
    [, , , $integration] = leadIntakeCtx();

    $integration->replaceSecrets(['lead_intake_token' => 'TOK_lead_intake_ROTADO_99998888']);
    $integration->save();
    expect($integration->fresh()->secret('lead_intake_token'))->toBe('TOK_lead_intake_ROTADO_99998888');

    $integration->replaceSecrets(['lead_intake_token' => '']);
    $integration->save();
    expect($integration->fresh()->secret('lead_intake_token'))->toBe('TOK_lead_intake_ROTADO_99998888');
});

it('el token nunca aparece en la respuesta ni en la auditoría', function () {
    [$institution] = leadIntakeCtx();

    $ok = postIntake(LEAD_INTAKE_TOKEN, intakePayload(['email' => 'safe@empresa.com']))->assertStatus(201);
    expect($ok->getContent())->not->toContain(LEAD_INTAKE_TOKEN);

    $unauth = postIntake('otro-token-secreto-xyz', intakePayload())->assertStatus(401);
    expect($unauth->getContent())->not->toContain('otro-token-secreto-xyz');

    app(CurrentInstitution::class)->runFor($institution->id, function () {
        AuditLog::query()->get()->each(function (AuditLog $log) {
            expect(json_encode($log->getAttributes()))->not->toContain(LEAD_INTAKE_TOKEN);
        });
    });
});

// ===========================================================================
// product_type — taxonomía controlada, nunca microcredencial silencioso
// ===========================================================================

it('1) nunca clasifica como microcredencial de forma silenciosa: sin product_type ni form → 422', function () {
    [$institution] = leadIntakeCtx();

    $payload = intakePayload();
    unset($payload['product_type'], $payload['form']);

    postIntake(LEAD_INTAKE_TOKEN, $payload)
        ->assertStatus(422)
        ->assertJsonStructure(['ok', 'message', 'errors' => ['product_type']]);

    expect(intakeCounts($institution->id)['leads'])->toBe(0);
});

it('1b) sin product_type pero con form reconocido → se DERIVA (no microcredencial)', function () {
    [$institution] = leadIntakeCtx();

    $payload = intakePayload(['email' => 'deriv@empresa.com', 'form' => 'programas_ejecutivos_inscripcion']);
    unset($payload['product_type']);

    $id = postIntake(LEAD_INTAKE_TOKEN, $payload)->assertStatus(201)->json('lead_id');

    app(CurrentInstitution::class)->runFor($institution->id, function () use ($id) {
        expect(Lead::query()->findOrFail($id)->product_type)->toBe('programa_ejecutivo');
    });
});

it('2) product_type fuera de la taxonomía → 422', function () {
    [$institution] = leadIntakeCtx();

    postIntake(LEAD_INTAKE_TOKEN, intakePayload(['product_type' => 'microcredential']))
        ->assertStatus(422)->assertJsonStructure(['errors' => ['product_type']]);

    expect(intakeCounts($institution->id)['leads'])->toBe(0);
});

it('2b) product_type válido de la taxonomía → se guarda tal cual', function () {
    [$institution] = leadIntakeCtx();

    $id = postIntake(LEAD_INTAKE_TOKEN, intakePayload(['email' => 'tax@empresa.com', 'product_type' => 'estancia_internacional']))
        ->assertStatus(201)->json('lead_id');

    app(CurrentInstitution::class)->runFor($institution->id, function () use ($id) {
        expect(Lead::query()->findOrFail($id)->product_type)->toBe('estancia_internacional');
    });
});

// ===========================================================================
// País — ISO-2 o nombre, normalización, sin pérdida, sin nacionalidad
// ===========================================================================

it('3) país recibido como ISO-2 se guarda en contacts.country', function () {
    [$institution] = leadIntakeCtx();
    $id = postIntake(LEAD_INTAKE_TOKEN, intakePayload(['email' => 'iso2@empresa.com', 'country' => 'gt']))
        ->assertStatus(201)->json('lead_id');

    app(CurrentInstitution::class)->runFor($institution->id, function () use ($id) {
        expect(Lead::query()->findOrFail($id)->contact->country)->toBe('GT');
    });
});

it('4) país recibido como nombre completo se normaliza a ISO-2', function () {
    [$institution] = leadIntakeCtx();
    $id = postIntake(LEAD_INTAKE_TOKEN, intakePayload(['email' => 'name@empresa.com', 'country' => 'República Dominicana']))
        ->assertStatus(201)->json('lead_id');

    app(CurrentInstitution::class)->runFor($institution->id, function () use ($id) {
        expect(Lead::query()->findOrFail($id)->contact->country)->toBe('DO');
    });
});

it('5) país NO reconocido no pierde el lead (country queda null, crudo en el evento)', function () {
    [$institution] = leadIntakeCtx();
    $res = postIntake(LEAD_INTAKE_TOKEN, intakePayload(['email' => 'unk@empresa.com', 'country' => 'Wakanda']))
        ->assertStatus(201);
    $id = $res->json('lead_id');

    app(CurrentInstitution::class)->runFor($institution->id, function () use ($id) {
        $lead = Lead::query()->findOrFail($id);
        expect($lead->contact->country)->toBeNull(); // no se rechazó el lead
        $event = Event::query()->where('event_type', 'lead_intake')->where('contact_id', $lead->contact_id)->firstOrFail();
        expect($event->event_data['country_raw'] ?? null)->toBe('Wakanda'); // crudo preservado
    });
});

it('6) el país nunca se copia a nacionalidad', function () {
    [$institution] = leadIntakeCtx();
    $id = postIntake(LEAD_INTAKE_TOKEN, intakePayload(['email' => 'nat@empresa.com', 'country' => 'DO']))
        ->assertStatus(201)->json('lead_id');

    app(CurrentInstitution::class)->runFor($institution->id, function () use ($id) {
        $contact = Lead::query()->findOrFail($id)->contact;
        expect($contact->country)->toBe('DO');
        // No existe (ni se puebla) ninguna nacionalidad derivada del país.
        expect($contact->getAttribute('nationality'))->toBeNull();
    });
});

// ===========================================================================
// Identidad del lead — email (esquema) y bloqueo de email-o-teléfono
// ===========================================================================

it('7) lead con email → 201 created', function () {
    leadIntakeCtx();
    postIntake(LEAD_INTAKE_TOKEN, intakePayload(['email' => 'withmail@empresa.com']))
        ->assertStatus(201)->assertJson(['ok' => true, 'action' => 'created']);
});

it('8) lead con teléfono y SIN email — PENDIENTE de cambio de esquema (contacts.email NOT NULL)', function () {
    // BLOQUEADO por esquema: contacts.email es NOT NULL + UNIQUE(institution_id,email).
    // No se implementa con emails ficticios; requiere decisión de esquema/diseño (ver informe).
    expect(true)->toBeTrue();
})->skip('contacts.email NOT NULL: email-o-teléfono requiere cambio de esquema pendiente de aprobación.');

it('9) faltan email y teléfono → 422 (email es obligatorio por esquema hoy)', function () {
    [$institution] = leadIntakeCtx();

    $payload = intakePayload();
    unset($payload['email'], $payload['phone']);

    postIntake(LEAD_INTAKE_TOKEN, $payload)
        ->assertStatus(422)->assertJsonStructure(['errors' => ['email']]);

    expect(intakeCounts($institution->id)['leads'])->toBe(0);
});

// ===========================================================================
// Consentimiento — verdadero, falso, ausente (null); nunca inventado
// ===========================================================================

it('10) consentimiento verdadero → se sella consent_at + consent_source', function () {
    [$institution] = leadIntakeCtx();
    $id = postIntake(LEAD_INTAKE_TOKEN, intakePayload([
        'email' => 'consent-true@empresa.com', 'consent' => true, 'consent_source' => 'web_form',
    ]))->assertStatus(201)->json('lead_id');

    app(CurrentInstitution::class)->runFor($institution->id, function () use ($id) {
        $contact = Lead::query()->findOrFail($id)->contact;
        expect($contact->consent_at)->not->toBeNull();
        expect($contact->consent_source)->toBe('web_form');
    });
});

it('11) consentimiento falso → NO se sella consentimiento', function () {
    [$institution] = leadIntakeCtx();
    $id = postIntake(LEAD_INTAKE_TOKEN, intakePayload(['email' => 'consent-false@empresa.com', 'consent' => false]))
        ->assertStatus(201)->json('lead_id');

    app(CurrentInstitution::class)->runFor($institution->id, function () use ($id) {
        expect(Lead::query()->findOrFail($id)->contact->consent_at)->toBeNull();
    });
});

it('12) consentimiento ausente → queda null (no se inventa)', function () {
    [$institution] = leadIntakeCtx();
    $payload = intakePayload(['email' => 'consent-null@empresa.com']);
    // sin claves de consentimiento
    $id = postIntake(LEAD_INTAKE_TOKEN, $payload)->assertStatus(201)->json('lead_id');

    app(CurrentInstitution::class)->runFor($institution->id, function () use ($id) {
        expect(Lead::query()->findOrFail($id)->contact->consent_at)->toBeNull();
    });
});

// ===========================================================================
// Idempotencia — identificador obligatorio y reintento estable
// ===========================================================================

it('13) sin Idempotency-Key ni request_id → 422', function () {
    [$institution] = leadIntakeCtx();

    $payload = intakePayload();
    unset($payload['request_id']);

    postIntake(LEAD_INTAKE_TOKEN, $payload)
        ->assertStatus(422)->assertJsonStructure(['errors' => ['request_id']]);

    expect(intakeCounts($institution->id)['leads'])->toBe(0);
});

it('13b) el request_id puede llegar por la cabecera Idempotency-Key', function () {
    leadIntakeCtx();
    $payload = intakePayload(['email' => 'hdr@empresa.com']);
    unset($payload['request_id']);

    postIntake(LEAD_INTAKE_TOKEN, $payload, ['Idempotency-Key' => 'EVT-777'])
        ->assertStatus(201)->assertJson(['request_id' => 'EVT-777']);
});

it('14) reintento con el MISMO identificador → duplicate, un solo lead', function () {
    [$institution] = leadIntakeCtx();

    $first = postIntake(LEAD_INTAKE_TOKEN, intakePayload(['email' => 'idem@empresa.com', 'request_id' => 'REQ-STABLE-1']))
        ->assertStatus(201)->assertJson(['action' => 'created']);
    $second = postIntake(LEAD_INTAKE_TOKEN, intakePayload(['email' => 'idem@empresa.com', 'request_id' => 'REQ-STABLE-1']))
        ->assertStatus(200)->assertJson(['action' => 'duplicate']);

    expect($second->json('lead_id'))->toBe($first->json('lead_id'));
    expect(intakeCounts($institution->id)['leads'])->toBe(1);
});

// ===========================================================================
// Resolución de programa — code / idnumber / nombre / alias; sin asociación errónea
// ===========================================================================

it('15) resuelve el programa por CODE del catálogo', function () {
    [$institution, , $program] = leadIntakeCtx();
    $id = postIntake(LEAD_INTAKE_TOKEN, intakePayload(['email' => 'p-code@empresa.com', 'program' => 'MC-050']))
        ->assertStatus(201)->json('lead_id');

    app(CurrentInstitution::class)->runFor($institution->id, fn () => expect(Lead::query()->findOrFail($id)->program_id)->toBe($program->id));
});

it('16) resuelve el programa por COURSE_IDNUMBER de Moodle', function () {
    [$institution, , $program] = leadIntakeCtx();
    $id = postIntake(LEAD_INTAKE_TOKEN, intakePayload(['email' => 'p-idn@empresa.com', 'program' => 'CORP-101']))
        ->assertStatus(201)->json('lead_id');

    app(CurrentInstitution::class)->runFor($institution->id, fn () => expect(Lead::query()->findOrFail($id)->program_id)->toBe($program->id));
});

it('17) resuelve el programa por NOMBRE oficial y por ALIAS controlado', function () {
    [$institution, , $program] = leadIntakeCtx();

    // Por nombre oficial (sin distinguir mayúsculas).
    $byName = postIntake(LEAD_INTAKE_TOKEN, intakePayload(['email' => 'p-name@empresa.com', 'program' => 'liderazgo corporativo']))
        ->assertStatus(201)->json('lead_id');
    app(CurrentInstitution::class)->runFor($institution->id, fn () => expect(Lead::query()->findOrFail($byName)->program_id)->toBe($program->id));

    // Por alias controlado (config).
    config(['crm.lead_intake.program_aliases' => ['micro-mba' => 'MC-050']]);
    $byAlias = postIntake(LEAD_INTAKE_TOKEN, intakePayload(['email' => 'p-alias@empresa.com', 'product_type' => 'programa_ejecutivo', 'program' => 'micro-mba']))
        ->assertStatus(201)->json('lead_id');
    app(CurrentInstitution::class)->runFor($institution->id, fn () => expect(Lead::query()->findOrFail($byAlias)->program_id)->toBe($program->id));
});

it('18) programa NO resuelto: no asocia a uno incorrecto y marca pendiente (sin perder el lead)', function () {
    [$institution] = leadIntakeCtx();
    $id = postIntake(LEAD_INTAKE_TOKEN, intakePayload(['email' => 'p-unk@empresa.com', 'program' => 'CODIGO-INEXISTENTE-999']))
        ->assertStatus(201)->json('lead_id');

    app(CurrentInstitution::class)->runFor($institution->id, function () use ($id) {
        $lead = Lead::query()->findOrFail($id);
        expect($lead->program_id)->toBeNull(); // no se asoció a un programa incorrecto
        $event = Event::query()->where('event_type', 'lead_intake')->where('contact_id', $lead->contact_id)->firstOrFail();
        expect($event->event_data['program_pending'] ?? null)->toBeTrue();
        expect($event->event_data['program'] ?? null)->toBe('CODIGO-INEXISTENTE-999'); // interés original preservado
    });
});

// ===========================================================================
// Límite de tamaño
// ===========================================================================

it('payload demasiado grande → 413 y no crea nada', function () {
    [$institution] = leadIntakeCtx();
    $big = intakePayload(['email' => 'big@empresa.com', 'blob' => str_repeat('x', 20000)]);
    postIntake(LEAD_INTAKE_TOKEN, $big)->assertStatus(413);
    expect(intakeCounts($institution->id)['leads'])->toBe(0);
});
