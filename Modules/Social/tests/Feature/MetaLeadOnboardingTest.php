<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
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
use Modules\Social\Livewire\MetaConnect;
use Modules\Social\Models\MetaConnection;
use Modules\Social\Models\MetaLeadForm;
use Modules\Social\Models\MetaLeadPage;
use Modules\Social\Models\MetaLeadReceipt;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Services\MetaConnectionService;
use Modules\Social\Services\MetaLeadPageService;

/**
 * Formularios publicitarios MULTIEMPRESA, todo desde el panel de cada empresa: Conectar Meta →
 * elegir Páginas y formularios → asignar programa y asesor → comprobar acceso → activar la
 * recepción. Sin .env, sin comandos manuales, sin n8n. Meta simulado con las respuestas reales
 * documentadas (incluido el 403 «Requires pages_manage_ads permission» observado en MCA).
 */
beforeEach(function () {
    config([
        'social.graph_version' => 'v26.0',
        'social.meta.app_secret' => 'meta_test_secret',
        'social.meta_app_id' => 'APP_META_1',
        'social.meta.login_config_id' => 'LOGIN_CFG_1',
        'social.meta.fake_discovery' => null,
    ]);
});

/** El 403 REAL que devolvió Meta a MCA al listar formularios. */
const MLO_403_PAGES_MANAGE_ADS = ['error' => [
    'message' => '(#200) Requires pages_manage_ads permission to manage the object',
    'type' => 'OAuthException', 'code' => 200, 'fbtrace_id' => 'AbCdEf123',
]];

/** @return array{0: Institution, 1: User, 2: Program, 3: Bot} */
function mloCompany(string $name, bool $operator = false): array
{
    $inst = Institution::factory()->create(['name' => $name]);
    app(CurrentInstitution::class)->set($inst->id);
    $admin = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin', 'is_super_admin' => $operator]);
    $program = Program::factory()->create(['code' => 'P-'.$inst->id, 'line' => 'diplomas_avanzados', 'status' => 'active']);
    $bot = Bot::factory()->create(['assistant_name' => 'Asesor '.$name]);

    return [$inst, $admin, $program, $bot];
}

/**
 * Meta simulado. Cada clave admite [cuerpo, estado]: exchange | accounts | debug | page |
 * lead_access | forms | form_leads | lead.
 *
 * @param  array<string, array{0: array<string, mixed>, 1?: int}>  $o
 */
function mloMeta(array $o = [], string $pageId = 'PAGE_B', string $pageToken = 'PAGE_TOKEN_B'): void
{
    $defaults = [
        'exchange' => [['access_token' => 'USER_TOKEN_1']],
        'accounts' => [['data' => [['id' => $pageId, 'name' => 'Escuela B', 'access_token' => $pageToken, 'tasks' => ['MANAGE', 'ADVERTISE']]]]],
        'debug' => [['data' => ['is_valid' => true, 'type' => 'USER', 'user_id' => '10001112223334', 'scopes' => ['pages_show_list', 'pages_read_engagement', 'pages_manage_ads', 'leads_retrieval'],
            'expires_at' => now()->addDays(60)->getTimestamp(), 'data_access_expires_at' => now()->addDays(90)->getTimestamp()]]],
        'page' => [['id' => $pageId, 'name' => 'Escuela B']],
        'lead_access' => [['has_lead_access' => ['app_has_leads_permission' => true, 'user_has_leads_permission' => true, 'can_access_lead' => true]]],
        'forms' => [['data' => [['id' => 'FORM_B1', 'name' => 'Diplomas · Otoño', 'status' => 'ACTIVE']]]],
        'form_leads' => [['data' => []]],
        'lead' => [['created_time' => '2026-10-07T10:00:00+0000', 'platform' => 'fb', 'campaign_name' => 'Otoño', 'field_data' => [
            ['name' => 'full_name', 'values' => ['Marta Ruiz']], ['name' => 'email', 'values' => ['marta@example.test']],
        ]]],
    ];
    // Http::fake() acumula simulaciones (la primera manda): se registra UNA vez por prueba y lee
    // las respuestas vigentes, para poder cambiar lo que «responde Meta» a mitad de una prueba.
    app()->instance('mlo.meta', $o + $defaults);
    if (app()->bound('mlo.faked')) {
        return;
    }
    app()->instance('mlo.faked', true);

    Http::fake(function (HttpRequest $r) {
        $map = app('mlo.meta');
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

/** «Conectar Meta» desde el panel (lo que hace el botón tras autorizar en Facebook). */
function mloConnect(User $admin, Institution $inst, string $code = 'CODE_OK'): Testable
{
    app(CurrentInstitution::class)->set($inst->id);
    $state = app(MetaConnectionService::class)->issueState($admin->id, $inst->id);

    return Livewire::actingAs($admin)->test(MetaConnect::class)->call('discover', $state, $code);
}

it('una segunda empresa completa el alta desde el panel: conectar, elegir, asignar, comprobar, activar y recibir', function () {
    // Primera empresa (MCA) ya funcionando con su propia Página.
    [$mca, $mcaAdmin] = mloCompany('MCA School');
    mloMeta([], 'PAGE_MCA', 'PAGE_TOKEN_MCA');
    mloConnect($mcaAdmin, $mca);
    $mcaPage = MetaLeadPage::query()->sole();
    app(MetaLeadPageService::class)->select($mcaPage, true);

    // Segunda empresa: TODO desde su panel.
    [$inst, $admin, $program, $bot] = mloCompany('Escuela B');
    mloMeta();
    mloConnect($admin, $inst)->assertSet('step', 'select')->assertSee('Escuela B')
        ->call('confirm')->assertSee('Conexión con Meta guardada')->assertSee('Ir a Formularios publicitarios');

    $connection = MetaConnection::query()->sole();
    expect($connection->only(['token', 'token_type', 'meta_user_id', 'connected_by']))
        ->toBe(['token' => 'USER_TOKEN_1', 'token_type' => 'USER', 'meta_user_id' => '10001112223334', 'connected_by' => $admin->id])
        ->and($connection->scopes)->toContain('leads_retrieval')
        ->and($connection->effectiveStatus())->toBe('active');
    $page = MetaLeadPage::query()->sole();
    expect($page->only(['page_id', 'page_token', 'selected', 'receiving_enabled', 'access_status']))
        ->toBe(['page_id' => 'PAGE_B', 'page_token' => 'PAGE_TOKEN_B', 'selected' => false, 'receiving_enabled' => false, 'access_status' => 'unchecked']);

    $panel = Livewire::actingAs($admin)->test(LeadForms::class)
        ->assertSee('Conectada')->assertSee('Escuela B')
        ->call('selectPage', $page->id, true)->assertSee('Usada para formularios')
        ->call('checkAccess', $page->id)->assertSee('Acceso verificado')->assertSee('Lectura de contactos autorizada por Meta');

    $form = MetaLeadForm::query()->sole(); // traído en la misma comprobación
    expect($form->only(['form_id', 'meta_lead_page_id', 'is_active']))->toBe(['form_id' => 'FORM_B1', 'meta_lead_page_id' => $page->id, 'is_active' => false]);

    $panel->call('chooseForm', $form->id, true)
        ->call('setDestination', $form->id, (string) $program->id)
        ->call('setAdvisor', $form->id, (string) $bot->id)
        ->call('setReceiving', $page->id, true)->assertSee('Recibiendo contactos');

    // Ningún token llega al navegador.
    expect($panel->html())->not->toContain('PAGE_TOKEN_B')->not->toContain('USER_TOKEN_1');

    // Entra un contacto por el formulario de la segunda empresa.
    mloMeta(['form_leads' => [['data' => [['id' => 'LEAD_B1']]]]]);
    Artisan::call('social:meta-leads-poll');

    $lead = Lead::query()->sole();
    expect($lead->program_id)->toBe($program->id)->and($lead->bot_id)->toBe($bot->id)
        ->and(Contact::query()->sole()->email)->toBe('marta@example.test')
        ->and(MetaLeadReceipt::query()->sole()->status)->toBe('processed')
        ->and(SocialChannel::query()->count())->toBe(0); // no se creó ningún canal

    // Nada cruzó a la primera empresa.
    app(CurrentInstitution::class)->set($mca->id);
    expect(Lead::query()->count())->toBe(0)->and(MetaLeadReceipt::query()->count())->toBe(0)
        ->and(MetaLeadPage::query()->sole()->page_id)->toBe('PAGE_MCA');
});

it('reconectar sustituye la autorización solo si la nueva funciona y nunca toca la credencial de Messenger', function () {
    [$inst, $admin, $program] = mloCompany('Escuela B');
    $messenger = SocialChannel::factory()->create(['provider' => 'messenger', 'external_id' => 'PAGE_B', 'credentials' => ['token' => 'MESSENGER_TOKEN_OK']]);

    mloMeta();
    mloConnect($admin, $inst);
    $page = MetaLeadPage::query()->sole();
    app(MetaLeadPageService::class)->select($page, true);
    MetaLeadForm::query()->create(['meta_lead_page_id' => $page->id, 'form_id' => 'FORM_B1', 'name' => 'Diplomas', 'program_id' => $program->id, 'destination' => 'program', 'is_active' => true]);

    // 1) Meta rechaza el código: nada cambia.
    mloMeta(['exchange' => [['error' => ['message' => 'Invalid verification code format.', 'code' => 100]], 400]]);
    mloConnect($admin, $inst, 'CODE_BAD')->assertSee('Meta rechazó la conexión');
    expect(MetaConnection::query()->sole()->token)->toBe('USER_TOKEN_1');

    // 2) La autorización no devuelve las Páginas: nada cambia.
    mloMeta(['exchange' => [['access_token' => 'USER_TOKEN_2']], 'accounts' => [['error' => ['message' => '(#10) Permission denied', 'code' => 10]], 403]]);
    mloConnect($admin, $inst)->assertSee('Meta no devolvió las Páginas autorizadas');
    expect(MetaConnection::query()->sole()->token)->toBe('USER_TOKEN_1')
        ->and(MetaLeadPage::query()->sole()->page_token)->toBe('PAGE_TOKEN_B');

    // 3) Reconexión correcta: nuevas credenciales, misma configuración.
    mloMeta(['exchange' => [['access_token' => 'USER_TOKEN_3']]], 'PAGE_B', 'PAGE_TOKEN_B2');
    mloConnect($admin, $inst)->assertSet('step', 'select')->assertSet('errorMessage', '');
    $page = MetaLeadPage::query()->sole();
    expect(MetaConnection::query()->sole()->token)->toBe('USER_TOKEN_3')
        ->and($page->page_token)->toBe('PAGE_TOKEN_B2')
        ->and($page->selected)->toBeTrue()
        ->and(MetaLeadForm::query()->sole()->program_id)->toBe($program->id);

    // La credencial de Messenger nunca se tocó.
    expect($messenger->fresh()->credentials['token'])->toBe('MESSENGER_TOKEN_OK')
        ->and(SocialChannel::query()->count())->toBe(1);
});

it('el 403 real «Requires pages_manage_ads permission» es gestión de la plataforma: el cliente solo es informado y la recepción no se activa', function () {
    [$inst, $admin] = mloCompany('MCA School');
    mloMeta();
    mloConnect($admin, $inst);
    $page = MetaLeadPage::query()->sole();
    app(MetaLeadPageService::class)->select($page, true);

    mloMeta(['forms' => [MLO_403_PAGES_MANAGE_ADS, 403]]);
    $client = Livewire::actingAs($admin)->test(LeadForms::class)
        ->call('checkAccess', $page->id)
        ->assertSee('Acceso con problemas')
        ->assertSee('Lo resuelve la plataforma del CRM')
        ->assertSee('Pendiente de la plataforma del CRM')
        ->assertDontSee('Lo resuelve tu empresa')
        ->assertDontSee('pages_manage_ads')
        ->assertDontSee('Gestión de la plataforma');

    $issue = MetaLeadPage::query()->sole()->access_result['issues'][0];
    expect($issue)->toMatchArray(['area' => 'app', 'who' => 'platform', 'permissions' => ['pages_manage_ads'], 'http_status' => 403, 'code' => 200])
        ->and(MetaLeadPage::query()->sole()->access_status)->toBe('failed');

    $client->call('setReceiving', $page->id, true)->assertSee('Antes de activar la recepción');
    expect(MetaLeadPage::query()->sole()->receiving_enabled)->toBeFalse();

    // El OPERADOR de la plataforma ve la gestión central, sin promesas de que el código lo conceda.
    $operator = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin', 'is_super_admin' => true]);
    Livewire::actingAs($operator)->test(LeadForms::class)
        ->assertSee('Gestión de la plataforma')
        ->assertSee('pages_manage_ads')
        ->assertSee('El código no puede conceder permisos de Meta')
        ->assertSee('HTTP 403');
});

it('un acceso que debe conceder la empresa se distingue del permiso de la aplicación', function () {
    [$inst, $admin] = mloCompany('Escuela B');
    mloMeta();
    mloConnect($admin, $inst);
    $page = MetaLeadPage::query()->sole();
    app(MetaLeadPageService::class)->select($page, true);

    // Leads Access Manager: el negocio no da acceso a los contactos.
    mloMeta([
        'lead_access' => [['has_lead_access' => ['app_has_leads_permission' => true, 'user_has_leads_permission' => false, 'enabled_lead_access_manager' => true]]],
        'form_leads' => [['error' => ['message' => '(#200) The user does not have lead access permission for this page (Leads Access Manager)', 'code' => 200]], 403],
    ]);
    Livewire::actingAs($admin)->test(LeadForms::class)->call('checkAccess', $page->id)
        ->assertSee('Lo resuelve tu empresa')->assertSee('Acceso a clientes potenciales')
        ->assertDontSee('Lo resuelve la plataforma del CRM');
    expect(MetaLeadPage::query()->sole()->access_result['issues'][0])->toMatchArray(['area' => 'business', 'who' => 'company']);

    // Página no autorizada para esta conexión.
    $missing = [['error' => ['message' => "Unsupported get request. Object with ID 'PAGE_B' does not exist, cannot be loaded due to missing permissions", 'code' => 100, 'error_subcode' => 33]], 400];
    mloMeta(['page' => $missing, 'forms' => $missing]);
    Livewire::actingAs($admin)->test(LeadForms::class)->call('checkAccess', $page->id)
        ->assertSee('Tu empresa debe dar acceso a esta Página');
    expect(MetaLeadPage::query()->sole()->access_result['issues'][0])->toMatchArray(['area' => 'page', 'who' => 'company']);
});

it('aislamiento entre empresas: Páginas, formularios, programas y contactos no se cruzan', function () {
    [$instA, $adminA, $programA] = mloCompany('MCA School');
    mloMeta([], 'PAGE_SHARED', 'TOKEN_A');
    mloConnect($adminA, $instA);
    $pageA = MetaLeadPage::query()->sole();
    app(MetaLeadPageService::class)->select($pageA, true);
    $formA = MetaLeadForm::query()->create(['meta_lead_page_id' => $pageA->id, 'form_id' => 'FORM_A', 'name' => 'Formulario de A']);
    MetaLeadReceipt::query()->create(['leadgen_id' => 'L_A', 'form_id' => 'FORM_A', 'page_id' => 'PAGE_SHARED', 'status' => 'processed', 'attribution' => ['form' => 'Formulario de A']]);

    [$instB, $adminB, $programB] = mloCompany('Escuela B');
    mloMeta([], 'PAGE_SHARED', 'TOKEN_B'); // la misma Página también la administra alguien de B
    mloConnect($adminB, $instB);
    $pageB = MetaLeadPage::query()->sole();

    // Una Página solo envía contactos a UNA empresa.
    Livewire::actingAs($adminB)->test(LeadForms::class)->call('selectPage', $pageB->id, true)
        ->assertSee('Esta Página la usa otra empresa en el CRM');
    expect($pageB->fresh()->selected)->toBeFalse();

    // B no ve ni toca lo de A.
    $panelB = Livewire::actingAs($adminB)->test(LeadForms::class)->assertDontSee('Formulario de A');
    expect(fn () => $panelB->call('selectPage', $pageA->id, true))->toThrow(ModelNotFoundException::class)
        ->and(fn () => Livewire::actingAs($adminB)->test(LeadForms::class)->call('setDestination', $formA->id, (string) $programB->id))->toThrow(ModelNotFoundException::class);

    // A no puede asignar un programa de B.
    app(CurrentInstitution::class)->set($instA->id);
    Livewire::actingAs($adminA)->test(LeadForms::class)->call('setDestination', $formA->id, (string) $programB->id);
    expect($formA->fresh()->program_id)->toBeNull();
    Livewire::actingAs($adminA)->test(LeadForms::class)->call('setDestination', $formA->id, (string) $programA->id);
    expect($formA->fresh()->program_id)->toBe($programA->id);

    // Cada empresa ve solo sus conexiones y Páginas.
    expect(MetaConnection::query()->sole()->token)->toBe('USER_TOKEN_1')
        ->and(MetaLeadPage::query()->sole()->page_token)->toBe('TOKEN_A');
    app(CurrentInstitution::class)->set($instB->id);
    expect(MetaLeadPage::query()->sole()->page_token)->toBe('TOKEN_B')->and(MetaLeadReceipt::query()->count())->toBe(0);
});

it('una conexión caducada pide reconectar y deja de recibir; la revisión diaria detecta las revocadas', function () {
    [$inst, $admin, $program] = mloCompany('Escuela B');
    mloMeta();
    mloConnect($admin, $inst);
    $page = MetaLeadPage::query()->sole();
    $page->forceFill(['selected' => true, 'access_status' => 'verified', 'receiving_enabled' => true])->save();
    MetaLeadForm::query()->create(['meta_lead_page_id' => $page->id, 'form_id' => 'FORM_B1', 'name' => 'Diplomas', 'program_id' => $program->id, 'destination' => 'program', 'is_active' => true, 'receiving_since' => now()]);

    MetaConnection::query()->sole()->forceFill(['expires_at' => now()->subDay()])->save();
    expect(MetaLeadPage::query()->with('metaConnection')->sole()->receiving())->toBeFalse();
    Livewire::actingAs($admin)->test(LeadForms::class)
        ->assertSee('Caducada')->assertSee('Reconectar Meta')->assertSee('En pausa por un problema de acceso');

    Http::fake();
    Artisan::call('social:meta-leads-poll');
    Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/leads'));

    // Revocada en Meta: la revisión diaria la marca como no válida.
    MetaConnection::query()->sole()->forceFill(['expires_at' => now()->addDays(30)])->save();
    mloMeta(['debug' => [['data' => ['is_valid' => false, 'error' => ['message' => 'Error validating access token: The user has not authorized application APP_META_1.']]]]]);
    Artisan::call('social:meta-connections-check');
    expect(MetaConnection::query()->sole()->effectiveStatus())->toBe('invalid');
    Livewire::actingAs($admin)->test(LeadForms::class)->assertSee('No válida')->assertSee('vuelve a autorizar');
});

it('desconectar borra las credenciales y para la recepción, pero conserva la configuración', function () {
    [$inst, $admin, $program] = mloCompany('Escuela B');
    mloMeta();
    mloConnect($admin, $inst);
    $page = MetaLeadPage::query()->sole();
    $page->forceFill(['selected' => true, 'access_status' => 'verified', 'receiving_enabled' => true])->save();
    MetaLeadForm::query()->create(['meta_lead_page_id' => $page->id, 'form_id' => 'FORM_B1', 'name' => 'Diplomas', 'program_id' => $program->id, 'destination' => 'program', 'is_active' => true]);

    Livewire::actingAs($admin)->test(LeadForms::class)->call('disconnect')
        ->assertSee('Sin conexión')->assertSee('Conectar Meta');

    $page = MetaLeadPage::query()->sole();
    expect(MetaConnection::query()->sole()->token)->toBe('')
        ->and($page->page_token)->toBe('')
        ->and($page->receiving_enabled)->toBeFalse()
        ->and($page->selected)->toBeTrue()
        ->and(MetaLeadForm::query()->sole()->program_id)->toBe($program->id);
});
