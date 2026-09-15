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
