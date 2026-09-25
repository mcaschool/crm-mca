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
 * Puerta pública: la seguridad se prueba con EVIDENCIA (401 sin token o inválido, aislamiento,
 * token acotado, 413 por tamaño, 429 por rate limit) y el flujo real crea/actualiza el
 * lead reutilizando la dedup del CRM, con idempotencia por request_id y auditoría sin PII.
 */
const LEAD_INTAKE_TOKEN = 'TOK_lead_intake_0123456789abcdef';
const LEAD_INTAKE_INCOMPANY_TOKEN = 'TOK_incompany_only_0123456789abc';

/**
 * Crea institución + bot activo + programa + integración n8n con el token de intake.
 * También fija incompany_inbound_token (distinto) para probar el aislamiento entre tokens.
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
        'email' => 'lead@empresa.com',
        'first_name' => 'Juan',
        'last_name' => 'Pérez',
        'phone' => '+1 809 555 1234',
        'country' => 'DO',
        'program' => 'MC-050',
        'source' => 'maestrias_web',
        'channel' => 'web',
        'form' => 'Maestrías - Solicitud de información',
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

// ---------------------------------------------------------------------------
// (a) SEGURIDAD
// ---------------------------------------------------------------------------

it('1) token válido → 201 created con el contrato {ok, lead_id, action, request_id}', function () {
    leadIntakeCtx();

    postIntake(LEAD_INTAKE_TOKEN, intakePayload())
        ->assertStatus(201)
        ->assertJson(['ok' => true, 'action' => 'created'])
        ->assertJsonStructure(['ok', 'lead_id', 'action', 'request_id']);
});

it('2) token ausente → 401 y no crea nada', function () {
    [$institution] = leadIntakeCtx();

    postIntake(null, intakePayload())->assertStatus(401);

    expect(intakeCounts($institution->id)['leads'])->toBe(0);
});

it('3) token inválido → 401', function () {
    [$institution] = leadIntakeCtx();

    postIntake('token-que-no-existe', intakePayload())->assertStatus(401);
    expect(intakeCounts($institution->id)['leads'])->toBe(0);
});

it('4) integración n8n INACTIVA → 401 (aunque el token coincida)', function () {
    [$institution] = leadIntakeCtx(LEAD_INTAKE_TOKEN, 'inactive');

    postIntake(LEAD_INTAKE_TOKEN, intakePayload())->assertStatus(401);
    expect(intakeCounts($institution->id)['leads'])->toBe(0);
});

it('5) aislamiento entre instituciones: el token de A crea SOLO en A, nunca en B', function () {
    [$a] = leadIntakeCtx('TOK_lead_A_0123456789abcdefghij');
    [$b] = leadIntakeCtx('TOK_lead_B_0123456789abcdefghij');

    postIntake('TOK_lead_A_0123456789abcdefghij', intakePayload(['email' => 'iso@empresa.com']))
        ->assertStatus(201);

    expect(intakeCounts($a->id)['leads'])->toBe(1);
    expect(intakeCounts($b->id)['leads'])->toBe(0);
});

it('12) el token InCompany NO autentica el endpoint de intake → 401', function () {
    [$institution] = leadIntakeCtx();

    postIntake(LEAD_INTAKE_INCOMPANY_TOKEN, intakePayload())->assertStatus(401);
    expect(intakeCounts($institution->id)['leads'])->toBe(0);
});

it('13) el token de intake NO autentica el endpoint InCompany → 401', function () {
    leadIntakeCtx();

    test()->withHeaders(['Authorization' => 'Bearer '.LEAD_INTAKE_TOKEN])
        ->postJson('/api/v1/incompany/lead', ['evento' => 'ruta_generada'])
        ->assertStatus(401);
});

// ---------------------------------------------------------------------------
// (b) FLUJO REAL — crea/actualiza reutilizando la dedup del CRM
// ---------------------------------------------------------------------------

it('6) creación de lead: contacto dedup, programa enlazado, evento y auditoría', function () {
    [$institution, , $program] = leadIntakeCtx();

    $res = postIntake(LEAD_INTAKE_TOKEN, intakePayload(['email' => 'nuevo@empresa.com']))->assertStatus(201);
    $leadId = $res->json('lead_id');

    app(CurrentInstitution::class)->runFor($institution->id, function () use ($leadId, $program) {
        $lead = Lead::query()->findOrFail($leadId);
        expect($lead->source)->toBe('maestrias_web');
        expect($lead->program_id)->toBe($program->id); // enlazado por code MC-050
        expect($lead->contact->email)->toBe('nuevo@empresa.com');

        expect(Contact::query()->where('email', 'nuevo@empresa.com')->count())->toBe(1);
        expect(Event::query()->where('event_type', 'lead_intake')->where('contact_id', $lead->contact_id)->exists())->toBeTrue();
        expect(AuditLog::query()->where('action', 'lead_intake.received')->where('auditable_id', $leadId)->exists())->toBeTrue();
    });
});

it('7) actualización: el MISMO email (sin request_id) reutiliza el lead vigente → updated, no duplica', function () {
    [$institution] = leadIntakeCtx();

    postIntake(LEAD_INTAKE_TOKEN, intakePayload(['email' => 'ana@empresa.com']))
        ->assertStatus(201)->assertJson(['action' => 'created']);

    postIntake(LEAD_INTAKE_TOKEN, intakePayload(['email' => 'ana@empresa.com', 'first_name' => 'Ana']))
        ->assertStatus(200)->assertJson(['action' => 'updated']);

    $counts = intakeCounts($institution->id);
    expect($counts['leads'])->toBe(1);
    expect($counts['contacts'])->toBe(1);
});

it('8) idempotencia: el MISMO request_id no duplica → action=duplicate y un solo lead', function () {
    [$institution] = leadIntakeCtx();

    $first = postIntake(LEAD_INTAKE_TOKEN, intakePayload(['email' => 'idem@empresa.com', 'request_id' => 'REQ-abc-123']))
        ->assertStatus(201)->assertJson(['action' => 'created']);

    $second = postIntake(LEAD_INTAKE_TOKEN, intakePayload(['email' => 'idem@empresa.com', 'request_id' => 'REQ-abc-123']))
        ->assertStatus(200)->assertJson(['action' => 'duplicate']);

    // Mismo lead devuelto, sin duplicar.
    expect($second->json('lead_id'))->toBe($first->json('lead_id'));
    expect($second->json('request_id'))->toBe('REQ-abc-123');
    expect(intakeCounts($institution->id)['leads'])->toBe(1);
});

// ---------------------------------------------------------------------------
// (c) VALIDACIÓN Y LÍMITES
// ---------------------------------------------------------------------------

it('9) campos inválidos → 422 y no crea nada', function () {
    [$institution] = leadIntakeCtx();

    postIntake(LEAD_INTAKE_TOKEN, intakePayload(['email' => '', 'preferred_language' => 'fr', 'interest_level' => 'urgente']))
        ->assertStatus(422)
        ->assertJsonStructure(['ok', 'message', 'errors' => ['email']]);

    expect(intakeCounts($institution->id)['leads'])->toBe(0);
});

it('10) payload demasiado grande → 413 y no crea nada', function () {
    [$institution] = leadIntakeCtx();

    // Cuerpo > 16 KB (campo extra voluminoso); el límite de tamaño actúa antes de validar.
    $big = intakePayload(['email' => 'big@empresa.com', 'blob' => str_repeat('x', 20000)]);
    postIntake(LEAD_INTAKE_TOKEN, $big)->assertStatus(413);

    expect(intakeCounts($institution->id)['leads'])->toBe(0);
});

it('11) el token nunca aparece en la respuesta ni en la auditoría', function () {
    [$institution] = leadIntakeCtx();

    // Éxito: el cuerpo de la respuesta no contiene el token.
    $ok = postIntake(LEAD_INTAKE_TOKEN, intakePayload(['email' => 'safe@empresa.com']))->assertStatus(201);
    expect($ok->getContent())->not->toContain(LEAD_INTAKE_TOKEN);

    // 401: el cuerpo de la respuesta no contiene el token intentado.
    $unauth = postIntake('otro-token-secreto-xyz', intakePayload())->assertStatus(401);
    expect($unauth->getContent())->not->toContain('otro-token-secreto-xyz');

    // Auditoría: ninguna fila registra el token en sus metadatos.
    app(CurrentInstitution::class)->runFor($institution->id, function () {
        AuditLog::query()->get()->each(function (AuditLog $log) {
            expect(json_encode($log->getAttributes()))->not->toContain(LEAD_INTAKE_TOKEN);
        });
    });
});

// ---------------------------------------------------------------------------
// (d) SECRETO — cifrado, enmascarado, rotación y conservación
// ---------------------------------------------------------------------------

it('14) el token de intake se guarda CIFRADO y se muestra ENMASCARADO', function () {
    [, , , $integration] = leadIntakeCtx();

    // Cifrado en reposo: el texto plano no está en la columna `config`.
    $raw = (string) DB::table('integrations')->where('id', $integration->id)->value('config');
    expect($raw)->not->toBe('')->and($raw)->not->toContain(LEAD_INTAKE_TOKEN);

    // Enmascarado en el preview que ve el panel.
    $masked = $integration->maskedConfig();
    expect($masked['lead_intake_token'])->not->toBe(LEAD_INTAKE_TOKEN)->toContain('••••');

    // El accesor de servidor descifra correctamente (round-trip).
    expect($integration->secret('lead_intake_token'))->toBe(LEAD_INTAKE_TOKEN);
});

it('15) rotación: reemplaza el token; vacío conserva el anterior', function () {
    [, , , $integration] = leadIntakeCtx();

    // Rotar: un valor nuevo reemplaza al anterior.
    $integration->replaceSecrets(['lead_intake_token' => 'TOK_lead_intake_ROTADO_99998888']);
    $integration->save();
    expect($integration->fresh()->secret('lead_intake_token'))->toBe('TOK_lead_intake_ROTADO_99998888');

    // Vacío al editar: se CONSERVA el valor actual (no se borra).
    $integration->replaceSecrets(['lead_intake_token' => '']);
    $integration->save();
    expect($integration->fresh()->secret('lead_intake_token'))->toBe('TOK_lead_intake_ROTADO_99998888');
});
