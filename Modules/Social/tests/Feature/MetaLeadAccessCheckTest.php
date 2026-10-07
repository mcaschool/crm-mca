<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use Modules\Social\Livewire\LeadForms;
use Modules\Social\Models\MetaConnection;
use Modules\Social\Models\MetaLeadPage;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Services\MetaLeadAccessCheck;

/**
 * «Comprobar acceso» a los formularios publicitarios con la conexión ACTUAL de la Página: distingue
 * Página / acceso del negocio a los leads / permiso de la aplicación, con la respuesta exacta de
 * Meta saneada (sin credenciales ni datos personales). Sin Meta real (Http::fake con las formas de
 * respuesta documentadas por Meta).
 */
const MLC_TOKEN = 'EAAmlcSecretPageToken0123456789';

function mlcPage(): SocialChannel
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);

    return SocialChannel::factory()->create([
        'provider' => 'messenger', 'external_id' => 'page_77', 'display_name' => 'MCA School',
        'credentials' => ['token' => MLC_TOKEN],
    ]);
}

/**
 * Meta simulado por operación: page | token_owner | lead_access | forms | form_leads | test_create | test_read | test_delete.
 *
 * @param  array<string, array{0: array<string, mixed>, 1?: int}>  $map
 */
function mlcFake(array $map): void
{
    Http::fake(function (HttpRequest $r) use ($map) {
        $path = (string) parse_url($r->url(), PHP_URL_PATH);
        $fields = (string) ($r->data()['fields'] ?? '');
        $key = match (true) {
            $r->method() === 'POST' && str_ends_with($path, '/test_leads') => 'test_create',
            $r->method() === 'DELETE' => 'test_delete',
            str_ends_with($path, '/leadgen_forms') => 'forms',
            str_ends_with($path, '/leads') => 'form_leads',
            str_ends_with($path, '/debug_token') => 'token_owner',
            str_ends_with($path, '/page_77') && str_starts_with($fields, 'has_lead_access') => 'lead_access',
            str_ends_with($path, '/page_77') => 'page',
            default => 'test_read',
        };
        [$body, $status] = ($map[$key] ?? [[]]) + [1 => 200];

        return Http::response($body, $status);
    });
}

/** debug_token: la conexión la respalda un usuario de sistema. */
function mlcOwner(): array
{
    return [['data' => ['app_id' => '1317892160409334', 'type' => 'PAGE', 'is_valid' => true, 'user_id' => '10009876543210', 'profile_id' => 'page_77', 'scopes' => ['pages_show_list', 'pages_messaging']]]];
}

function mlcError(int $code, string $message, int $subcode = 0): array
{
    return [['error' => array_filter(['message' => $message, 'type' => 'OAuthException', 'code' => $code, 'error_subcode' => $subcode ?: null, 'fbtrace_id' => 'Atrace1'])], 400];
}

it('con todos los accesos correctos lista formularios y lee los leads del formulario', function () {
    $page = mlcPage();
    mlcFake([
        'page' => [['id' => 'page_77', 'name' => 'MCA School']],
        'token_owner' => mlcOwner(),
        'lead_access' => [['has_lead_access' => ['app_has_leads_permission' => true, 'user_has_leads_permission' => true, 'can_access_lead' => true, 'enabled_lead_access_manager' => false], 'id' => 'page_77']],
        'forms' => [['data' => [['id' => 'f1', 'name' => 'Diplomas', 'status' => 'ACTIVE', 'leads_count' => 4], ['id' => 'f2', 'name' => 'Maestrías', 'status' => 'ACTIVE', 'leads_count' => 0]]]],
        'form_leads' => [['data' => [['id' => 'l1', 'created_time' => '2026-10-06T10:00:00+0000']]]],
    ]);

    $result = app(MetaLeadAccessCheck::class)->run($page);

    expect(collect($result['steps'])->pluck('step')->all())->toBe(['page', 'token_owner', 'lead_access', 'forms', 'form_leads'])
        ->and(collect($result['verdict'])->pluck('status')->all())->toBe(['ok', 'ok', 'ok']);
    // Solo lectura: ninguna escritura en Meta.
    Http::assertNotSent(fn (HttpRequest $r) => $r->method() !== 'GET');
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'f1/leads'));
    // has_lead_access con el usuario que respalda la conexión; en la salida, enmascarado.
    Http::assertSent(fn (HttpRequest $r) => ($r->data()['fields'] ?? '') === 'has_lead_access.user_id(10009876543210)');
    expect(json_encode($result, JSON_UNESCAPED_UNICODE))->not->toContain('10009876543210')->toContain('user_id(…3210)');
});

it('distingue el permiso de la aplicación cuando Meta exige leads_retrieval / pages_manage_ads', function () {
    $page = mlcPage();
    mlcFake([
        'page' => [['id' => 'page_77', 'name' => 'MCA School']],
        'token_owner' => mlcOwner(),
        'lead_access' => mlcError(200, '(#200) Requires leads_retrieval permission to manage the object'),
        'forms' => mlcError(200, '(#200) Requires pages_manage_ads permission to manage the object'),
    ]);

    $result = app(MetaLeadAccessCheck::class)->run($page);

    expect($result['verdict']['page']['status'])->toBe('ok')
        ->and($result['verdict']['app']['status'])->toBe('fail')
        ->and($result['verdict']['app']['detail'])->toContain('leads_retrieval')
        ->and($result['verdict']['business']['status'])->toBe('unknown');
    // La respuesta exacta de Meta se conserva (código, tipo, traza).
    expect($result['steps'][3]['response']['error'])->toMatchArray(['code' => 200, 'type' => 'OAuthException', 'fbtrace_id' => 'Atrace1']);
});

it('distingue el acceso del negocio a los leads (Leads Access Manager)', function () {
    $page = mlcPage();
    mlcFake([
        'page' => [['id' => 'page_77', 'name' => 'MCA School']],
        'token_owner' => mlcOwner(),
        'lead_access' => [['has_lead_access' => [
            'app_has_leads_permission' => true, 'user_has_leads_permission' => false, 'can_access_lead' => false,
            'enabled_lead_access_manager' => true, 'failure_reason' => 'User does not have lead access permission', 'failure_resolution' => 'Grant lead access in Leads Access Manager',
        ]]],
        'forms' => [['data' => []]],
    ]);

    $result = app(MetaLeadAccessCheck::class)->run($page);

    expect($result['verdict']['app']['status'])->toBe('ok')
        ->and($result['verdict']['business'])->toBe(['status' => 'fail', 'detail' => 'Grant lead access in Leads Access Manager']);
});

it('distingue la autorización de la Página (conexión caducada o Página no autorizada)', function () {
    $page = mlcPage();
    mlcFake([
        'page' => mlcError(190, 'Error validating access token: Session has expired'),
        'token_owner' => mlcError(190, 'Error validating access token: Session has expired'),
        'forms' => mlcError(100, 'Unsupported get request. Object with ID \'page_77\' does not exist, cannot be loaded due to missing permissions', 33),
    ]);

    $result = app(MetaLeadAccessCheck::class)->run($page);

    expect($result['verdict']['page']['status'])->toBe('fail')
        ->and(collect($result['steps'])->pluck('area')->all())->toBe(['token', 'token', 'not_checkable', 'page']);
});

it('el lead de PRUEBA se crea, se lee enmascarado y se borra; nunca muestra la credencial ni datos personales', function () {
    $page = mlcPage();
    mlcFake([
        'page' => [['id' => 'page_77', 'name' => 'MCA School']],
        'lead_access' => [['has_lead_access' => ['app_has_leads_permission' => true, 'user_has_leads_permission' => true]]],
        'forms' => [['data' => [['id' => 'f1', 'name' => 'Diplomas']]]],
        'test_create' => [['id' => 'tl_1']],
        'test_read' => [['id' => 'tl_1', 'form_id' => 'f1', 'field_data' => [
            ['name' => 'email', 'values' => ['test@fb.com']], ['name' => 'full_name', 'values' => ['Test Lead Dummy']], ['name' => 'phone_number', 'values' => ['+15555550100']],
        ], 'access_token' => MLC_TOKEN]],
        'test_delete' => [['success' => true]],
    ]);

    Artisan::call('social:meta-lead-check', ['--test-lead' => true]);
    $output = Artisan::output();

    expect($output)->toContain('POST /v26.0/f1/test_leads')
        ->toContain('GET /v26.0/tl_1?fields=')
        ->toContain('DELETE /v26.0/tl_1')
        ->toContain('"name": "email"')
        ->not->toContain(MLC_TOKEN)
        ->not->toContain('test@fb.com')
        ->not->toContain('+15555550100')
        ->not->toContain('Test Lead Dummy');
    Http::assertSentCount(6);
});

it('«Comprobar acceso» en el panel usa lenguaje llano, dice quién lo resuelve y no activa nada', function () {
    $channel = mlcPage();
    $admin = User::factory()->create(['institution_id' => $channel->institution_id, 'role' => 'admin']);
    $connection = MetaConnection::query()->create(['token' => 'USER_TOKEN', 'status' => 'active', 'connected_at' => now()]);
    $page = MetaLeadPage::query()->create(['meta_connection_id' => $connection->id, 'page_id' => 'page_77', 'name' => 'MCA School', 'page_token' => MLC_TOKEN, 'selected' => true]);
    mlcFake([
        'page' => [['id' => 'page_77', 'name' => 'MCA School']],
        'forms' => [['data' => [['id' => 'f1', 'name' => 'Diplomas']]]],
        'form_leads' => mlcError(200, '(#200) Requires leads_retrieval permission to manage the object'),
    ]);

    $c = Livewire::actingAs($admin)->test(LeadForms::class)
        ->assertSee('Conectar Meta')->assertSee('Elegir Página y formularios')->assertSee('Asignar destino')
        ->call('checkAccess', $page->id)
        ->assertSee('Acceso con problemas')
        ->assertSee('Lo resuelve la plataforma del CRM')
        ->assertSee('(1 formulario)');

    $block = Illuminate\Support\Str::between($c->html(), 'data-testid="page-issues-'.$page->id.'"', 'data-testid="step-receiving"');
    foreach (['token', 'webhook', 'endpoint', 'payload', 'n8n', 'leads_retrieval', 'http'] as $word) {
        expect(strtolower($block))->not->toContain($word);
    }
    expect($page->fresh()->receiving_enabled)->toBeFalse();
    Http::assertNotSent(fn (HttpRequest $r) => $r->method() !== 'GET');
});

it('#100 «The parameter user_id is required» no se clasifica como falta de permiso: el veredicto sale de las lecturas reales', function () {
    $page = mlcPage();
    mlcFake([
        'page' => [['id' => 'page_77', 'name' => 'MCA School']],
        'token_owner' => mlcOwner(),
        'lead_access' => mlcError(100, '(#100) The parameter user_id is required'),
        'forms' => [['data' => [['id' => 'f1', 'name' => 'Diplomas', 'status' => 'ACTIVE']]]],
        'form_leads' => [['data' => [['id' => 'l1', 'created_time' => '2026-10-06T10:00:00+0000']]]],
    ]);

    expect(app(MetaLeadAccessCheck::class)->classify(['code' => 100, 'message' => '(#100) The parameter user_id is required']))->toBe('request');

    $result = app(MetaLeadAccessCheck::class)->run($page);

    expect(collect($result['steps'])->firstWhere('step', 'lead_access')['area'])->toBe('request')
        ->and(collect($result['verdict'])->pluck('status')->all())->toBe(['ok', 'ok', 'ok'])
        ->and(app(MetaLeadAccessCheck::class)->realCheckFailed($result))->toBeFalse();
    expect(Artisan::call('social:meta-lead-check'))->toBe(0);
    expect(Artisan::output())->toContain('Resultado: sin fallos');
});

it('sin user_id en la conexión, has_lead_access es «no comprobable» y el veredicto usa /leadgen_forms y /{form}/leads', function () {
    $page = mlcPage();
    mlcFake([
        'page' => [['id' => 'page_77', 'name' => 'MCA School']],
        'token_owner' => [['data' => ['type' => 'PAGE', 'is_valid' => true, 'profile_id' => 'page_77']]], // sin user_id
        'forms' => [['data' => [['id' => 'f1', 'name' => 'Diplomas']]]],
        'form_leads' => mlcError(200, '(#200) Requires leads_retrieval permission to manage the object'),
    ]);

    $result = app(MetaLeadAccessCheck::class)->run($page);
    $access = collect($result['steps'])->firstWhere('step', 'lead_access');

    expect($access['ok'])->toBeNull()->and($access['area'])->toBe('not_checkable')
        ->and($result['verdict']['page']['status'])->toBe('ok')
        ->and($result['verdict']['app']['status'])->toBe('fail')
        ->and($result['verdict']['business']['status'])->toBe('unknown');
    Http::assertNotSent(fn (HttpRequest $r) => str_starts_with((string) ($r->data()['fields'] ?? ''), 'has_lead_access'));

    // La comprobación real falló → el comando devuelve código de error.
    expect(Artisan::call('social:meta-lead-check'))->toBe(1);
    expect(Artisan::output())->toContain('[not_checkable]')->toContain('Resultado: la comprobación real FALLÓ');
});

it('el comando devuelve error si la Página no responde aunque lo demás no sea comprobable', function () {
    mlcPage();
    mlcFake([
        'page' => mlcError(190, 'Error validating access token: Session has expired'),
        'token_owner' => mlcError(190, 'Error validating access token: Session has expired'),
        'forms' => mlcError(190, 'Error validating access token: Session has expired'),
    ]);

    expect(Artisan::call('social:meta-lead-check'))->toBe(1);
    expect(Artisan::output())->not->toContain(MLC_TOKEN);
});
