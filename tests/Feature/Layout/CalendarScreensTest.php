<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Livewire\Agenda\Form;
use Modules\Crm\Livewire\Agenda\Index;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\AvailabilityRule;
use Modules\Crm\Models\MeetingProfile;
use Modules\Crm\Support\Agenda\CalendarGrid;
use Modules\Institutions\Models\Institution;

/*
| Calendario (modernización visual, etapa 5A): todas las vistas con el kit compartido, rejilla
| horaria con actividades simultáneas repartidas, agenda en tableta y móvil y fichas/formularios
| con el patrón aprobado. Solo presentación: mismas acciones wire: y mismas reglas.
| Martes 13/10/2026 en America/Bogota (UTC−5).
*/

const CAL_TZ = 'America/Bogota';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-13 07:00:00', 'UTC'));
});

function calAdmin(): User
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);
    $user = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin', 'status' => 'active']);
    config(['auth.two_factor.exempt_emails' => [mb_strtolower($user->email)]]);
    MeetingProfile::query()->create(['user_id' => $user->id, 'timezone' => CAL_TZ, 'default_medium' => 'google_meet', 'meet_url' => 'https://meet.google.com/abc-defg-hij']);

    return $user;
}

/** Actividad creada directamente (sin servicios: no envía avisos ni correos). */
function calActivity(User $u, string $local, int $minutes, array $attrs = []): Activity
{
    $start = Carbon::parse($local, CAL_TZ)->utc();

    return Activity::query()->create(array_merge([
        'assigned_to_user_id' => $u->id, 'created_by_user_id' => $u->id, 'title' => 'Llamada '.$local, 'type' => 'call', 'medium' => 'phone',
        'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes($minutes), 'timezone' => CAL_TZ, 'priority' => 'normal', 'status' => 'scheduled',
        'reminders' => [], 'uid' => (string) Str::uuid(),
    ], $attrs));
}

it('la rejilla reparte en carriles las actividades simultáneas y agrupa 3 o más', function () {
    $u = calAdmin();
    $a = calActivity($u, '2026-10-13 09:00', 45);
    $b = calActivity($u, '2026-10-13 09:15', 30);
    $c = calActivity($u, '2026-10-13 09:30', 30);
    $d = calActivity($u, '2026-10-13 11:00', 60);
    $e = calActivity($u, '2026-10-14 07:00', 30); // amplía la franja visible
    $f = calActivity($u, '2026-10-14 07:15', 30);

    $days = [Carbon::parse('2026-10-13', CAL_TZ), Carbon::parse('2026-10-14', CAL_TZ)];
    $byDay = collect([$a, $b, $c, $d, $e, $f])->groupBy(fn (Activity $x) => $x->starts_at->copy()->setTimezone(CAL_TZ)->toDateString());
    $grid = CalendarGrid::build($days, $byDay, CAL_TZ);

    expect($grid['start'])->toBe(7)->and($grid['end'])->toBe(18)->and($grid['hours'][0])->toBe('07:00')
        // 09:00–09:45, 09:15 y 09:30: tres carriles en un mismo grupo
        ->and([$grid['items'][$a->id]['l'], $grid['items'][$b->id]['l'], $grid['items'][$c->id]['l']])->toBe([0, 1, 2])
        ->and($grid['items'][$a->id]['n'])->toBe(3)
        ->and($grid['items'][$a->id]['t'])->toBe(120)->and($grid['items'][$a->id]['d'])->toBe(45)
        // 11:00 no coincide con nadie: ancho completo
        ->and($grid['items'][$d->id]['n'])->toBe(1)->and($grid['items'][$d->id]['size'])->toBe('md')
        // dos a la vez: dos carriles, sin tarjeta de grupo
        ->and($grid['items'][$f->id]['l'])->toBe(1)->and($grid['items'][$f->id]['n'])->toBe(2)
        ->and($grid['clusters']['2026-10-13'][0]['ids'])->toBe([$a->id, $b->id, $c->id])
        ->and($grid['clusters'])->not->toHaveKey('2026-10-14');
});

it('el Calendario usa el kit: cabecera, navegación, vistas, filtros plegables y leyenda de estados', function () {
    $u = calAdmin();
    calActivity($u, '2026-10-13 09:00', 30);

    $html = $this->actingAs($u)->get('/crm/agenda')->assertOk()->getContent();

    expect($html)->not->toContain('class="crm-page')->not->toContain('class="panel"')
        ->toContain('<div class="mca-pagehead ui-ph-stack">')->toContain('class="ui-card ui-cal"')
        ->toContain('class="ui-filters"')->toContain('class="ui-cal-legend"')
        ->toContain('aria-label="Periodo anterior"')->toContain('aria-label="Periodo siguiente"')
        ->toContain('wire:click="previous"')->toContain('wire:click="today"')->toContain('wire:click="next"')
        ->toContain("wire:click=\"setView('week')\" aria-pressed=\"true\"")
        ->toContain(route('crm.agenda.availability'))->toContain(route('crm.agenda.profile'))->toContain(route('crm.agenda.create'));
    foreach (['advisor', 'type', 'status', 'medium'] as $filter) {
        expect($html)->toContain('wire:model.live="'.$filter.'"');
    }
});

it('semana y día pintan la rejilla horaria con posiciones; la agenda queda en la misma marca', function () {
    $u = calAdmin();
    calActivity($u, '2026-10-13 09:00', 45);
    calActivity($u, '2026-10-13 09:15', 30);
    calActivity($u, '2026-10-13 09:30', 30);
    $this->actingAs($u);

    $week = Livewire::test(Index::class)->html();
    expect($week)->toContain('class="ui-cal-grid"')->toContain('--cols:7;--hours:10')
        ->toContain('class="ui-cal-hours"')->toContain('style="--t:60;--d:45;--l:0;--n:3"')
        ->toContain('class="ui-cal-cluster"')->toContain('3 actividades a la vez')
        ->toContain('09:00–09:45') // rango completo (título de la tarjeta)
        ->and(substr_count($week, 'class="ui-cal-day '))->toBe(7);

    $day = Livewire::test(Index::class, ['view' => 'day'])->html();
    expect($day)->toContain('ui-cal-grid ui-cal-grid--day')->toContain('--cols:1;')
        ->and(substr_count($day, 'class="ui-cal-day '))->toBe(1);
});

it('el mes no deja una fila huérfana y en móvil añade la agenda del mes', function () {
    $u = calAdmin();
    calActivity($u, '2026-10-27 09:00', 30);
    $this->actingAs($u);

    $html = Livewire::test(Index::class, ['view' => 'month'])->html();
    // octubre de 2026: del lunes 28/9 al domingo 1/11 = 5 semanas completas
    expect(preg_match_all('~class="ui-cal-cell[ "]~', $html))->toBe(35)
        ->and($html)->toContain('class="ui-cal-month-agenda"')->toContain('class="ui-cal-dots"')
        ->toContain("wire:click=\"goTo('2026-10-27')\"");
});

it('la lista y las vencidas usan la agenda con estado vacío del kit', function () {
    $u = calAdmin();
    $this->actingAs($u);

    Livewire::test(Index::class, ['view' => 'list'])
        ->assertSeeHtml('class="ui-empty"')->assertSee('No hay actividades en este periodo.');
    Livewire::test(Index::class, ['overdue' => '1'])
        ->assertSee('No hay actividades vencidas.')->assertSee('Solo actividades programadas cuya hora ya pasó.');
});

it('el formulario muestra los huecos libres del día (antes los ocultaba la variable $slots de Livewire)', function () {
    $u = calAdmin();
    foreach ([1, 2, 3, 4, 5] as $wd) {
        AvailabilityRule::query()->create(['user_id' => $u->id, 'weekday' => $wd, 'start_time' => '09:00:00', 'end_time' => '11:00:00']);
    }
    $this->actingAs($u);

    Livewire::test(Form::class)
        ->set('timezone', CAL_TZ)->set('date', '2026-10-14')
        ->assertSee('Huecos libres ese día')
        ->assertSeeHtml('class="ui-chip" wire:click="pickSlot(\'09:00\')"')
        ->call('pickSlot', '09:30')->assertSet('time', '09:30')
        ->assertSeeHtml('aria-pressed="true">09:30</button>');
});

it('el formulario usa campos del kit y muestra los errores junto a cada campo', function () {
    $u = calAdmin();
    $this->actingAs($u);

    Livewire::test(Form::class)
        ->set('title', '')->set('time', '')->call('save')
        ->assertHasErrors(['title', 'time'])
        ->assertSeeHtml('id="ag-title-error"')->assertSeeHtml('aria-describedby="ag-time-error"')
        ->assertSeeHtml('class="ui-input is-invalid"');
});

it('la ficha de la actividad usa el patrón de ficha y conserva todas sus acciones', function () {
    $u = calAdmin();
    $a = calActivity($u, '2026-10-14 10:00', 30, ['title' => 'Videollamada de orientación', 'type' => 'video_call', 'medium' => 'google_meet', 'meeting_link' => 'https://meet.google.com/abc-defg-hij', 'priority' => 'high']);

    $html = $this->actingAs($u)->get(route('crm.agenda.show', $a))->assertOk()->getContent();
    expect($html)->not->toContain('class="crm-page')
        ->toContain('class="ui-record ui-record--main-first"')->toContain('class="ui-card ui-record-h"')->toContain('class="ui-record-grid"')
        ->toContain('Prioridad alta')->toContain('Abrir Google Meet')->toContain('Invitados')
        ->toContain('wire:click="complete"')->toContain("wire:click=\"open('reschedule')\"")->toContain("wire:click=\"open('cancel')\"")
        ->toContain('wire:click="saveOutcome"')->toContain('wire:click="noShow"')->toContain('wire:submit="addManualGuest"')
        ->toContain(route('crm.agenda.edit', $a));
});

it('disponibilidad, perfil de reuniones y plantillas dejan la familia heredada', function () {
    $u = calAdmin();
    $this->actingAs($u);

    foreach (['/crm/agenda/availability' => 'Disponibilidad', '/crm/agenda/profile' => 'Perfil de reuniones', '/crm/plantillas-calendario' => 'Plantillas de Calendario'] as $url => $title) {
        $html = $this->get($url)->assertOk()->getContent();
        expect($html)->not->toContain('class="crm-page')->not->toContain('ag-form')
            ->toMatch('~<div class="mca-pagehead">.*?<h1>'.preg_quote($title, '~').'~s');
    }
    $avail = $this->get('/crm/agenda/availability')->getContent();
    expect($avail)->toContain('class="ui-weekrow"')->toContain('wire:click="addInterval(1)"')->toContain('wire:submit="addException"');
});
