<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Catalog\Models\Program;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Event;
use Modules\Crm\Models\Lead;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;
use Modules\Social\Models\MetaConnection;
use Modules\Social\Models\MetaLeadForm;
use Modules\Social\Models\MetaLeadPage;
use Modules\Social\Models\MetaLeadReceipt;
use Modules\Social\Services\MetaLeadFormService;
use Modules\Social\Services\MetaLeadPageService;

/**
 * Formularios de Meta con la capa común de datos de contacto del CRM: el contacto de prueba de
 * Meta (todos sus campos son «<test lead: dummy data for …>» salvo el correo) recorre el MISMO
 * camino que uno real sin intentar guardar sus valores ficticios; un dato real inválido deja el
 * recibo fallido con un motivo útil, sin error SQL ni contacto duplicado. Meta simulado.
 */
const MDN_PHONE = '+34 600 111 222';

/** @return array{0: Institution, 1: MetaLeadPage, 2: MetaLeadForm, 3: Program} */
function mdnCtx(bool $receiving = true): array
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);
    $connection = MetaConnection::query()->create(['token' => 'USER_TOKEN_N', 'status' => 'active', 'connected_at' => now()]);
    $page = MetaLeadPage::query()->create([
        'meta_connection_id' => $connection->id, 'page_id' => 'page_n', 'name' => 'Academia N', 'page_token' => 'PAGE_TOKEN_N',
        'selected' => true, 'access_status' => 'verified', 'receiving_enabled' => $receiving,
    ]);
    $bot = Bot::factory()->create();
    $program = Program::factory()->create(['code' => 'MN-1', 'line' => 'maestrias', 'status' => 'active', 'name_es' => 'Maestría N']);
    $form = MetaLeadForm::query()->create([
        'meta_lead_page_id' => $page->id, 'form_id' => 'form_n', 'name' => 'Maestrías', 'program_id' => $program->id, 'destination' => 'program',
        'bot_id' => $bot->id, 'is_active' => true, 'receiving_since' => now()->subHour(),
    ]);

    return [$inst, $page, $form, $program];
}

/** Lo que devuelve Meta para su contacto de prueba (herramienta de pruebas / POST test_leads). */
function mdnDummyLead(): array
{
    return ['platform' => 'fb', 'field_data' => [
        ['name' => 'full_name', 'values' => ['<test lead: dummy data for full_name>']],
        ['name' => 'email', 'values' => ['test@fb.com']],
        ['name' => 'phone_number', 'values' => ['<test lead: dummy data for phone_number>']],
    ]];
}

/** @param  array<string, string>  $fields */
function mdnLead(array $fields): array
{
    return ['platform' => 'fb', 'field_data' => array_map(fn ($k, $v) => ['name' => $k, 'values' => [$v]], array_keys($fields), $fields)];
}

/**
 * Meta simulado con UNA sola simulación que lee las respuestas vigentes (Http::fake acumula).
 *
 * @param  array<string, mixed>  $o  leads (id => lead) | list | test_create | test_delete
 */
function mdnMeta(array $o): void
{
    app()->instance('mdn.meta', $o + ['leads' => [], 'list' => [], 'test_create' => [['id' => 'TEST_N1']], 'test_delete' => [['success' => true]]]);
    if (app()->bound('mdn.faked')) {
        return;
    }
    app()->instance('mdn.faked', true);
    Http::fake(function (HttpRequest $r) {
        $m = app('mdn.meta');
        $path = (string) parse_url($r->url(), PHP_URL_PATH);
        if ($r->method() === 'POST' && str_ends_with($path, '/test_leads')) {
            return Http::response(...$m['test_create']);
        }
        if ($r->method() === 'DELETE') {
            return Http::response(...$m['test_delete']);
        }
        if (str_ends_with($path, '/leads')) {
            return Http::response(['data' => $m['list']]);
        }
        $id = basename($path);

        return isset($m['leads'][$id]) ? Http::response($m['leads'][$id]) : Http::response(['error' => ['message' => 'Unknown']], 404);
    });
}

/** @return array{leadgen_id: string, form_id: string, page_id: string} */
function mdnValue(string $leadgenId): array
{
    return ['leadgen_id' => $leadgenId, 'form_id' => 'form_n', 'page_id' => 'page_n'];
}

it('12) aviso de Meta con su contacto de prueba: entra por el correo, sin teléfono ni nombre ficticios, y el reintento no duplica', function () {
    mdnCtx();
    mdnMeta(['leads' => ['lg_dummy' => mdnDummyLead()]]);

    expect(app(MetaLeadFormService::class)->processLeadgen(mdnValue('lg_dummy')))->toBe('processed');
    $contact = Contact::query()->sole();
    expect($contact->only(['email', 'phone', 'phone_normalized', 'first_name', 'last_name']))
        ->toBe(['email' => 'test@fb.com', 'phone' => null, 'phone_normalized' => null, 'first_name' => 'Sin nombre', 'last_name' => null])
        ->and(MetaLeadReceipt::query()->sole()->status)->toBe('processed');

    // 15) idempotencia por el id de Meta.
    expect(app(MetaLeadFormService::class)->processLeadgen(mdnValue('lg_dummy')))->toBe('duplicate')
        ->and(Contact::query()->count())->toBe(1)->and(Lead::query()->count())->toBe(1)->and(MetaLeadReceipt::query()->count())->toBe(1);
});

it('12b) un dato REAL inválido deja el recibo fallido con un motivo útil: sin error SQL, sin contacto y sin datos personales en el registro', function () {
    mdnCtx();
    $badPhone = '+34 600 111 222 333 444 555 666 777';
    mdnMeta(['leads' => ['lg_bad' => mdnLead(['full_name' => 'Rosa Díaz', 'email' => 'rosa@example.test', 'phone_number' => $badPhone])]]);
    Log::spy();

    expect(app(MetaLeadFormService::class)->processLeadgen(mdnValue('lg_bad')))->toBe('failed');
    $receipt = MetaLeadReceipt::query()->sole();
    expect($receipt->status)->toBe('failed')
        ->and($receipt->error)->toContain('phone')->toContain('30')
        ->and($receipt->error)->not->toContain('SQLSTATE')->not->toContain('600 111')
        ->and(Contact::query()->count())->toBe(0)->and(Lead::query()->count())->toBe(0);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $msg, array $ctx = []): bool => str_contains($msg, 'no válidos')
        && $ctx['fields'] === ['phone'] && ! str_contains((string) json_encode($ctx), 'rosa') && ! str_contains((string) json_encode($ctx), '600'));

    // Un aviso repetido no lo reprocesa ni crea un contacto (recibo único por id de Meta).
    expect(app(MetaLeadFormService::class)->processLeadgen(mdnValue('lg_bad')))->toBe('duplicate')
        ->and(Contact::query()->count())->toBe(0);
});

it('13) el sondeo programado (proceso asíncrono) no se rompe con datos inválidos: registra cada contacto con su resultado', function () {
    mdnCtx();
    mdnMeta([
        'list' => [['id' => 'lg_a'], ['id' => 'lg_b'], ['id' => 'lg_c']],
        'leads' => [
            'lg_a' => mdnDummyLead(),
            'lg_b' => mdnLead(['full_name' => str_repeat('Larguísimo', 9), 'email' => 'b@example.test']), // nombre de 90
            'lg_c' => mdnLead(['full_name' => 'Pedro Sanz', 'email' => 'pedro@example.test', 'phone_number' => MDN_PHONE]),
        ],
    ]);

    expect(Artisan::call('social:meta-leads-poll'))->toBe(0);
    expect(MetaLeadReceipt::query()->orderBy('leadgen_id')->pluck('status', 'leadgen_id')->all())
        ->toBe(['lg_a' => 'processed', 'lg_b' => 'failed', 'lg_c' => 'processed'])
        ->and(MetaLeadReceipt::query()->where('leadgen_id', 'lg_b')->value('error'))->toContain('first_name')
        ->and(Contact::query()->pluck('email')->sort()->values()->all())->toBe(['pedro@example.test', 'test@fb.com']);
});

it('18) y 20) prueba completa con el contacto de prueba de Meta: superada por el camino real, sin dejar datos, con el contacto de prueba borrado y la recepción apagada', function () {
    [, $page] = mdnCtx(receiving: false);
    mdnMeta(['leads' => ['TEST_N1' => mdnDummyLead()]]);

    $result = app(MetaLeadPageService::class)->receptionTest($page->fresh());

    expect($result['status'])->toBe('passed')->and($result['detail'])->toContain('Maestría N')
        ->and(Contact::query()->count())->toBe(0)->and(Lead::query()->count())->toBe(0)
        ->and(MetaLeadReceipt::query()->count())->toBe(0)
        ->and(Event::query()->whereIn('event_type', ['meta_lead_received', 'lead_intake'])->count())->toBe(0);
    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/TEST_N1'));

    $page = $page->fresh();
    expect($page->receiving_enabled)->toBeFalse()->and($page->receiving())->toBeFalse()
        ->and($page->access_result['reception_test']['status'])->toBe('passed');
});

it('18b) la prueba completa usa la validación REAL y no se da por superada si falla una fase (contacto inválido o borrado sin confirmar)', function () {
    [, $page] = mdnCtx(receiving: false);

    // Un dato real inválido en el contacto de prueba → falla igual que fallaría la recepción.
    mdnMeta(['leads' => ['TEST_N1' => mdnLead(['email' => 'no-es-un-correo'])]]);
    $failed = app(MetaLeadFormService::class)->rehearse($page, MetaLeadForm::query()->sole());
    expect($failed['status'])->toBe('failed')->and($failed['detail'])->toContain('email');
    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE'); // se borra igualmente

    // Meta no confirma el borrado del contacto de prueba → no superada.
    mdnMeta(['leads' => ['TEST_N1' => mdnDummyLead()], 'test_delete' => [['error' => ['message' => 'nope']], 400]]);
    $notDeleted = app(MetaLeadFormService::class)->rehearse($page, MetaLeadForm::query()->sole());
    expect($notDeleted['status'])->toBe('failed')->and($notDeleted['detail'])->toContain('borrado del contacto de prueba')
        ->and(Contact::query()->count())->toBe(0)->and(Lead::query()->count())->toBe(0)
        ->and($page->fresh()->receiving_enabled)->toBeFalse();
});

it('19) un contacto REAL con teléfono válido sigue creando contacto y lead, con el teléfono conservado y normalizado', function () {
    [, , , $program] = mdnCtx();
    mdnMeta(['leads' => ['lg_real' => mdnLead(['full_name' => 'Laura Gómez Ruiz', 'email' => 'laura@example.test', 'phone_number' => MDN_PHONE])]]);

    expect(app(MetaLeadFormService::class)->processLeadgen(mdnValue('lg_real')))->toBe('processed');
    $contact = Contact::query()->sole();
    expect($contact->only(['first_name', 'last_name', 'phone', 'phone_normalized']))
        ->toBe(['first_name' => 'Laura', 'last_name' => 'Gómez Ruiz', 'phone' => MDN_PHONE, 'phone_normalized' => '+34600111222'])
        ->and(Lead::query()->sole()->program_id)->toBe($program->id);

    // Solo teléfono (sin correo) también entra y deduplica por el normalizado.
    mdnMeta(['leads' => ['lg_tel' => mdnLead(['whatsapp' => '0034 600-111-222'])]]);
    expect(app(MetaLeadFormService::class)->processLeadgen(mdnValue('lg_tel')))->toBe('processed')
        ->and(Contact::query()->count())->toBe(1);
});
