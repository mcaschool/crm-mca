<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\QueryException;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Exceptions\InvalidContactDataException;
use Modules\Crm\Livewire\Leads\Create;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Crm\Services\ContactService;
use Modules\Crm\Support\ContactDataNormalizer;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;
use Modules\Integrations\Models\Integration;

/**
 * Capa ÚNICA de normalización y validación de contactos (ContactDataNormalizer, aplicada por
 * ContactService): ningún dato externo inválido, sintético o demasiado largo llega a provocar un
 * error SQL, venga del canal que venga. Sin truncar, sin inventar, con errores que nombran el
 * campo (nunca el valor).
 */
const CDN_TOKEN = 'TOK_cdn_intake_0123456789abcdef01';

function cdnInstitution(): Institution
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);

    return $institution;
}

/** @param  array<string, mixed>  $data */
function cdnSave(array $data, bool $strict = false): Contact
{
    return app(ContactService::class)->createOrUpdate($data, $strict);
}

/**
 * @param  array<string, mixed>  $data
 * @return array<string, string> errores de la capa común (campo => motivo)
 */
function cdnErrors(array $data): array
{
    try {
        cdnSave($data);
    } catch (InvalidContactDataException $e) {
        return $e->errors;
    }

    return [];
}

/** Institución con bot activo e integración n8n con el token de captación (API). */
function cdnApi(): Institution
{
    $institution = cdnInstitution();
    Bot::factory()->create(['status' => 'active']);
    $integration = Integration::factory()->create(['institution_id' => $institution->id, 'type' => 'n8n', 'status' => 'active']);
    $integration->replaceSecrets(['webhook_url' => 'https://n8n.example/webhook', 'signing_secret' => 'hmac', 'lead_intake_token' => CDN_TOKEN]);
    $integration->save();

    return $institution;
}

/** @param  array<string, mixed>  $overrides */
function cdnPost(array $overrides = [], array $without = [])
{
    $payload = array_merge([
        'request_id' => 'REQ-'.bin2hex(random_bytes(6)),
        'email' => 'lead@empresa.test',
        'first_name' => 'Juan',
        'phone' => '+1 809 555 1234',
        'product_type' => 'maestria',
        'source' => 'website',
    ], $overrides);
    foreach ($without as $key) {
        unset($payload[$key]);
    }

    return test()->withHeaders(['Authorization' => 'Bearer '.CDN_TOKEN])->postJson('/api/v1/leads/intake', $payload);
}

// ─────────────────────────────── 1–7 · Teléfono

it('1) E.164: se conserva y se normaliza para deduplicar', function () {
    cdnInstitution();
    $c = cdnSave(['email' => 'a@x.test', 'phone' => '+34600111222']);

    expect($c->phone)->toBe('+34600111222')->and($c->phone_normalized)->toBe('+34600111222');
});

it('2) con espacios, guiones o paréntesis: se conserva como llega (presentación) y se normaliza', function () {
    cdnInstitution();
    $c = cdnSave(['email' => 'a@x.test', 'phone' => '+1 (809) 555-1234']);

    expect($c->phone)->toBe('+1 (809) 555-1234')->and($c->phone_normalized)->toBe('+18095551234');
    // Un número nacional sin prefijo NO se inventa país: queda como presentación, sin clave.
    $n = cdnSave(['email' => 'b@x.test', 'phone' => '611 222 333']);
    expect($n->phone)->toBe('611 222 333')->and($n->phone_normalized)->toBeNull();
});

it('3) y 4) vacío o null: el teléfono queda null y el contacto entra por su correo', function (mixed $phone) {
    cdnInstitution();
    $c = cdnSave(['email' => 'a@x.test', 'first_name' => 'Ana', 'phone' => $phone]);

    expect($c->exists)->toBeTrue()->and($c->phone)->toBeNull()->and($c->phone_normalized)->toBeNull();
})->with(['vacío' => [''], 'solo espacios' => ["  \u{00A0} "], 'null' => [null]]);

it('5) el teléfono ficticio de Meta («<test lead: dummy data…>») se trata como ausente, nunca como dato', function () {
    cdnInstitution();
    $c = cdnSave(['email' => 'test@fb.com', 'first_name' => '<test lead: dummy data for full_name>', 'phone' => '<test lead: dummy data for phone_number>']);

    expect($c->phone)->toBeNull()->and($c->phone_normalized)->toBeNull()
        ->and($c->first_name)->toBe('Sin nombre') // el nombre ficticio tampoco se guarda
        ->and(ContactDataNormalizer::isProviderPlaceholder('<TEST LEAD: dummy data for email>'))->toBeTrue()
        ->and(ContactDataNormalizer::isProviderPlaceholder('+34 600 111 222'))->toBeFalse()
        ->and(ContactDataNormalizer::isProviderPlaceholder('<b>Laura</b>'))->toBeFalse();

    // Si el ficticio era la ÚNICA identidad, no se crea un contacto anónimo: error controlado.
    expect(cdnErrors(['phone' => '<test lead: dummy data for phone_number>']))->toHaveKey('email')
        ->and(Contact::query()->count())->toBe(1);
});

it('6) extremadamente largo: se rechaza con el campo, sin truncar y sin tocar la base de datos', function (string $phone) {
    cdnInstitution();

    expect(cdnErrors(['email' => 'a@x.test', 'phone' => $phone]))->toHaveKey('phone')
        ->and(Contact::query()->count())->toBe(0);
})->with([
    '31 caracteres' => ['+34 600 111 222 333 444 555 666'],
    '5000 dígitos' => [str_repeat('9', 5000)],
    'texto libre' => ['llámame por la tarde, gracias'],
]);

it('7) Unicode y espacios exteriores: NFC, espacios Unicode y anchura cero fuera', function () {
    cdnInstitution();
    $c = cdnSave([
        'email' => "  \u{00A0}Laura@Example.TEST\u{200B} ",
        'first_name' => "  Jose\u{0301}\u{00A0}\u{00A0}María\t",  // «José» descompuesto + NBSP + tab
        'last_name' => "\u{FEFF}Gómez  Ruiz ",
        'phone' => "\u{00A0}+34\u{2009}600 111 222\u{200B} ",
    ]);

    expect($c->email)->toBe('laura@example.test')
        ->and($c->first_name)->toBe('José María')
        ->and(mb_strlen($c->first_name))->toBe(10)
        ->and($c->last_name)->toBe('Gómez Ruiz')
        ->and($c->phone)->toBe('+34 600 111 222')
        ->and($c->phone_normalized)->toBe('+34600111222');
});

// ─────────────────────────────── 8 · Otros campos externos

it('8) correo o nombre por encima del máximo del esquema: error con el campo; el límite exacto entra', function () {
    cdnInstitution();

    $domain = fn (int $labels): string => implode('.', array_fill(0, $labels, str_repeat('d', 60))).'.es';
    expect(cdnErrors(['email' => 'a@'.$domain(4)]))->toHaveKey('email') // 248 > 190
        ->and(cdnErrors(['email' => 'a@x.test', 'first_name' => str_repeat('ñ', 81)]))->toHaveKey('first_name')
        ->and(cdnErrors(['email' => 'a@x.test', 'last_name' => str_repeat('x', 81)]))->toHaveKey('last_name')
        ->and(cdnErrors(['email' => 'no-es-correo']))->toHaveKey('email')
        ->and(cdnErrors(['email' => 'a@x.test', 'country' => 'España']))->toHaveKey('country')
        ->and(cdnErrors(['email' => 'a@x.test', 'preferred_language' => 'fr']))->toHaveKey('preferred_language')
        ->and(cdnErrors(['email' => 'a@x.test', 'consent' => true, 'consent_source' => str_repeat('s', 61)]))->toHaveKey('consent_source')
        ->and(Contact::query()->count())->toBe(0);

    // Exactamente en el límite (multibyte cuenta como un carácter, como en MySQL utf8mb4).
    $c = cdnSave(['email' => 'a@'.$domain(3), 'first_name' => str_repeat('ñ', 80), 'country' => 'es']);
    expect(mb_strlen($c->first_name))->toBe(80)->and(strlen((string) $c->email))->toBe(187)->and($c->country)->toBe('ES');

    // Los errores nombran el campo y el motivo, NUNCA el valor recibido.
    try {
        cdnSave(['email' => 'a@x.test', 'phone' => 'SECRETO-'.str_repeat('7', 40)]);
    } catch (InvalidContactDataException $e) {
        expect($e->getMessage().json_encode($e->errors))->not->toContain('SECRETO');
    }
});

// ─────────────────────────────── 9 · Alta manual

it('9) alta manual: el error aparece junto a su campo antes de guardar y no se crea nada', function () {
    $institution = cdnInstitution();
    Bot::factory()->create(['type' => 'ia', 'status' => 'active']);
    $this->actingAs(User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin', 'status' => 'active']));

    Livewire::test(Create::class)
        ->set('first_name', str_repeat('n', 81))->set('email', 'manual@x.test')->set('phone', str_repeat('5', 31))
        ->call('save')
        ->assertHasErrors(['first_name' => 'max', 'phone' => 'max']);

    // Pasa las reglas del formulario pero no la capa común (formato): error en «phone».
    Livewire::test(Create::class)
        ->set('first_name', 'Nuevo')->set('email', 'manual@x.test')->set('phone', 'mañana por la tarde')
        ->call('save')
        ->assertHasErrors(['phone']);
    expect(Contact::query()->count())->toBe(0)->and(Lead::query()->count())->toBe(0);

    Livewire::test(Create::class)
        ->set('first_name', 'Nuevo')->set('email', 'manual@x.test')->set('phone', '+52 55 1234 5678')
        ->call('save')
        ->assertHasNoErrors();
    expect(Contact::query()->sole()->phone_normalized)->toBe('+525512345678');
});

// ─────────────────────────────── 10 · Actualizar existente

it('10) actualizar un existente: lo inválido no lo toca; lo ficticio no borra el teléfono real', function () {
    cdnInstitution();
    $existing = cdnSave(['email' => 'a@x.test', 'first_name' => 'Ana', 'phone' => '+34600111222']);

    expect(cdnErrors(['email' => 'a@x.test', 'phone' => str_repeat('1', 40), 'first_name' => 'Otro']))->toHaveKey('phone');
    expect($existing->fresh()->only(['first_name', 'phone']))->toBe(['first_name' => 'Ana', 'phone' => '+34600111222']);

    $same = cdnSave(['email' => 'A@X.TEST', 'phone' => '<test lead: dummy data for phone_number>', 'last_name' => 'Ruiz']);
    expect($same->getKey())->toBe($existing->getKey())
        ->and($same->phone)->toBe('+34600111222')
        ->and($same->last_name)->toBe('Ruiz')
        ->and(Contact::query()->count())->toBe(1);
});

// ─────────────────────────────── 11 · API de captación

it('11) API: lo que rechaza la capa común es un 422 con el campo; lo ficticio no se guarda', function () {
    $institution = cdnApi();

    // Formato de teléfono no válido → 422 en «phone» (sin SQL, sin datos).
    cdnPost(['phone' => 'llámame mañana'])->assertStatus(422)->assertJsonStructure(['errors' => ['phone']])
        ->assertJsonMissingPath('exception');
    // Ficticio como ÚNICA identidad → 422 (sin contacto anónimo).
    cdnPost(['phone' => '<test lead: dummy>'], without: ['email'])->assertStatus(422)->assertJsonStructure(['errors' => ['phone']]);
    // Campos del lead por encima de su columna (varchar 80) → 422, no error SQL.
    cdnPost(['area' => str_repeat('a', 81)])->assertStatus(422)->assertJsonStructure(['errors' => ['area']]);
    expect(Contact::query()->count())->toBe(0)->and(Lead::query()->count())->toBe(0);

    // Ficticio con correo válido → 201, sin teléfono.
    $id = cdnPost(['email' => 'test@fb.com', 'phone' => '<test lead: dummy data>'])->assertStatus(201)->json('lead_id');
    expect(Lead::query()->findOrFail($id)->contact->phone)->toBeNull();

    // Sin nombre (el contrato lo permite): entra como «Sin nombre» en vez de fallar en la base de datos.
    $id = cdnPost(['email' => 'sinnombre@x.test'], without: ['first_name', 'phone'])->assertStatus(201)->json('lead_id');
    expect(Lead::query()->findOrFail($id)->contact->first_name)->toBe('Sin nombre')
        ->and($institution->id)->toBe(Contact::query()->latest('id')->value('institution_id'));
});

// ─────────────────────────────── 14 · Deduplicación (sin truncados que fusionen)

it('14) deduplicación intacta: mismo número en otro formato es el mismo contacto; uno más largo nunca se trunca para fusionar', function () {
    cdnInstitution();
    $a = cdnSave(['phone' => '+34 600 111 222', 'first_name' => 'Ana']);
    $b = cdnSave(['phone' => '0034-600-111-222', 'first_name' => 'Ana']);
    expect($b->getKey())->toBe($a->getKey());

    // Truncar «+34600111222 ext 99999999999…» a 30 coincidiría con A: se rechaza.
    expect(cdnErrors(['phone' => '+34600111222'.str_repeat('9', 30)]))->toHaveKey('phone')
        ->and(Contact::query()->count())->toBe(1);
    // Correo con mayúsculas/espacios: el mismo contacto (no un duplicado).
    $c = cdnSave(['email' => 'luis@x.test', 'first_name' => 'Luis']);
    expect(cdnSave(['email' => "  LUIS@x.TEST\u{00A0}"])->getKey())->toBe($c->getKey());
});

// ─────────────────────────────── 15 · Idempotencia

it('15) idempotencia: el reintento con el mismo identificador devuelve el mismo lead, aunque traiga datos ficticios', function () {
    cdnApi();
    $payload = ['request_id' => 'REQ-IDEM-1', 'email' => 'test@fb.com', 'phone' => '<test lead: dummy data>'];

    $first = cdnPost($payload)->assertStatus(201)->json('lead_id');
    cdnPost($payload)->assertStatus(200)->assertJson(['action' => 'duplicate', 'lead_id' => $first]);
    expect(Lead::query()->count())->toBe(1)->and(Contact::query()->count())->toBe(1);
});

// ─────────────────────────────── 16 · Aislamiento por institución

it('16) aislamiento: la normalización deduplica solo dentro de la institución', function () {
    $a = cdnInstitution();
    $inA = cdnSave(['email' => 'mismo@x.test', 'phone' => '+34600111222', 'first_name' => 'A']);

    $b = cdnInstitution();
    $inB = cdnSave(['email' => ' MISMO@x.test ', 'phone' => '+34 600-111-222', 'first_name' => 'B']);

    expect($inB->getKey())->not->toBe($inA->getKey())
        ->and($inB->institution_id)->toBe($b->id)
        ->and(app(CurrentInstitution::class)->runFor($a->id, fn () => Contact::query()->sole()->first_name))->toBe('A')
        ->and(app(CurrentInstitution::class)->runFor($b->id, fn () => Contact::query()->sole()->first_name))->toBe('B');
});

// ─────────────────────────────── 17 · Nunca una excepción SQL

it('17) ningún dato inválido provoca una excepción SQL: solo resultados controlados', function (array $data) {
    cdnInstitution();
    try {
        cdnSave($data + ['email' => 'safe@x.test']);
    } catch (InvalidContactDataException) {
        // controlado
    } catch (QueryException $e) {
        $this->fail('Llegó a MySQL: '.class_basename($e));
    }
    expect(Contact::query()->count())->toBeLessThanOrEqual(1);
})->with([
    'teléfono 31' => [['phone' => str_repeat('1', 31)]],
    'nombre 81' => [['first_name' => str_repeat('x', 81)]],
    'apellido 500' => [['last_name' => str_repeat('x', 500)]],
    'país largo' => [['country' => 'República Dominicana']],
    'idioma largo' => [['preferred_language' => 'español']],
    'origen de consentimiento 61' => [['consent' => true, 'consent_source' => str_repeat('w', 61)]],
    'fecha de consentimiento fuera de rango' => [['consent' => true, 'consent_at' => '9999-12-31 00:00:00']],
    'fecha de consentimiento basura' => [['consent' => true, 'consent_at' => 'ayer por la tarde']],
    'tipo array' => [['phone' => ['+34600111222']]],
    'tipo booleano' => [['first_name' => true]],
    'UTF-8 inválido' => [['first_name' => "Ana\xC3\x28"]],
    'correo enorme' => [['email' => str_repeat('a', 300).'@x.test']],
]);
