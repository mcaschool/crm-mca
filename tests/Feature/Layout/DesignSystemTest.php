<?php

declare(strict_types=1);

use App\Models\User;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Enums\LeadStatus;
use Modules\Crm\Models\Lead;
use Modules\Institutions\Models\Institution;

/*
| Sistema de diseño compartido (modernización visual, etapa 2): una sola capa de componentes
| `ui-` cargada por el layout, componentes Blade reutilizables y las tres pantallas prototipo
| (Leads, Inicio, Usuarios) construidas solo con ellos, sin las familias heredadas.
*/

function dsAdmin(): User
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);
    $user = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin', 'status' => 'active']);
    config(['auth.two_factor.exempt_emails' => [mb_strtolower($user->email)]]);

    return $user;
}

it('el layout carga la capa de componentes una sola vez y las pantallas migradas no usan las familias heredadas', function () {
    $admin = dsAdmin();
    Lead::factory()->create();

    foreach (['/crm/leads', '/users', '/dashboard', '/crm/contacts', '/audit', '/catalog', '/advisors', '/integrations'] as $url) {
        $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
        expect(substr_count($html, '.ui-btn{--h:'))->toBe(1, $url)
            ->and(substr_count($html, '--mca-text-3:'))->toBe(1, $url)
            ->and($html)->not->toContain('class="crm-page')
            ->and($html)->not->toContain('class="mca-panel');
    }
});

it('el estado del lead usa un único mapa de tonos con punto y su etiqueta', function () {
    $tones = ['new' => 'blue', 'contacted' => 'teal', 'qualified' => 'warning', 'enrolled' => 'success', 'discarded' => 'neutral'];

    foreach (LeadStatus::cases() as $status) {
        $html = (string) $this->blade('<x-ui.lead-status :status="$s" />', ['s' => $status]);
        expect($html)->toContain('ui-badge ui-tone--'.$tones[$status->value])
            ->toContain('class="dot"')
            ->toContain($status->label());
    }
});

it('los componentes base generan el marcado esperado', function () {
    // Botón: enlace con href, <button> sin él; sin texto pasa a ser cuadrado.
    expect((string) $this->blade('<x-ui.button variant="primary" icon="plus" href="/x">Crear</x-ui.button>'))
        ->toContain('<a href="/x"')->toContain('ui-btn ui-btn--primary');
    expect((string) $this->blade('<x-ui.button size="sm" icon="power" aria-label="Desactivar" />'))
        ->toContain('<button type="button"')->toContain('ui-btn--sm ui-btn--icon')->toContain('aria-label="Desactivar"');

    // Avatar: iniciales y tono estables por persona; foto si existe.
    $a = (string) $this->blade('<x-ui.avatar name="Ana María Pérez" seed="7" />');
    expect($a)->toContain('AP')->toBe((string) $this->blade('<x-ui.avatar name="Ana María Pérez" seed="7" />'));
    expect((string) $this->blade('<x-ui.avatar name="Ana" src="/f.jpg" />'))->toContain('<img src="/f.jpg"');

    // Selector de filtro y KPI enlazado.
    expect((string) $this->blade('<x-ui.select filter><option value="">Todos</option></x-ui.select>'))->toContain('ui-select ui-select--filter');
    expect((string) $this->blade('<x-ui.kpi label="Nuevos" :value="3" tone="blue" href="/l" />'))
        ->toMatch('~<a\s+href="/l"\s+class="ui-kpi"~')->toContain('ui-kpi-value')->toContain('ui-tone--blue');
});

it('el paginador muestra el rango y desactiva la página anterior en la primera', function () {
    $p = new Illuminate\Pagination\LengthAwarePaginator(range(1, 2), 5, 2, 1);
    $html = (string) $this->blade('<x-ui.pager :paginator="$p" />', ['p' => $p]);

    expect($html)->toContain('<b>1–2</b>')->toContain('<b>5</b>')->toContain('1 / 3')
        ->toMatch('~wire:click="previousPage"[^>]*disabled~')
        ->not->toMatch('~wire:click="nextPage"[^>]*disabled~');
    expect((string) $this->blade('<x-ui.pager :paginator="$p" />', ['p' => new Illuminate\Pagination\LengthAwarePaginator([1], 1, 25, 1)]))->toBe('');
});

it('Inicio: indicadores con enlace a su listado y el acento dorado solo en interés corporativo', function () {
    $html = $this->actingAs(dsAdmin())->get('/dashboard')->assertOk()->getContent();

    foreach (['new', 'overdue', 'today', 'next7', 'followup', 'enrolledMonth'] as $k) {
        expect($html)->toMatch('~<a\s+href="[^"]+"\s+class="ui-kpi"\s+data-kpi="'.$k.'"~');
    }
    expect(substr_count($html, 'class="ui-kpi ui-kpi--accent"'))->toBe(1)
        ->and($html)->toContain(e(route('crm.leads.index', ['corporate' => 1])));
});

it('Usuarios: acción principal destacada y acciones por fila con nombre accesible', function () {
    $admin = dsAdmin();
    User::factory()->create(['institution_id' => $admin->institution_id, 'role' => 'admissions', 'status' => 'inactive']);

    $html = $this->actingAs($admin)->get('/users')->assertOk()->getContent();
    expect($html)->toContain('ui-btn ui-btn--primary')->toContain('Crear usuario')
        ->toMatch('~aria-label="Activar ~')                // el inactivo puede activarse
        ->toContain('class="t-gap"')                       // el propio usuario no tiene botón de desactivar
        ->toContain('ui-badge ui-tone--neutral');          // estado «Inactivo»
});
