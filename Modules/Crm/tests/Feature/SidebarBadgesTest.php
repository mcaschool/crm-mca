<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Livewire\Contacts\Index as ContactsIndex;
use Modules\Crm\Livewire\Leads\Index as LeadsIndex;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\CrmModuleRead;
use Modules\Crm\Models\Lead;
use Modules\Crm\Services\SidebarBadges;
use Modules\Institutions\Models\Institution;

/**
 * Badges de "nuevos" del menú lateral (Leads/Contactos): watermark POR USUARIO en
 * crm_module_reads, mismo patrón que el badge de la Bandeja social. "Nuevo" =
 * created_at > last_seen_at; entrar al módulo marca visto y el contador cae.
 */

/**
 * @return array{0: Institution, 1: User}
 */
function sbCtx(): array
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);
    $user = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin']);

    return [$institution, $user];
}

function sbBadges(): SidebarBadges
{
    return app(SidebarBadges::class);
}

it('sin registros nuevos no hay contadores (y lo histórico previo no cuenta como nuevo)', function () {
    [, $user] = sbCtx();
    // Histórico ANTERIOR al estreno de la feature: la línea base perezosa lo deja visto.
    Lead::factory()->count(3)->create();
    Contact::factory()->count(2)->create();

    expect(sbBadges()->counts($user))->toBe(['leads' => 0, 'contacts' => 0]);
});

it('un lead nuevo tras la línea base pone el contador de Leads en 1', function () {
    [, $user] = sbCtx();
    sbBadges()->counts($user); // fija la línea base ahora

    $this->travel(1)->minutes();
    Lead::factory()->create();

    expect(sbBadges()->counts($user)['leads'])->toBe(1);
});

it('varios leads nuevos suman la cantidad correcta', function () {
    [, $user] = sbCtx();
    sbBadges()->counts($user);

    $this->travel(1)->minutes();
    Lead::factory()->count(4)->create();

    expect(sbBadges()->counts($user)['leads'])->toBe(4);
});

it('entrar al módulo Leads marca visto: el contador cae a 0 y se avisa al menú', function () {
    [, $user] = sbCtx();
    sbBadges()->counts($user);
    $this->travel(1)->minutes();
    Lead::factory()->count(5)->create();
    expect(sbBadges()->counts($user)['leads'])->toBe(5);

    $this->travel(1)->minutes();
    Livewire::actingAs($user)->test(LeadsIndex::class)
        ->assertDispatched('crm-badges-updated', leads: 0);

    expect(sbBadges()->counts($user)['leads'])->toBe(0);
});

it('un contacto nuevo pone el contador de Contactos y entrar al módulo lo limpia', function () {
    [, $user] = sbCtx();
    sbBadges()->counts($user);
    $this->travel(1)->minutes();
    Contact::factory()->count(2)->create();
    expect(sbBadges()->counts($user)['contacts'])->toBe(2);

    $this->travel(1)->minutes();
    Livewire::actingAs($user)->test(ContactsIndex::class)
        ->assertDispatched('crm-badges-updated', contacts: 0);

    expect(sbBadges()->counts($user)['contacts'])->toBe(0);
});

it('Leads y Contactos cuentan a la vez y marcar uno NO toca el otro', function () {
    [, $user] = sbCtx();
    sbBadges()->counts($user);

    $this->travel(1)->minutes();
    Lead::factory()->count(2)->create(); // cada Lead crea también su Contact
    expect(sbBadges()->counts($user))->toBe(['leads' => 2, 'contacts' => 2]);

    $this->travel(1)->minutes();
    sbBadges()->markSeen($user, 'leads');

    expect(sbBadges()->counts($user))->toBe(['leads' => 0, 'contacts' => 2]);
});

it('la marca de visto PERSISTE (sobrevive al refresco: se relee de BD)', function () {
    [, $user] = sbCtx();
    sbBadges()->counts($user);
    $this->travel(1)->minutes();
    Lead::factory()->create();
    $this->travel(1)->minutes();
    sbBadges()->markSeen($user, 'leads');

    // Fila real en la tabla de tracking + instancia NUEVA del servicio = mismo estado.
    expect(CrmModuleRead::query()->where('user_id', $user->id)->where('module', 'leads')->exists())->toBeTrue();
    expect((new SidebarBadges(app(CurrentInstitution::class)))->counts($user)['leads'])->toBe(0);
});

it('la lectura es POR USUARIO: que A marque visto no altera el contador de B', function () {
    [$institution, $userA] = sbCtx();
    $userB = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admissions']);
    sbBadges()->counts($userA);
    sbBadges()->counts($userB);

    $this->travel(1)->minutes();
    Lead::factory()->create();
    expect(sbBadges()->counts($userA)['leads'])->toBe(1);
    expect(sbBadges()->counts($userB)['leads'])->toBe(1);

    $this->travel(1)->minutes();
    sbBadges()->markSeen($userA, 'leads');

    expect(sbBadges()->counts($userA)['leads'])->toBe(0);
    expect(sbBadges()->counts($userB)['leads'])->toBe(1); // B sigue con su pendiente
});

it('sin institución en contexto no consulta nada ni crea líneas base', function () {
    [, $user] = sbCtx();
    app(CurrentInstitution::class)->forget();

    expect(sbBadges()->counts($user))->toBe(['leads' => 0, 'contacts' => 0]);

    app(CurrentInstitution::class)->set($user->institution_id);
    expect(CrmModuleRead::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('sin permiso viewAny el contador de ese módulo no se calcula ni deja rastro', function () {
    [, $user] = sbCtx();
    // Hoy los tres roles del panel pasan viewAny; se fuerza la denegación para
    // probar el guard del servicio (roles futuros sin CRM).
    Gate::before(fn () => false);

    expect(sbBadges()->counts($user))->toBe(['leads' => 0, 'contacts' => 0]);
    expect(CrmModuleRead::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('el notifier de la topbar refresca los contadores en vivo (evento a todo el panel)', function () {
    [, $user] = sbCtx();
    sbBadges()->counts($user);
    $this->travel(1)->minutes();
    Lead::factory()->count(3)->create();

    Livewire::actingAs($user)->test(\Modules\Crm\Livewire\NewLeadNotifier::class)
        ->call('check')
        ->assertDispatched('crm-badges-updated', leads: 3);
});

// --- Marca de visto POR INSTITUCIÓN (clave única institution_id + user_id + module) ------

it('regresión: un super-admin que cambia de institución carga Leads sin error de clave duplicada', function () {
    config(['crm.multi_institution' => true]);
    $instA = Institution::factory()->create();
    $instB = Institution::factory()->create();
    $super = User::factory()->create(['institution_id' => $instA->id, 'role' => 'admin', 'is_super_admin' => true]);

    $this->actingAs($super)->get('/crm/leads')->assertOk();      // crea sus marcas en A
    $this->actingAs($super)->post('/institution/switch', ['institution_id' => $instB->id])->assertRedirect();
    $this->actingAs($super)->get('/crm/leads')->assertOk();      // y en B, sin 1062
    $this->actingAs($super)->get('/crm/contacts')->assertOk();

    $rows = app(CurrentInstitution::class)->runGlobally(
        fn () => CrmModuleRead::query()->where('user_id', $super->id)->where('module', 'leads')->pluck('institution_id')->sort()->values()->all()
    );
    expect($rows)->toBe([$instA->id, $instB->id]);
});

it('markSeen y lastSeenAt están aislados por institución para el mismo usuario', function () {
    [$instA, $user] = sbCtx();
    $instB = Institution::factory()->create();
    $ctx = app(CurrentInstitution::class);

    sbBadges()->counts($user);                       // línea base en A
    $ctx->set($instB->id);
    sbBadges()->counts($user);                       // línea base propia en B (antes: 1062)

    $this->travel(1)->minutes();
    Lead::factory()->count(2)->create();             // 2 leads nuevos en B
    $ctx->set($instA->id);
    Lead::factory()->count(3)->create();             // 3 leads nuevos en A

    expect(sbBadges()->counts($user)['leads'])->toBe(3);
    $ctx->set($instB->id);
    expect(sbBadges()->counts($user)['leads'])->toBe(2);

    // Marcar visto en B no toca la marca de A.
    $this->travel(1)->minutes();
    sbBadges()->markSeen($user, 'leads');
    expect(sbBadges()->counts($user)['leads'])->toBe(0);
    $ctx->set($instA->id);
    expect(sbBadges()->counts($user)['leads'])->toBe(3);
});

it('migración de la clave única: up permite la misma marca en dos instituciones; down se niega si hay conflicto y restaura la clave si no', function () {
    // DDL aislado en SQLite en memoria (el DDL en MySQL cerraría la transacción de la prueba).
    config(['database.connections.reads_mig' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $previous = config('database.default');
    \Illuminate\Support\Facades\DB::purge('reads_mig');
    config(['database.default' => 'reads_mig']);
    $db = fn () => \Illuminate\Support\Facades\DB::table('crm_module_reads');
    $row = fn (int $inst) => ['institution_id' => $inst, 'user_id' => 7, 'module' => 'leads', 'last_seen_at' => now()];

    try {
        \Illuminate\Support\Facades\Schema::create('crm_module_reads', function (\Illuminate\Database\Schema\Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('institution_id');
            $t->unsignedBigInteger('user_id');
            $t->string('module', 20);
            $t->timestamp('last_seen_at');
            $t->timestamps();
            $t->unique(['user_id', 'module']);
            $t->index(['institution_id', 'module']);
        });
        $db()->insert($row(1));
        expect(fn () => $db()->insert($row(2)))->toThrow(\Illuminate\Database\QueryException::class); // clave antigua

        $migration = require base_path('Modules/Crm/database/migrations/2026_09_30_120000_scope_crm_module_reads_unique_by_institution.php');
        $migration->up();

        $db()->insert($row(2));                                                                         // otra institución: permitido
        expect(fn () => $db()->insert($row(2)))->toThrow(\Illuminate\Database\QueryException::class);  // misma institución: no

        // down con filas que violarían la clave antigua: falla controlado y no cambia nada.
        $indexes = fn () => collect(\Illuminate\Support\Facades\Schema::getIndexes('crm_module_reads'))->pluck('name')->sort()->values()->all();
        $before = $indexes();
        expect(fn () => $migration->down())->toThrow(RuntimeException::class, '1 combinaciones usuario/módulo');
        expect($indexes())->toBe($before)->and($db()->count())->toBe(2);

        // Sin conflicto: restaura (user_id, module).
        $db()->where('institution_id', 2)->delete();
        $migration->down();
        expect($indexes())->toContain('crm_module_reads_user_id_module_unique')
            ->not->toContain('crm_module_reads_institution_user_module_unique')
            ->not->toContain('crm_module_reads_user_id_index');
        expect(fn () => $db()->insert($row(2)))->toThrow(\Illuminate\Database\QueryException::class);
    } finally {
        config(['database.default' => $previous]);
        \Illuminate\Support\Facades\DB::purge('reads_mig');
    }
});

it('el badge de la Bandeja social no se ve afectado por las marcas del CRM', function () {
    [, $user] = sbCtx();
    $channel = \Modules\Social\Models\SocialChannel::factory()->create(['provider' => 'whatsapp']);
    \Modules\Social\Models\SocialConversation::factory()->create([
        'social_channel_id' => $channel->id,
        'provider' => 'whatsapp',
        'unread_count' => 4,
    ]);

    sbBadges()->markSeen($user, 'leads');
    sbBadges()->markSeen($user, 'contacts');

    // El contador social (suma de unread_count) sigue intacto.
    expect((int) \Modules\Social\Models\SocialConversation::query()->sum('unread_count'))->toBe(4);
});
