<?php

declare(strict_types=1);

use App\Models\User;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Lead;
use Modules\Institutions\Models\Institution;

/*
| Layout del panel (modernización visual, etapa 1): sin carril derecho reservado, migas de pan
| desde un único mapa de rutas, cabecera de página y listados con patrón responsive real
| (desplazamiento propio en escritorio y tarjetas en móvil), sin ocultar columnas ni acciones.
*/

function layoutAdmin(): User
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);
    $user = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin', 'status' => 'active']);
    config(['auth.two_factor.exempt_emails' => [mb_strtolower($user->email)]]);

    return $user;
}

/** @return array<int,array{label:string, current:bool, href:string|null}> */
function crumbsOf(string $html): array
{
    preg_match('~<nav class="mca-crumbs"[^>]*>(.*?)</nav>~s', $html, $m);
    preg_match_all('~<li>\s*(?:<a href="([^"]+)">([^<]+)</a>|<span aria-current="page">([^<]+)</span>)\s*</li>~', $m[1] ?? '', $items, PREG_SET_ORDER);

    return array_map(fn ($i) => [
        'label' => trim(html_entity_decode($i[2] !== '' ? $i[2] : ($i[3] ?? ''))),
        'current' => ($i[3] ?? '') !== '',
        'href' => $i[1] !== '' ? $i[1] : null,
    ], $items);
}

it('no reserva el carril derecho y todas las páginas usan el margen común', function () {
    $html = $this->actingAs(layoutAdmin())->get('/crm/leads')->assertOk()->getContent();

    expect($html)->not->toContain('mca-rail')->not->toContain('rail-ph')
        ->and($html)->toMatch('~<div [^>]*class="mca-page"~')
        ->and($html)->not->toContain('padding:22px 26px 34px');
});

it('muestra migas de pan de sección y página, con la página actual marcada', function () {
    $admin = layoutAdmin();
    $lead = Lead::factory()->create();

    $cases = [
        '/crm/leads' => ['Leads'],
        '/crm/leads/create' => ['Leads', 'Nuevo lead'],
        '/crm/leads/'.$lead->id => ['Leads', 'Ficha del lead'],
        '/crm/agenda' => ['Calendario'],
        '/crm/agenda/create' => ['Calendario', 'Nueva actividad'],
        '/crm/pipeline' => ['Pipeline Comercial'],
        '/social/bandeja' => ['Bandeja social'],
        '/centro-conocimiento' => ['Centro de Conocimiento'],
        '/integrations/mcp' => ['IA / MCP'],
        '/users/create' => ['Usuarios', 'Nuevo usuario'],
        '/audit' => ['Auditoría'],
    ];
    foreach ($cases as $url => $expected) {
        $crumbs = crumbsOf($this->actingAs($admin)->get($url)->assertOk()->getContent());
        expect(array_column($crumbs, 'label'))->toBe($expected, $url)
            ->and(end($crumbs)['current'])->toBeTrue();
        if (count($crumbs) === 2) {
            expect($crumbs[0]['href'])->not->toBeNull();
        }
    }
    // La sección enlaza a su listado.
    expect(crumbsOf($this->actingAs($admin)->get('/crm/leads/create')->getContent())[0]['href'])->toBe(route('crm.leads.index'));
});

it('da cabecera de página a los listados que no la tenían', function () {
    $admin = layoutAdmin();
    foreach (['/crm/leads' => 'Leads', '/crm/contacts' => 'Contactos', '/users' => 'Usuarios', '/catalog' => 'Catálogo', '/advisors' => 'Asesores Inteligentes', '/audit' => 'Auditoría de seguridad', '/integrations' => 'Integraciones', '/crm/pipeline' => 'Pipeline Comercial'] as $url => $title) {
        $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
        expect($html)->toMatch('~<div class="mca-pagehead">.*?<h1>'.preg_quote($title, '~').'~s');
    }
});

it('Leads, Usuarios y Auditoría usan el patrón responsive sin quitar columnas ni acciones', function () {
    $admin = layoutAdmin();
    Lead::factory()->create();

    $leads = $this->actingAs($admin)->get('/crm/leads')->getContent();
    expect($leads)->toContain('<div class="mca-table-scroll">')->toContain('<table class="ui-table">')
        ->toContain('class="ui-person-mail"');
    foreach (['Asesor', 'Estado', 'Emparejador', 'Empresa', 'Fecha'] as $label) {
        expect($leads)->toContain('data-label="'.$label.'"');
    }

    User::factory()->create(['institution_id' => $admin->institution_id, 'role' => 'admissions', 'status' => 'active']);
    $users = $this->actingAs($admin)->get('/users')->getContent();
    expect($users)->toContain('class="ui-table"')->toContain('class="rt-actions"')
        ->toContain('class="ui-person-mail"')->toContain('data-label="Rol"')->toContain('data-label="Estado"')
        ->toMatch('~aria-label="(Desactivar|Activar) ~');

    $this->actingAs($admin);
    app(\Modules\Audit\Services\AuditService::class)->log('user.updated', $admin);
    $audit = $this->get('/audit')->getContent();
    expect($audit)->toContain('class="ui-table ui-table--stack-aside"')->toContain('class="rt-primary"')
        ->toContain('data-label="Fecha / hora"')->toContain('data-label="Acción"')->toContain('data-label="Entidad"')->toContain('data-label="IP"');
});
