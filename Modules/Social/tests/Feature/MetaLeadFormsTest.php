<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
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
use Modules\Social\Models\MetaLeadForm;
use Modules\Social\Models\MetaLeadReceipt;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Services\MetaLeadFormService;

/**
 * Formularios publicitarios de Facebook/Instagram directos al CRM (Meta → CRM, sin n8n).
 * Preparado y DESACTIVADO hasta la aprobación de Meta. Sin Meta real (Http::fake).
 *
 * @return array{0: Institution, 1: SocialChannel, 2: MetaLeadForm, 3: Bot, 4: Program}
 */
function mlfCtx(): array
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);
    $page = SocialChannel::factory()->create(['provider' => 'messenger', 'external_id' => 'page_123', 'display_name' => 'MCA School']);
    $bot = Bot::factory()->create(['assistant_name' => 'Sofía']);
    $program = Program::factory()->create(['code' => 'DA-001', 'line' => 'diplomas_avanzados', 'status' => 'active']);
    $form = MetaLeadForm::query()->create(['social_channel_id' => $page->id, 'form_id' => 'form_9', 'name' => 'Diplomas · Septiembre', 'program_id' => $program->id, 'bot_id' => $bot->id, 'is_active' => true]);

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

it('desactivado por defecto: no consulta a Meta ni registra nada, y la pantalla lo explica sin términos técnicos', function () {
    [$inst, $page] = mlfCtx();
    Http::fake();
    $admin = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);

    expect(config('social.meta.lead_forms_enabled'))->toBeFalse()
        ->and(app(MetaLeadFormService::class)->handleWebhook(mlfWebhook()))->toBe(0)
        ->and(app(MetaLeadFormService::class)->syncForms($page)['ok'])->toBeFalse();
    Http::assertNothingSent();
    expect(MetaLeadReceipt::query()->count())->toBe(0)->and(Lead::query()->count())->toBe(0);

    $html = Livewire::actingAs($admin)->test(LeadForms::class)
        ->assertSee('Formularios publicitarios')->assertSee('Pendiente de aprobación de Meta.')
        ->assertSee('Diplomas · Septiembre')->html();
    foreach (['webhook', 'endpoint', 'token', 'payload', 'n8n', 'leadgen'] as $word) {
        expect(mb_strtolower(strip_tags($html)))->not->toContain($word);
    }
});

it('activado: crea contacto y lead con programa, asesor, consentimiento y atribución de la campaña', function () {
    [, , , $bot, $program] = mlfCtx();
    config(['social.meta.lead_forms_enabled' => true]);
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

    // Solo se habló con Meta: ningún intermediario.
    Http::assertNotSent(fn (HttpRequest $r) => ! str_contains($r->url(), 'graph.facebook.com'));
});

it('es idempotente por el id de Meta: un aviso repetido no duplica contacto ni lead', function () {
    mlfCtx();
    config(['social.meta.lead_forms_enabled' => true]);
    Http::fake(['graph.facebook.com/*' => Http::response(mlfMetaLead())]);
    $service = app(MetaLeadFormService::class);

    $service->handleWebhook(mlfWebhook());
    expect($service->processLeadgen(mlfWebhook()['entry'][0]['changes'][0]['value']))->toBe('duplicate');

    expect(Lead::query()->count())->toBe(1)->and(Contact::query()->count())->toBe(1)->and(MetaLeadReceipt::query()->count())->toBe(1);
    Http::assertSentCount(1);
});

it('errores comprensibles: formulario no activado, sin programa, sin datos de contacto o Meta no responde', function () {
    [, , $form] = mlfCtx();
    config(['social.meta.lead_forms_enabled' => true]);
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
        'l_d' => 'No se pudieron leer los datos del contacto en Meta (permiso pendiente o conexión caducada).',
    ])->and(Lead::query()->count())->toBe(0);
});

it('aislamiento: el aviso de una Página solo crea datos en la institución de esa Página', function () {
    [$instA] = mlfCtx();
    config(['social.meta.lead_forms_enabled' => true]);
    Http::fake(['graph.facebook.com/*' => Http::response(mlfMetaLead())]);
    $instB = Institution::factory()->create();

    app(CurrentInstitution::class)->set($instB->id);
    app(MetaLeadFormService::class)->handleWebhook(mlfWebhook());

    expect(Lead::query()->count())->toBe(0); // nada en B
    app(CurrentInstitution::class)->set($instA->id);
    expect(Lead::query()->count())->toBe(1);
});

it('con la aprobación, «Actualizar formularios» trae los formularios de la Página', function () {
    [$inst, $page] = mlfCtx();
    config(['social.meta.lead_forms_enabled' => true]);
    Http::fake(['graph.facebook.com/*/page_123/leadgen_forms*' => Http::response(['data' => [['id' => 'form_9', 'name' => 'Diplomas · Septiembre'], ['id' => 'form_10', 'name' => 'Maestrías 2027']]])]);
    $admin = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);

    Livewire::actingAs($admin)->test(LeadForms::class)->call('sync', $page->id)->assertSee('2 formulario(s) actualizado(s).')->assertSee('Maestrías 2027');
    expect(MetaLeadForm::query()->where('form_id', 'form_10')->value('is_active'))->toBeFalse(); // nuevo: desactivado
});
