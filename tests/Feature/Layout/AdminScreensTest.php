<?php

declare(strict_types=1);

use App\Models\User;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;

/*
| Cierre de la modernización visual (etapa 6): las pantallas administrativas que seguían con las
| familias heredadas (x-ui.styles / .mca-panel, x-ui.crm-styles / .crm-page, CSS propio kc-*,
| Tailwind de Breeze) usan solo la capa compartida x-ui.kit y la cabecera de página del sistema.
| Las pantallas de acceso secundarias comparten el diseño aprobado del login.
*/

function adminScreensUser(): User
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);
    $user = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin', 'status' => 'active']);
    config(['auth.two_factor.exempt_emails' => [mb_strtolower($user->email)]]);

    return $user;
}

it('las pantallas administrativas migradas cargan con el kit y sin familias heredadas', function () {
    $admin = adminScreensUser();
    $bot = Bot::factory()->create(['institution_id' => $admin->institution_id]);

    $screens = [
        '/ajustes', '/ajustes/remitentes', '/ajustes/plantillas', '/mis-plantillas', '/mi-perfil', '/profile',
        '/users/create', '/users/'.$admin->id.'/edit',
        '/social/canales', '/social/meta', '/social/plantillas', '/social/formularios',
        '/centro-conocimiento', '/centro-conocimiento/agentes', '/knowledge', '/advisor',
        '/advisors/create', '/advisors/'.$bot->id.'/edit',
        '/catalog/categories', '/catalog/create',
        '/integrations/ai_provider/configure', '/integrations/smtp/configure', '/ai-processes',
        '/integrations/mcp', '/integrations/videoconferencias', '/crm/pipeline',
    ];

    foreach ($screens as $url) {
        $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();

        expect(substr_count($html, '.ui-btn{--h:'))->toBe(1, "capa del kit duplicada o ausente: $url")
            ->and(str_contains($html, 'class="mca-pagehead'))->toBeTrue("sin cabecera de página: $url")
            ->and($html)->not->toContain('class="mca-panel')->not->toContain('class="crm-page')->not->toContain('class="kc')
            ->and(str_contains($html, '.mca-panel{'))->toBeFalse("carga x-ui.styles: $url")
            ->and(str_contains($html, '.crm-page{'))->toBeFalse("carga x-ui.crm-styles: $url");
    }
});

it('las pestañas de Ajustes marcan la sección actual de forma accesible', function () {
    $html = $this->actingAs(adminScreensUser())->get('/ajustes/remitentes')->assertOk()->getContent();

    expect($html)->toContain('role="tablist"')
        ->toMatch('~<a[^>]+href="[^"]*/ajustes/remitentes"[^>]*role="tab"[^>]*aria-selected="true"~')
        ->toMatch('~<a[^>]+href="[^"]*/ajustes"[^>]*role="tab"[^>]*aria-selected="false"~');
});

it('recuperar, restablecer y verificar en dos pasos comparten el diseño del login', function () {
    foreach (['/forgot-password', '/reset-password/token-de-prueba?email=persona%40ejemplo.test'] as $url) {
        $html = $this->get($url)->assertOk()->getContent();

        expect($html)->toContain('class="card"')->toContain('images/logo.png')->toContain('class="btn"')
            ->not->toContain('bg-gray-100')->not->toContain('figtree')
            ->not->toContain('Forgot your password? No problem.');
    }

    expect($this->get('/forgot-password')->getContent())
        ->toContain('Recuperar contraseña')->toContain('name="email"')->toContain('Volver al acceso');
    expect($this->get('/reset-password/token-de-prueba?email=persona%40ejemplo.test')->getContent())
        ->toContain('name="token" value="token-de-prueba"')->toContain('name="password_confirmation"');
});
