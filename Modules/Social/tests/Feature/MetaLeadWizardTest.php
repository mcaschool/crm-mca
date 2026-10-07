<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Catalog\Models\Program;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;
use Modules\Social\Livewire\LeadForms;
use Modules\Social\Models\MetaConnection;
use Modules\Social\Models\MetaLeadForm;
use Modules\Social\Models\MetaLeadPage;
use Modules\Social\Models\MetaLeadReceipt;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Services\MetaConnectionService;
use Modules\Social\Services\MetaLeadFormService;

/**
 * Asistente de Formularios publicitarios (por empresa, todo desde el panel): conectar desde el
 * propio asistente, elegir Página y formularios, destino (programa o contacto general), comprobar
 * acceso distinguiendo LISTAR formularios de LEER contactos, activar, pausar, buscar ahora y
 * transferir una Página entre empresas. Contactos con solo teléfono. Meta simulado.
 */
beforeEach(function () {
    config([
        'social.graph_version' => 'v26.0', 'social.meta.app_secret' => 'meta_test_secret',
        'social.meta_app_id' => 'APP_META_1', 'social.meta.login_config_id' => 'LOGIN_CFG_1', 'social.meta.fake_discovery' => null,
    ]);
});

/** @return array{0: Institution, 1: User, 2: Program, 3: Bot} */
function mwzCompany(string $name): array
{
    $inst = Institution::factory()->create(['name' => $name]);
    app(CurrentInstitution::class)->set($inst->id);

    return [
        $inst,
        User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']),
        Program::factory()->create(['code' => 'P-'.$inst->id, 'line' => 'doctorados', 'status' => 'active']),
        Bot::factory()->create(['assistant_name' => 'Asesor '.$name]),
    ];
}

/**
 * Meta simulado (una sola simulación por prueba que lee las respuestas vigentes).
 *
 * @param  array<string, array{0: array<string, mixed>, 1?: int}>  $o  exchange|accounts|debug|page|lead_access|forms|form_leads|lead
 */
function mwzMeta(array $o = [], string $pageId = 'PAGE_W', array $tasks = ['MANAGE', 'ADVERTISE']): void
{
    app()->instance('mwz.meta', $o + [
        'exchange' => [['access_token' => 'USER_TOKEN_W']],
        'accounts' => [['data' => [['id' => $pageId, 'name' => 'Academia W', 'access_token' => 'PAGE_TOKEN_W', 'tasks' => $tasks]]]],
        'debug' => [['data' => ['is_valid' => true, 'type' => 'USER', 'user_id' => '1000777', 'scopes' => ['pages_show_list']]]],
        'page' => [['id' => $pageId, 'name' => 'Academia W']],
        'lead_access' => [['has_lead_access' => ['app_has_leads_permission' => true, 'user_has_leads_permission' => true]]],
        'forms' => [['data' => [['id' => 'FORM_W1', 'name' => 'Solicitud de información']]]],
        'form_leads' => [['data' => []]],
        'lead' => [['platform' => 'fb', 'field_data' => [['name' => 'full_name', 'values' => ['Rosa Díaz']], ['name' => 'Teléfono de contacto', 'values' => ['+34 611 222 333']]]]],
    ]);
    if (app()->bound('mwz.faked')) {
        return;
    }
    app()->instance('mwz.faked', true);
    Http::fake(function (HttpRequest $r) {
        $map = app('mwz.meta');
        $path = (string) parse_url($r->url(), PHP_URL_PATH);
        $fields = (string) ($r->data()['fields'] ?? '');
        $key = match (true) {
            str_ends_with($path, '/oauth/access_token') => 'exchange',
            str_ends_with($path, '/me/accounts') => 'accounts',
            str_ends_with($path, '/debug_token') => 'debug',
            str_ends_with($path, '/leadgen_forms') => 'forms',
            str_ends_with($path, '/leads') => 'form_leads',
            str_starts_with($fields, 'has_lead_access') => 'lead_access',
            $fields === 'id,name' => 'page',
            default => 'lead',
        };
        [$body, $status] = $map[$key] + [1 => 200];

        return Http::response($body, $status);
    });
}

/** Conectar Meta DESDE EL ASISTENTE (lo que hace su botón tras autorizar en Facebook). */
function mwzConnect(User $admin, Institution $inst, string $code = 'CODE_OK'): Testable
{
    app(CurrentInstitution::class)->set($inst->id);
    $state = app(MetaConnectionService::class)->issueState($admin->id, $inst->id);

    return Livewire::actingAs($admin)->test(LeadForms::class)->call('connect', $state, $code);
}

/** Página elegida, verificada y recibiendo, con un formulario elegido y con destino. */
function mwzReceivingPage(Program $program, string $destination = 'program'): MetaLeadPage
{
    $page = MetaLeadPage::query()->sole();
    $page->forceFill(['selected' => true, 'access_status' => 'verified', 'receiving_enabled' => true])->save();
    MetaLeadForm::query()->create([
        'meta_lead_page_id' => $page->id, 'form_id' => 'FORM_W1', 'name' => 'Solicitud de información', 'is_active' => true,
        'destination' => $destination, 'program_id' => $destination === 'program' ? $program->id : null, 'receiving_since' => now()->subMinute(),
    ]);

    return $page;
}

/** @return array<int, string> estado de cada paso del asistente (done | current | pending) */
function mwzStates(Testable $c): array
{
    preg_match_all('/data-step="(\d)" data-state="(\w+)"/', $c->html(), $m);

    return array_combine(array_map('intval', $m[1]), $m[2]);
}

it('el asistente avanza paso a paso desde el panel, conectando Meta sin salir de él', function () {
    [$inst, $admin, $program, $bot] = mwzCompany('Academia W');
    mwzMeta();

    $c = Livewire::actingAs($admin)->test(LeadForms::class);
    expect(mwzStates($c))->toBe([1 => 'current', 2 => 'pending', 3 => 'pending', 4 => 'pending', 5 => 'pending']);

    $c = mwzConnect($admin, $inst)->assertSee('Conexión guardada: 1 Página disponible.')->assertSee('Reconectar Meta');
    expect(mwzStates($c))->toBe([1 => 'done', 2 => 'current', 3 => 'pending', 4 => 'pending', 5 => 'pending']);

    $page = MetaLeadPage::query()->sole();
    $c->call('selectPage', $page->id, true)->call('sync', $page->id);
    $form = MetaLeadForm::query()->sole();
    $c->call('chooseForm', $form->id, true);
    expect(mwzStates($c)[2])->toBe('done')->and(mwzStates($c)[3])->toBe('current');

    $c->call('setDestination', $form->id, (string) $program->id)->call('setAdvisor', $form->id, (string) $bot->id);
    expect(mwzStates($c)[3])->toBe('done')->and(mwzStates($c)[4])->toBe('current');

    $c->call('checkAccess', $page->id);
    expect(mwzStates($c)[4])->toBe('done')->and(mwzStates($c)[5])->toBe('current');

    $c->call('setReceiving', $page->id, true)->assertSee('Recibiendo contactos');
    expect(mwzStates($c))->toBe([1 => 'done', 2 => 'done', 3 => 'done', 4 => 'done', 5 => 'done'])
        ->and(SocialChannel::query()->count())->toBe(0);
});

it('un contacto con SOLO teléfono (campo personalizado) entra al CRM', function () {
    [, $admin, $program] = mwzCompany('Academia W');
    mwzMeta();
    mwzConnect($admin, app(CurrentInstitution::class)->id() === null ? Institution::query()->first() : Institution::query()->find(app(CurrentInstitution::class)->id()));
    mwzReceivingPage($program);

    expect(app(MetaLeadFormService::class)->processLeadgen(['leadgen_id' => 'L_PHONE', 'form_id' => 'FORM_W1', 'page_id' => 'PAGE_W']))->toBe('processed');

    $contact = Contact::query()->sole();
    expect($contact->email)->toBeNull()
        ->and($contact->phone)->toBe('+34 611 222 333')
        ->and($contact->first_name)->toBe('Rosa')
        ->and(Lead::query()->sole()->program_id)->toBe($program->id);
});

it('destino «contacto general»: el contacto entra sin programa y sin reglas de otra empresa', function () {
    [$inst, $admin, $program] = mwzCompany('Academia W');
    mwzMeta();
    mwzConnect($admin, $inst);
    mwzReceivingPage($program, 'general');

    expect(app(MetaLeadFormService::class)->processLeadgen(['leadgen_id' => 'L_GEN', 'form_id' => 'FORM_W1', 'page_id' => 'PAGE_W']))->toBe('processed');
    $lead = Lead::query()->sole();
    expect($lead->program_id)->toBeNull()->and($lead->product_type)->toBe('general');

    // Un programa de una línea fuera del mapa de tipos (doctorados) también vale: su tipo es la propia
    // línea. Y un formulario SIN campo de nombre (solo correo) no pierde el contacto: «Sin nombre».
    MetaLeadForm::query()->sole()->update(['destination' => 'program', 'program_id' => $program->id]);
    mwzMeta(['lead' => [['field_data' => [['name' => 'email', 'values' => ['otro@example.test']]]]]]);
    expect(app(MetaLeadFormService::class)->processLeadgen(['leadgen_id' => 'L_PROG', 'form_id' => 'FORM_W1', 'page_id' => 'PAGE_W']))->toBe('processed')
        ->and(Lead::query()->where('program_id', $program->id)->value('product_type'))->toBe('doctorados')
        ->and(Contact::query()->where('email', 'otro@example.test')->value('first_name'))->toBe('Sin nombre');
});

it('distingue listar formularios de leer contactos: sin permiso para listar, la lectura verificada permite recibir', function () {
    [$inst, $admin, $program] = mwzCompany('Academia W');
    mwzMeta();
    mwzConnect($admin, $inst);
    $page = MetaLeadPage::query()->sole();
    $page->forceFill(['selected' => true])->save();
    MetaLeadForm::query()->create(['meta_lead_page_id' => $page->id, 'form_id' => 'FORM_W1', 'name' => 'Solicitud', 'is_active' => true, 'destination' => 'program', 'program_id' => $program->id]);

    // Meta no deja LISTAR (403 real pages_manage_ads) pero sí LEER los contactos del formulario conocido.
    mwzMeta(['forms' => [['error' => ['message' => '(#200) Requires pages_manage_ads permission to manage the object', 'code' => 200, 'type' => 'OAuthException']], 403]]);
    Livewire::actingAs($admin)->test(LeadForms::class)->call('checkAccess', $page->id)
        ->assertSee('Acceso verificado')
        ->assertSee('Puedes recibir contactos; para traer formularios nuevos hace falta que Meta permita listarlos.')
        ->assertSee('Lo resuelve la plataforma del CRM')
        ->call('setReceiving', $page->id, true)->assertSee('Recibiendo contactos');
    expect(MetaLeadPage::query()->sole()->access_result['checks'])->toBe(['page' => 'ok', 'list_forms' => 'fail', 'read_contacts' => 'ok']);

    // Al revés: se pueden listar, pero no LEER (leads_retrieval): no se puede recibir.
    mwzMeta(['form_leads' => [['error' => ['message' => '(#200) Requires leads_retrieval permission to manage the object', 'code' => 200]], 403]]);
    Livewire::actingAs($admin)->test(LeadForms::class)->call('checkAccess', $page->id)->assertSee('Acceso con problemas');
    $page = MetaLeadPage::query()->sole();
    expect($page->access_result['checks'])->toBe(['page' => 'ok', 'list_forms' => 'ok', 'read_contacts' => 'fail'])
        ->and($page->receiving_enabled)->toBeFalse()
        ->and($page->access_result['issues'][0]['permissions'])->toBe(['leads_retrieval']);
});

it('pausar, reanudar y «Buscar contactos ahora» funcionan desde el panel', function () {
    [$inst, $admin, $program] = mwzCompany('Academia W');
    mwzMeta(['form_leads' => [['data' => [['id' => 'L_NOW']]]]]);
    mwzConnect($admin, $inst);
    $page = mwzReceivingPage($program);

    Livewire::actingAs($admin)->test(LeadForms::class)->call('fetchNow', $page->id)->assertSee('Búsqueda terminada: 1 contacto nuevo.');
    expect(Lead::query()->count())->toBe(1);

    $c = Livewire::actingAs($admin)->test(LeadForms::class)->call('setReceiving', $page->id, false)->assertSee('Recepción en pausa');
    mwzMeta(['form_leads' => [['data' => [['id' => 'L_LATER']]]]]);
    expect(app(MetaLeadFormService::class)->poll())->toBe(0);
    $c->call('fetchNow', $page->id)->assertSee('Activa la recepción de esta Página para buscar contactos.');

    $c->call('setReceiving', $page->id, true)->assertSee('Recibiendo contactos')->call('fetchNow', $page->id);
    expect(MetaLeadReceipt::query()->where('status', 'processed')->count())->toBe(2)->and(Lead::query()->count())->toBe(1); // misma persona: el CRM deduplica
});

it('transferir una Página entre empresas desde el panel: solo con control total en Meta; la otra conserva sus contactos', function () {
    // Empresa A usa la Página y ya recibió un contacto.
    [$instA, $adminA, $programA] = mwzCompany('Escuela A');
    mwzMeta();
    mwzConnect($adminA, $instA);
    $pageA = mwzReceivingPage($programA);
    MetaLeadReceipt::query()->create(['leadgen_id' => 'L_OLD', 'page_id' => 'PAGE_W', 'status' => 'processed']);

    // Empresa B conecta la misma Página SIN control total: no puede transferir.
    [$instB, $adminB] = mwzCompany('Escuela B');
    mwzMeta([], 'PAGE_W', ['ADVERTISE']);
    mwzConnect($adminB, $instB);
    $pageB = MetaLeadPage::query()->sole();
    Livewire::actingAs($adminB)->test(LeadForms::class)
        ->assertSee('La usa otra empresa')->assertDontSee('Transferir a mi empresa')
        ->call('transfer', $pageB->id)->assertSee('quien conecta Meta debe tener control total');

    // Con control total (otra persona administradora conecta desde B): transferencia.
    mwzMeta([], 'PAGE_W', ['MANAGE', 'ADVERTISE']);
    mwzConnect($adminB, $instB);
    Livewire::actingAs($adminB)->test(LeadForms::class)
        ->assertSee('Transferir a mi empresa')
        ->call('transfer', $pageB->id)->assertSee('La Página ya envía sus contactos a tu empresa.');
    expect(MetaLeadPage::query()->sole()->only(['selected', 'access_status']))->toBe(['selected' => true, 'access_status' => 'unchecked']);

    // A deja de recibir de inmediato, conserva su contacto y ve el aviso.
    app(CurrentInstitution::class)->set($instA->id);
    $pageA->refresh();
    expect($pageA->selected)->toBeFalse()->and($pageA->receiving_enabled)->toBeFalse()->and($pageA->released_at)->not->toBeNull()
        ->and(MetaLeadReceipt::query()->count())->toBe(1);
    Livewire::actingAs($adminA)->test(LeadForms::class)->assertSee('La Página pasó a otra empresa')->assertSee('Los contactos que ya recibiste siguen en tu CRM.');
});

it('reconectar desde el asistente no sustituye una conexión que funciona si la nueva falla', function () {
    [$inst, $admin] = mwzCompany('Academia W');
    $messenger = SocialChannel::factory()->create(['provider' => 'messenger', 'external_id' => 'PAGE_W', 'credentials' => ['token' => 'MESSENGER_OK']]);
    mwzMeta();
    mwzConnect($admin, $inst);

    mwzMeta(['exchange' => [['error' => ['message' => 'Invalid verification code format.', 'code' => 100]], 400]]);
    mwzConnect($admin, $inst, 'CODE_BAD')->assertSee('Meta rechazó la conexión');

    expect(MetaConnection::query()->sole()->token)->toBe('USER_TOKEN_W')
        ->and($messenger->fresh()->credentials['token'])->toBe('MESSENGER_OK');
});
