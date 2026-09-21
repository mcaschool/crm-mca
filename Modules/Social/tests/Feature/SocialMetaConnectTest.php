<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use Modules\Social\Livewire\MetaConnect;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Services\MetaConnectionService;

/**
 * «Conectar Meta» (Meta Connection Core) SIN Meta real: state de un solo uso, intercambio de
 * código server-side y descubrimiento de Páginas + Instagram vía Http::fake. Verifica además
 * que los Page Access Tokens nunca se exponen a la UI y que esta etapa NO crea canales.
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

/**
 * @return array{0: Institution, 1: User}
 */
function mcCtx(string $role = 'admin'): array
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);
    $user = User::factory()->create(['institution_id' => $institution->id, 'role' => $role]);

    return [$institution, $user];
}

function mcService(): MetaConnectionService
{
    return app(MetaConnectionService::class);
}

/**
 * @param  list<array<string, mixed>>  $pages
 */
function mcFakeAccounts(array $pages): void
{
    Http::fake([
        'graph.facebook.com/v26.0/oauth/access_token' => Http::response(['access_token' => 'USERTOK'], 200),
        'graph.facebook.com/v26.0/me/accounts*' => Http::response(['data' => $pages], 200),
    ]);
}

// ============================================================ state anti-CSRF

it('emite y consume el state una sola vez (replay protection)', function () {
    [$institution, $user] = mcCtx();

    $state = mcService()->issueState($user->id, $institution->id);

    expect(mcService()->consumeState($user->id, $state))->toBe($institution->id)
        ->and(mcService()->consumeState($user->id, $state))->toBeNull()
        ->and(mcService()->consumeState($user->id, ''))->toBeNull();
});

// ============================================================ descubrimiento

it('descubre y normaliza Página + Instagram, e intercambia el código server-side', function () {
    mcCtx();
    mcFakeAccounts([[
        'id' => 'PAGE_1',
        'name' => 'MCA Business & Postgraduate School',
        'access_token' => 'PAGETOK_1',
        'instagram_business_account' => ['id' => 'IG_1', 'username' => 'mcaschoolofbusiness'],
    ]]);

    $result = mcService()->discoverAssets('CODE_1');

    expect($result->pages)->toHaveCount(1);
    $page = $result->pages[0];
    expect($page->pageId)->toBe('PAGE_1')
        ->and($page->name)->toBe('MCA Business & Postgraduate School')
        ->and($page->pageAccessToken)->toBe('PAGETOK_1')
        ->and($page->hasInstagram())->toBeTrue()
        ->and($page->instagramId)->toBe('IG_1')
        ->and($page->instagramUsername)->toBe('mcaschoolofbusiness');

    // El code viaja en el CUERPO (no en la URL) y sin client_secret en la URL; /me/accounts
    // usa el user token como Bearer.
    Http::assertSent(function ($request) {
        if (str_contains($request->url(), 'oauth/access_token')) {
            return $request['code'] === 'CODE_1'
                && ! str_contains($request->url(), 'CODE_1')
                && ! str_contains($request->url(), 'client_secret');
        }

        return true;
    });
    Http::assertSent(fn ($request) => ! str_contains($request->url(), '/me/accounts')
        || $request->hasHeader('Authorization', 'Bearer USERTOK'));
});

it('la proyección para la UI nunca incluye el Page Access Token', function () {
    mcCtx();
    mcFakeAccounts([[
        'id' => 'PAGE_1',
        'name' => 'MCA',
        'access_token' => 'PAGETOK_SECRET',
        'instagram_business_account' => ['id' => 'IG_1', 'username' => 'mca'],
    ]]);

    $display = mcService()->discoverAssets('CODE_1')->forDisplay();

    expect($display[0])->toBe([
        'page_id' => 'PAGE_1',
        'name' => 'MCA',
        'has_messenger' => true,
        'has_instagram' => true,
        'instagram_username' => 'mca',
    ])->and(json_encode($display))->not->toContain('PAGETOK_SECRET');
});

it('marca has_instagram=false cuando la Página no tiene Instagram asociado', function () {
    mcCtx();
    mcFakeAccounts([['id' => 'PAGE_2', 'name' => 'Solo Facebook', 'access_token' => 'PT2']]);

    $result = mcService()->discoverAssets('CODE_1');

    expect($result->pages[0]->hasInstagram())->toBeFalse()
        ->and($result->forDisplay()[0]['has_instagram'])->toBeFalse()
        ->and($result->forDisplay()[0]['instagram_username'])->toBeNull();
});

it('devuelve vacío cuando la cuenta no administra Páginas', function () {
    mcCtx();
    mcFakeAccounts([]);

    expect(mcService()->discoverAssets('CODE_1')->isEmpty())->toBeTrue();
});

it('ignora filas sin id o sin token', function () {
    mcCtx();
    mcFakeAccounts([
        ['id' => '', 'name' => 'Sin id', 'access_token' => 'X'],
        ['id' => 'PAGE_OK', 'name' => 'Buena', 'access_token' => 'T'],
        ['id' => 'PAGE_NOTOK', 'name' => 'Sin token'],
    ]);

    $result = mcService()->discoverAssets('CODE_1');

    expect($result->pages)->toHaveCount(1)
        ->and($result->pages[0]->pageId)->toBe('PAGE_OK');
});

it('lanza un error amigable si Meta rechaza el intercambio de código', function () {
    mcCtx();
    Http::fake([
        'graph.facebook.com/v26.0/oauth/access_token' => Http::response(['error' => ['code' => 190]], 400),
    ]);

    expect(fn () => mcService()->discoverAssets('BAD_CODE'))
        ->toThrow(RuntimeException::class);
});

// ============================================================ estado de plataforma

it('la plataforma está configurada con app id + config id + secret', function () {
    expect(mcService()->isPlatformConfigured())->toBeTrue();
});

it('la plataforma NO está configurada si falta el config id global', function () {
    config(['social.meta.login_config_id' => null]);

    expect(mcService()->isPlatformConfigured())->toBeFalse();
});

// ============================================================ Livewire (UX)

it('un usuario sin permiso de integraciones no puede abrir Conectar Meta', function () {
    [, $user] = mcCtx('marketing');

    test()->actingAs($user)->get(route('social.meta'))->assertForbidden();
});

it('el descubrimiento lleva a la selección y NO crea ningún canal', function () {
    [$institution, $user] = mcCtx('admin');
    mcFakeAccounts([[
        'id' => 'PAGE_1',
        'name' => 'MCA Business & Postgraduate School',
        'access_token' => 'PAGETOK_1',
        'instagram_business_account' => ['id' => 'IG_1', 'username' => 'mcaschoolofbusiness'],
    ]]);
    $state = mcService()->issueState($user->id, $institution->id);

    $component = Livewire::actingAs($user)->test(MetaConnect::class)
        ->call('discover', $state, 'CODE_1')
        ->assertSet('step', 'select')
        ->assertSet('selectedPageId', 'PAGE_1')
        ->assertSee('MCA Business & Postgraduate School')
        ->assertSee('mcaschoolofbusiness')
        ->assertDontSee('PAGETOK_1')
        ->call('confirm')
        ->assertSet('step', 'done');

    expect($component->get('pages'))->toHaveCount(1)
        ->and(SocialChannel::query()->count())->toBe(0);
});

it('un state inválido no inicia el descubrimiento', function () {
    [, $user] = mcCtx('admin');

    Livewire::actingAs($user)->test(MetaConnect::class)
        ->call('discover', 'STATE_FALSO', 'CODE_1')
        ->assertSet('step', 'idle')
        ->assertSet('errorMessage', __('La conexión expiró o no es válida. Vuelve a iniciar el proceso.'));
});
