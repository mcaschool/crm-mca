<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Modules\Audit\Models\AuditLog;
use Modules\Catalog\Models\Program;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Enums\LeadStatus;
use Modules\Crm\Livewire\Leads\Show;
use Modules\Crm\Livewire\Pipeline\Board;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Event;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\MeetingProfile;
use Modules\Crm\Services\Agenda\ActivityService;
use Modules\Crm\Services\LeadService;
use Modules\Crm\Services\LeadTransitionException;
use Modules\Crm\Services\PipelineService;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;

/*
| Pipeline Comercial (Fase 4). Lunes 19/10/2026 07:00 UTC.
*/

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-19 07:00:00', 'UTC'));
    Mail::fake();
});

/** @return array{inst:Institution,admin:User,ana:User,beto:User,bot:Bot} */
function pl(): array
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);
    $mk = fn (string $role, string $name, ?string $dept = null) => User::factory()->create([
        'institution_id' => $inst->id, 'role' => $role, 'department' => $dept, 'status' => 'active', 'name' => $name,
    ]);

    return [
        'inst' => $inst,
        'admin' => $mk('admin', 'Admin MCA'),
        'ana' => $mk('admissions', 'Ana Asesora', 'Admisiones'),
        'beto' => $mk('admissions', 'Beto Asesor', 'Admisiones'),
        'bot' => Bot::factory()->create(['assistant_name' => 'Celia', 'type' => 'ia']),
    ];
}

/** @param  array<string,mixed>  $attrs */
function plLead(array $s, string $first, array $attrs = []): Lead
{
    $contact = Contact::factory()->create(['first_name' => $first, 'last_name' => 'Prueba', 'email' => strtolower($first).'@example.org']);

    return Lead::factory()->create(array_merge(['contact_id' => $contact->id, 'bot_id' => $s['bot']->id], $attrs));
}

function plSvc(): PipelineService
{
    return app(PipelineService::class);
}

// 1 · Consultas, filtros y contadores -------------------------------------------------------------

it('cuenta el total filtrado por etapa y filtra por alcance, asesor, programa, producto, interés, período y búsqueda', function () {
    $s = pl();
    $prog = Program::factory()->create(['name_es' => 'Analítica de Datos']);
    plLead($s, 'Lucia', ['status' => 'new', 'assigned_to_user_id' => $s['ana']->id, 'program_id' => $prog->id, 'interest_level' => 'high']);
    plLead($s, 'Mateo', ['status' => 'new', 'assigned_to_user_id' => $s['beto']->id, 'product_type' => 'incompany']);
    plLead($s, 'Nora', ['status' => 'contacted', 'assigned_to_user_id' => $s['ana']->id]);
    plLead($s, 'Olga', ['status' => 'enrolled']);
    $old = plLead($s, 'Pablo', ['status' => 'qualified', 'assigned_to_user_id' => $s['ana']->id]);
    $old->forceFill(['created_at' => now()->subDays(60)])->save();

    expect(plSvc()->counts($s['admin'], ['scope' => 'team']))->toBe(['new' => 2, 'contacted' => 1, 'qualified' => 1, 'enrolled' => 1, 'discarded' => 0])
        ->and(array_sum(plSvc()->counts($s['ana'], ['scope' => 'mine'])))->toBe(3)
        ->and(plSvc()->counts($s['admin'], ['scope' => 'team', 'advisor' => $s['beto']->id])['new'])->toBe(1)
        ->and(array_sum(plSvc()->counts($s['admin'], ['program' => $prog->id])))->toBe(1)
        ->and(array_sum(plSvc()->counts($s['admin'], ['product' => 'incompany'])))->toBe(1)
        ->and(array_sum(plSvc()->counts($s['admin'], ['interest' => 'high'])))->toBe(1)
        ->and(array_sum(plSvc()->counts($s['admin'], ['period' => '30'])))->toBe(4)
        ->and(array_sum(plSvc()->counts($s['admin'], ['search' => 'nora@example'])))->toBe(1);

    // Los filtros de la interfaz actualizan tarjetas Y contadores.
    Livewire::actingAs($s['admin'])->test(Board::class)
        ->assertSet('scope', 'team')->assertSee('Lucia')->assertSee('Mateo')->assertSee('Analítica de Datos')
        ->set('advisor', (string) $s['beto']->id)->assertSee('Mateo')->assertDontSee('Lucia')
        ->set('advisor', '')->set('search', 'Lucia')->assertSee('Lucia')->assertDontSee('Mateo')
        ->call('clearFilters')->set('interest', 'high')->assertSee('Lucia')->assertDontSee('Nora');
    // Un asesor empieza por su cartera.
    Livewire::actingAs($s['beto'])->test(Board::class)->assertSet('scope', 'mine')->assertSee('Mateo')->assertDontSee('Lucia')
        ->set('scope', 'team')->assertSee('Lucia');
});

it('muestra asesor humano y bot de origen por separado, sin confundirlos', function () {
    $s = pl();
    plLead($s, 'Lucia', ['assigned_to_user_id' => $s['ana']->id]);
    plLead($s, 'Mateo');
    Livewire::actingAs($s['admin'])->test(Board::class)
        ->assertSeeInOrder(['Lucia', 'Asesor', 'Ana Asesora', 'Origen', 'Celia'])
        ->assertSeeInOrder(['Mateo', 'Asesor', 'Sin asignar', 'Origen', 'Celia']);
});

// 2 · Movimiento de tarjetas ---------------------------------------------------------------------

it('mueve una tarjeta con la regla central y actualiza contadores y columnas', function () {
    $s = pl();
    $lead = plLead($s, 'Lucia', ['status' => 'new']);
    Livewire::withQueryParams(['scope' => 'team'])->actingAs($s['ana'])->test(Board::class)
        ->call('move', $lead->id, 'new', 'qualified')
        ->assertSet('noticeError', false)->assertSee('pasó a En seguimiento');
    expect($lead->fresh()->status)->toBe(LeadStatus::Qualified)
        ->and(plSvc()->counts($s['ana'], [])['qualified'])->toBe(1)
        ->and(plSvc()->column($s['ana'], [], LeadStatus::Qualified, 20)->pluck('id')->all())->toBe([$lead->id]);
});

// 3 · Transiciones permitidas y prohibidas -------------------------------------------------------

it('permite las transiciones vigentes y rechaza salir de Matriculado o valores no válidos', function () {
    $s = pl();
    $a = plLead($s, 'Lucia', ['status' => 'discarded']);
    $b = plLead($s, 'Mateo', ['status' => 'enrolled']);
    $board = Livewire::actingAs($s['admin'])->test(Board::class);

    $board->call('move', $a->id, 'discarded', 'contacted')->assertSet('noticeError', false);
    expect($a->fresh()->status)->toBe(LeadStatus::Contacted);
    $board->call('move', $a->id, 'contacted', 'enrolled')->assertSet('noticeError', false);
    $board->call('move', $a->id, 'enrolled', 'qualified')->assertSet('noticeError', true)->assertSee('matriculado');
    $board->call('move', $b->id, 'enrolled', 'new')->assertSet('noticeError', true);
    $board->call('move', $b->id, 'enrolled', 'perdido')->assertSet('noticeError', true);
    expect($a->fresh()->status)->toBe(LeadStatus::Enrolled)->and($b->fresh()->status)->toBe(LeadStatus::Enrolled);
    // La tarjeta matriculada no es arrastrable ni ofrece cambio de etapa.
    $board->assertDontSee('Cambiar etapa de Mateo Prueba');
});

// 4 · Permisos por institución y asesor ----------------------------------------------------------

it('aplica LeadPolicy: solo lectura y Soporte (fuera de sus referidos) no mueven; otra institución no existe', function () {
    $s = pl();
    $lead = plLead($s, 'Lucia', ['status' => 'new']);
    $mkt = User::factory()->create(['institution_id' => $s['inst']->id, 'role' => 'marketing', 'status' => 'active']);
    $sup = User::factory()->create(['institution_id' => $s['inst']->id, 'role' => 'admissions', 'department' => 'Soporte', 'status' => 'active']);

    Livewire::actingAs($mkt)->test(Board::class)->set('scope', 'team')->assertSee('Lucia')
        ->assertDontSee('Cambiar etapa de Lucia Prueba')
        ->call('move', $lead->id, 'new', 'contacted')->assertSet('noticeError', true)->assertSee('No tienes permiso');
    Livewire::actingAs($sup)->test(Board::class)->set('scope', 'team')->call('move', $lead->id, 'new', 'contacted')->assertSet('noticeError', true);
    expect($lead->fresh()->status)->toBe(LeadStatus::New);
    $lead->forceFill(['assigned_to_user_id' => $sup->id])->save();
    Livewire::actingAs($sup)->test(Board::class)->call('move', $lead->id, 'new', 'contacted')->assertSet('noticeError', false);
    expect($lead->fresh()->status)->toBe(LeadStatus::Contacted);

    // Otra institución: su lead ni se ve ni se puede mover.
    $t = pl();
    $foreign = plLead($t, 'Zoe', ['status' => 'new']);
    app(CurrentInstitution::class)->set($s['inst']->id);
    Livewire::actingAs($s['admin'])->test(Board::class)->assertDontSee('Zoe')
        ->call('move', $foreign->id, 'new', 'contacted')->assertSee('ya no está disponible');
    app(CurrentInstitution::class)->set($t['inst']->id);
    expect($foreign->fresh()->status)->toBe(LeadStatus::New);
});

// 5 · Concurrencia ------------------------------------------------------------------------------

it('no sobrescribe un cambio concurrente: la etapa esperada debe coincidir (Pipeline y ficha)', function () {
    $s = pl();
    $lead = plLead($s, 'Lucia', ['status' => 'new']);
    $board = Livewire::actingAs($s['ana'])->test(Board::class)->set('scope', 'team');
    $show = Livewire::actingAs($s['ana'])->test(Show::class, ['lead' => $lead]);

    // Otro usuario lo pasa a Contactado mientras ambas pantallas muestran «Nuevo».
    app(LeadService::class)->changeStatus($lead->fresh(), LeadStatus::Contacted, LeadStatus::New);
    $board->call('move', $lead->id, 'new', 'discarded')->assertSet('noticeError', true)->assertSee('Otra persona cambió la etapa');
    $show->call('changeStatus', 'qualified')->assertHasErrors('status')->assertSet('status', 'contacted');
    expect($lead->fresh()->status)->toBe(LeadStatus::Contacted);

    expect(fn () => app(LeadService::class)->changeStatus($lead->fresh(), LeadStatus::Qualified, LeadStatus::New))
        ->toThrow(LeadTransitionException::class);
});

// 6 · Auditoría ---------------------------------------------------------------------------------

it('registra etapa anterior, nueva, usuario y fecha (evento append-only + auditoría), igual desde la ficha', function () {
    $s = pl();
    $lead = plLead($s, 'Lucia', ['status' => 'new']);
    $this->actingAs($s['ana']);
    Livewire::actingAs($s['ana'])->test(Board::class)->call('move', $lead->id, 'new', 'contacted');
    Livewire::actingAs($s['ana'])->test(Show::class, ['lead' => $lead->fresh()])->call('changeStatus', 'qualified');
    Livewire::actingAs($s['ana'])->test(Show::class, ['lead' => $lead->fresh()])->call('changeStatus', 'qualified'); // sin cambio: sin rastro

    $events = Event::query()->where('event_type', 'lead_status_changed')->orderBy('id')->get();
    expect($events)->toHaveCount(2)
        ->and($events[0]->event_data)->toMatchArray(['lead_id' => $lead->id, 'from' => 'new', 'to' => 'contacted', 'by_user_id' => $s['ana']->id])
        ->and($events[1]->event_data)->toMatchArray(['from' => 'contacted', 'to' => 'qualified'])
        ->and($events[0]->contact_id)->toBe($lead->contact_id)
        ->and($events[0]->label())->toBe('Etapa comercial cambiada');
    $logs = AuditLog::query()->where('action', 'lead.status_changed')->orderBy('id')->get();
    expect($logs)->toHaveCount(2)->and($logs[0]->user_id)->toBe($s['ana']->id)->and($logs[0]->created_at)->not->toBeNull()
        ->and($logs[0]->changes)->toMatchArray(['from' => 'new', 'to' => 'contacted']);
});

// 7 · Integración con Calendario ----------------------------------------------------------------

it('muestra próxima actividad, vencidas y «Unirse», enlaza al Calendario y nunca cambia la etapa sola', function () {
    $s = pl();
    MeetingProfile::query()->create(['user_id' => $s['ana']->id, 'timezone' => 'UTC', 'meet_url' => 'https://meet.google.com/abc-defg-hij']);
    $withNext = plLead($s, 'Lucia', ['status' => 'contacted', 'assigned_to_user_id' => $s['ana']->id]);
    $overdue = plLead($s, 'Mateo', ['status' => 'qualified', 'assigned_to_user_id' => $s['ana']->id]);
    $none = plLead($s, 'Nora', ['status' => 'new']);
    $svc = app(ActivityService::class);
    $mk = fn (Lead $l, string $start, string $medium = 'google_meet') => $svc->create([
        'title' => 'Orientación', 'type' => 'video_call', 'medium' => $medium, 'lead_id' => $l->id,
        'starts_at' => Carbon::parse($start, 'UTC'), 'ends_at' => Carbon::parse($start, 'UTC')->addMinutes(30),
        'timezone' => 'UTC', 'assigned_to_user_id' => $s['ana']->id,
    ], $s['ana'], true);
    $next = $mk($withNext, '2026-10-19 15:00');
    $past = $mk($overdue, '2026-10-19 08:00');
    $this->travelTo(Carbon::parse('2026-10-19 10:00:00', 'UTC')); // la de Mateo ya terminó sin cerrarse

    Livewire::withQueryParams(['scope' => 'team'])->actingAs($s['ana'])->test(Board::class)
        ->assertSeeInOrder(['Lucia', 'Actividad próxima', 'Videollamada · 19/10 15:00', 'Unirse'])
        ->assertSee('https://meet.google.com/abc-defg-hij')
        ->assertSeeInOrder(['Mateo', 'Seguimiento vencido'])
        ->assertSeeInOrder(['Nora', 'Sin próxima acción programada'])
        ->assertSee(route('crm.agenda.create', ['lead' => $none->id]), false)
        ->assertSee(route('crm.agenda.show', $next), false);

    // Completar o cancelar en el Calendario no cambia la etapa; el tablero refleja el cambio al actualizar.
    $svc->complete($past, $s['ana'], 'Hecho');
    $svc->cancel($next, $s['ana']);
    expect($overdue->fresh()->status)->toBe(LeadStatus::Qualified)->and($withNext->fresh()->status)->toBe(LeadStatus::Contacted);
    Livewire::withQueryParams(['scope' => 'team'])->actingAs($s['ana'])->test(Board::class)
        ->assertDontSee('class="pl-flag overdue"', false)->assertDontSee('title="Unirse"', false)
        ->assertSeeInOrder(['Lucia', 'Sin próxima acción programada']);

    // Visibilidad del Calendario: Beto no ve las actividades de Ana en las tarjetas.
    $mk($withNext, '2026-10-20 15:00');
    Livewire::withQueryParams(['scope' => 'team'])->actingAs($s['beto'])->test(Board::class)->assertDontSee('Videollamada · 20/10');
    Livewire::actingAs($s['admin'])->test(Board::class)->assertSee('Videollamada · 20/10');
});

// 8 · Interfaz ----------------------------------------------------------------------------------

it('pinta cinco columnas, pestañas móviles, arrastre solo en tarjetas movibles y el acceso en CAPTACIÓN', function () {
    $s = pl();
    plLead($s, 'Lucia', ['status' => 'new']);
    plLead($s, 'Olga', ['status' => 'enrolled']);
    $html = Livewire::actingAs($s['admin'])->test(Board::class)->html();
    expect(substr_count($html, 'class="pl-col"'))->toBe(5)
        ->and(substr_count($html, 'role="tab"'))->toBe(5)
        ->and(substr_count($html, 'draggable="true" @dragstart'))->toBe(1);
    foreach (['Nuevo', 'Contactado', 'En seguimiento', 'Matriculado', 'Descartado'] as $label) {
        expect($html)->toContain($label);
    }

    config(['auth.two_factor.exempt_emails' => [mb_strtolower($s['admin']->email)]]);
    $this->actingAs($s['admin'])->get(route('crm.pipeline'))->assertOk()->assertSee('Pipeline Comercial');
    $this->actingAs($s['admin'])->get(route('crm.leads.index'))->assertSee(route('crm.pipeline'), false);
    $this->get(route('crm.pipeline').'?lang=en')->assertSee('Sales Pipeline')->assertSee('My leads');
});

// 9 · Rendimiento -------------------------------------------------------------------------------

it('con miles de leads carga por columna, cuenta el total y no crece en consultas', function () {
    $s = pl();
    $contacts = [];
    $now = now()->format('Y-m-d H:i:s');
    for ($i = 0; $i < 3000; $i++) {
        $contacts[] = ['institution_id' => $s['inst']->id, 'first_name' => 'C'.$i, 'last_name' => 'X', 'email' => "c$i@example.org", 'preferred_language' => 'es', 'created_at' => $now, 'updated_at' => $now];
    }
    foreach (array_chunk($contacts, 500) as $chunk) {
        DB::table('contacts')->insert($chunk);
    }
    $ids = DB::table('contacts')->where('institution_id', $s['inst']->id)->pluck('id');
    $statuses = ['new', 'contacted', 'qualified', 'enrolled', 'discarded'];
    $leads = [];
    foreach ($ids as $n => $cid) {
        $leads[] = ['institution_id' => $s['inst']->id, 'contact_id' => $cid, 'bot_id' => $s['bot']->id, 'product_type' => 'microcredential',
            'status' => $statuses[$n % 5], 'interest_level' => 'low', 'assigned_to_user_id' => $n % 2 ? $s['ana']->id : null, 'created_at' => $now, 'updated_at' => $now];
    }
    foreach (array_chunk($leads, 500) as $chunk) {
        DB::table('leads')->insert($chunk);
    }

    DB::enableQueryLog();
    $board = Livewire::actingAs($s['admin'])->test(Board::class);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();
    $html = $board->html();
    expect($queries)->toBeLessThan(40)
        ->and(substr_count($html, 'class="pl-card"'))->toBe(100)
        ->and($html)->toContain('Cargar más (580 restantes)');

    $board->call('loadMore', 'new');
    expect(substr_count($board->html(), 'class="pl-card"'))->toBe(120)
        ->and(plSvc()->counts($s['admin'], [])['new'])->toBe(600);
});
