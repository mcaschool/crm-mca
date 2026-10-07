<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Catalog\Models\Program;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Event;
use Modules\Crm\Models\Lead;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;
use Modules\Social\Livewire\LeadForms;
use Modules\Social\Models\MetaConnection;
use Modules\Social\Models\MetaLeadForm;
use Modules\Social\Models\MetaLeadPage;
use Modules\Social\Models\MetaLeadReceipt;
use Modules\Social\Services\MetaLeadFormService;

/**
 * Formularios publicitarios de Facebook/Instagram directos al CRM (Meta → CRM, sin n8n), por
 * empresa: una Página conectada por la empresa, con acceso verificado y recepción activada.
 * Sin Meta real (Http::fake).
 *
 * @return array{0: Institution, 1: MetaLeadPage, 2: MetaLeadForm, 3: Bot, 4: Program}
 */
function mlfCtx(bool $receiving = true): array
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);
    $connection = MetaConnection::query()->create(['token' => 'USER_TOKEN_A', 'status' => 'active', 'connected_at' => now()]);
    $page = MetaLeadPage::query()->create([
        'meta_connection_id' => $connection->id, 'page_id' => 'page_123', 'name' => 'MCA School', 'page_token' => 'PAGE_TOKEN_A',
        'selected' => true, 'access_status' => $receiving ? 'verified' : 'unchecked', 'receiving_enabled' => $receiving,
    ]);
    $bot = Bot::factory()->create(['assistant_name' => 'Sofía']);
    $program = Program::factory()->create(['code' => 'DA-001', 'line' => 'diplomas_avanzados', 'status' => 'active']);
    $form = MetaLeadForm::query()->create([
        'meta_lead_page_id' => $page->id, 'form_id' => 'form_9', 'name' => 'Diplomas · Septiembre', 'program_id' => $program->id,
        'bot_id' => $bot->id, 'is_active' => true, 'receiving_since' => now()->subHour(),
    ]);

    return [$inst, $page, $form, $bot, $program];
}

function mlfMetaLead(array $override = []): array
{
    return $override + [
        'created_time' => '2026-10-06T10:00:00+0000',
        'field_data' => [
            ['name' => 'full_name', 'values' => ['Laura Gómez Ruiz']],
            ['name' => 'email', 'values' => ['laura@example.test']],
            ['name' => 'phone_number', 'values' => ['+34600111222']],
        ],
        'campaign_name' => 'Diplomas otoño', 'adset_name' => 'España 25-45', 'ad_name' => 'Video 1',
        'platform' => 'ig', 'is_organic' => false,
    ];
}

function mlfWebhook(string $leadgenId = 'lead_1'): array
{
    return ['object' => 'page', 'entry' => [['id' => 'page_123', 'changes' => [['field' => 'leadgen', 'value' => [
        'leadgen_id' => $leadgenId, 'form_id' => 'form_9', 'page_id' => 'page_123', 'created_time' => 1759744800,
    ]]]]]];
}

it('apagado por defecto: una Página sin activar no registra nada y la pantalla no usa términos técnicos', function () {
    [$inst] = mlfCtx(receiving: false);
    Http::fake();
    $admin = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);

    expect(app(MetaLeadFormService::class)->processLeadgen(mlfWebhook()['entry'][0]['changes'][0]['value']))->toBe('not_receiving')
        ->and(app(MetaLeadFormService::class)->poll())->toBe(0);
    Http::assertNothingSent();
    expect(MetaLeadReceipt::query()->count())->toBe(0)->and(Lead::query()->count())->toBe(0);

    $html = Livewire::actingAs($admin)->test(LeadForms::class)
        ->assertSee('Formularios publicitarios')->assertSee('Conectar Meta')->assertSee('Activar la recepción')
        ->assertSee('Recepción apagada')->assertSee('Diplomas · Septiembre')->html();
    foreach (['webhook', 'endpoint', 'token', 'payload', 'n8n', 'leadgen', '.env'] as $word) {
        expect(mb_strtolower(strip_tags($html)))->not->toContain($word);
    }
});

it('recibiendo: crea contacto y lead con programa, asesor, consentimiento y atribución de la campaña', function () {
    [, , , $bot, $program] = mlfCtx();
    Http::fake(['graph.facebook.com/*/lead_1*' => Http::response(mlfMetaLead())]);

    expect(app(MetaLeadFormService::class)->handleWebhook(mlfWebhook()))->toBe(1);

    $contact = Contact::query()->sole();
    $lead = Lead::query()->sole();
    expect($contact->only(['first_name', 'last_name', 'email']))->toBe(['first_name' => 'Laura', 'last_name' => 'Gómez Ruiz', 'email' => 'laura@example.test'])
        ->and($lead->program_id)->toBe($program->id)
        ->and($lead->bot_id)->toBe($bot->id)
        ->and($lead->source)->toBe('meta_lead_ads');
    $event = Event::query()->where('event_type', 'meta_lead_received')->sole();
    expect($event->event_data)->toMatchArray(['platform' => 'instagram', 'campaign' => 'Diplomas otoño', 'adset' => 'España 25-45', 'ad' => 'Video 1', 'form' => 'Diplomas · Septiembre']);
    expect(MetaLeadReceipt::query()->sole()->only(['status', 'lead_id']))->toBe(['status' => 'processed', 'lead_id' => $lead->id]);

    // Con el token de la Página de la empresa; solo se habló con Meta.
    Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Bearer PAGE_TOKEN_A'));
    Http::assertNotSent(fn (HttpRequest $r) => ! str_contains($r->url(), 'graph.facebook.com'));
});

it('el sondeo programado recoge los contactos nuevos de los formularios activos, sin duplicar con el aviso', function () {
    mlfCtx();
    Http::fake([
        'graph.facebook.com/*/form_9/leads*' => Http::response(['data' => [['id' => 'lead_1', 'created_time' => '2026-10-06T10:00:00+0000'], ['id' => 'lead_2']]]),
        'graph.facebook.com/*/lead_*' => Http::response(mlfMetaLead()),
    ]);

    app(MetaLeadFormService::class)->handleWebhook(mlfWebhook('lead_1')); // llegó antes por aviso
    Artisan::call('social:meta-leads-poll');

    expect(MetaLeadReceipt::query()->pluck('status', 'leadgen_id')->all())->toBe(['lead_1' => 'processed', 'lead_2' => 'processed'])
        ->and(Lead::query()->count())->toBe(1) // mismo contacto: el CRM lo deduplica
        ->and(MetaLeadPage::query()->sole()->last_polled_at)->not->toBeNull();
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'form_9/leads') && str_contains(urldecode($r->url()), 'time_created'));
});

it('si la conexión no puede leer los datos del anuncio, el contacto entra igualmente sin ellos', function () {
    mlfCtx();
    Http::fake(function (HttpRequest $r) {
        return str_contains((string) ($r->data()['fields'] ?? ''), 'campaign_name')
            ? Http::response(['error' => ['message' => '(#200) Requires ads_management permission', 'code' => 200]], 403)
            : Http::response(mlfMetaLead(['campaign_name' => null, 'adset_name' => null, 'ad_name' => null]));
    });

    expect(app(MetaLeadFormService::class)->processLeadgen(mlfWebhook()['entry'][0]['changes'][0]['value']))->toBe('processed')
        ->and(Lead::query()->count())->toBe(1);
});

it('es idempotente por el id de Meta: un aviso repetido no duplica contacto ni lead', function () {
    mlfCtx();
    Http::fake(['graph.facebook.com/*' => Http::response(mlfMetaLead())]);
    $service = app(MetaLeadFormService::class);

    $service->handleWebhook(mlfWebhook());
    expect($service->processLeadgen(mlfWebhook()['entry'][0]['changes'][0]['value']))->toBe('duplicate');

    expect(Lead::query()->count())->toBe(1)->and(Contact::query()->count())->toBe(1)->and(MetaLeadReceipt::query()->count())->toBe(1);
    Http::assertSentCount(1);
});

it('errores comprensibles: formulario no activado, sin programa, sin datos de contacto o Meta no responde', function () {
    [, , $form] = mlfCtx();
    $service = app(MetaLeadFormService::class);
    $value = fn (string $id) => ['leadgen_id' => $id, 'form_id' => 'form_9', 'page_id' => 'page_123'];

    $form->update(['is_active' => false]);
    expect($service->processLeadgen($value('l_a')))->toBe('skipped');

    $form->update(['is_active' => true, 'program_id' => null]);
    expect($service->processLeadgen($value('l_b')))->toBe('failed');

    $form->update(['program_id' => Program::query()->value('id')]);
    Http::fake(['graph.facebook.com/*/l_c*' => Http::response(mlfMetaLead(['field_data' => [['name' => 'full_name', 'values' => ['Sin Datos']]]])), 'graph.facebook.com/*/l_d*' => Http::response(['error' => ['message' => 'x']], 403)]);
    expect($service->processLeadgen($value('l_c')))->toBe('failed')
        ->and($service->processLeadgen($value('l_d')))->toBe('failed')
        ->and($service->processLeadgen(['leadgen_id' => 'l_e', 'form_id' => 'form_9', 'page_id' => 'pagina_desconocida']))->toBe('unknown_page');

    expect(MetaLeadReceipt::query()->orderBy('id')->pluck('error', 'leadgen_id')->all())->toBe([
        'l_a' => 'El formulario no está activado en el CRM.',
        'l_b' => 'Asigna al formulario un programa con tipo de producto reconocido.',
        'l_c' => 'El contacto no trae correo ni teléfono.',
        'l_d' => 'No se pudieron leer los datos del contacto en Meta (acceso denegado o conexión caducada).',
    ])->and(Lead::query()->count())->toBe(0);
});

it('aislamiento: el aviso de una Página solo crea datos en la empresa de esa Página', function () {
    [$instA] = mlfCtx();
    Http::fake(['graph.facebook.com/*' => Http::response(mlfMetaLead())]);
    $instB = Institution::factory()->create();

    app(CurrentInstitution::class)->set($instB->id);
    app(MetaLeadFormService::class)->handleWebhook(mlfWebhook());

    expect(Lead::query()->count())->toBe(0)->and(MetaLeadReceipt::query()->count())->toBe(0); // nada en B
    app(CurrentInstitution::class)->set($instA->id);
    expect(Lead::query()->count())->toBe(1)->and(MetaLeadReceipt::query()->count())->toBe(1);
});

it('un formulario de otra Página no se procesa con esta Página', function () {
    [, $page, $form] = mlfCtx();
    $other = MetaLeadPage::query()->create(['meta_connection_id' => $page->meta_connection_id, 'page_id' => 'page_999', 'name' => 'Otra', 'page_token' => 'T', 'selected' => true]);
    $form->update(['meta_lead_page_id' => $other->id]);
    Http::fake(['graph.facebook.com/*' => Http::response(mlfMetaLead())]);

    expect(app(MetaLeadFormService::class)->processLeadgen(mlfWebhook()['entry'][0]['changes'][0]['value']))->toBe('skipped')
        ->and(Lead::query()->count())->toBe(0);
});

it('«Actualizar formularios» trae los formularios de la Página, nuevos y desactivados', function () {
    [$inst, $page] = mlfCtx();
    Http::fake(['graph.facebook.com/*/page_123/leadgen_forms*' => Http::response(['data' => [['id' => 'form_9', 'name' => 'Diplomas · Septiembre'], ['id' => 'form_10', 'name' => 'Maestrías 2027']]])]);
    $admin = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);

    Livewire::actingAs($admin)->test(LeadForms::class)->call('sync', $page->id)->assertSee('2 formularios actualizados.')->assertSee('Maestrías 2027');
    expect(MetaLeadForm::query()->where('form_id', 'form_10')->value('is_active'))->toBeFalse()
        ->and(MetaLeadForm::query()->where('form_id', 'form_10')->value('meta_lead_page_id'))->toBe($page->id);
});

it('la parada de emergencia del operador detiene la recepción de todas las empresas', function () {
    mlfCtx();
    config(['social.meta.lead_forms_kill_switch' => true]);
    Http::fake();

    expect(app(MetaLeadFormService::class)->handleWebhook(mlfWebhook()))->toBe(0)
        ->and(app(MetaLeadFormService::class)->poll())->toBe(0);
    Http::assertNothingSent();
});
