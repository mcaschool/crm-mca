<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\PanelBadges;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Livewire\Leads\Index as LeadsIndex;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Institutions\Models\Institution;
use Modules\Social\Livewire\Inbox;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Services\NormalizedMessage;
use Modules\Social\Services\SocialIngestService;

/*
| Indicadores del menú lateral en tiempo real: un único cálculo (PanelBadges) servido por
| GET /panel/indicadores y consultado por un único sincronizador desde cualquier pantalla. Se
| prueba con mensajes ingeridos como los del webhook de Meta (sin Meta real), leads y contactos
| creados por procesos/APIs, lecturas, duplicados, permisos y aislamiento por institución.
*/

/** @return array{0: Institution, 1: User, 2: SocialChannel} */
function pbCtx(string $role = 'admissions'): array
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);
    $user = User::factory()->create(['institution_id' => $institution->id, 'role' => $role, 'department' => 'Admisiones', 'status' => 'active']);
    config(['auth.two_factor.exempt_emails' => array_merge(config('auth.two_factor.exempt_emails', []), [mb_strtolower($user->email)])]);
    $channel = SocialChannel::factory()->create(['provider' => 'instagram', 'external_id' => 'IG_'.$institution->id, 'is_active' => true, 'credentials' => ['token' => 'T']]);

    return [$institution, $user, $channel];
}

function pbMessage(SocialChannel $ch, string $mid, string $direction = 'inbound', string $sender = 'contact', bool $history = false): NormalizedMessage
{
    return new NormalizedMessage(
        provider: 'instagram', channelExternalId: $ch->external_id, conversationExternalId: 'IGSID_1', contactName: 'Ana',
        contactExternalId: 'IGSID_1', contactAvatarUrl: 'https://cdn.example.test/a.jpg', messageExternalId: $mid, type: 'text', body: 'Hola',
        attachments: null, providerTimestamp: null, direction: $direction, senderType: $sender, fromHistory: $history,
    );
}

function pbGet(User $user): array
{
    return test()->actingAs($user)->getJson(route('panel.badges'))->assertOk()->json();
}

it('el endpoint solo devuelve números de los indicadores permitidos, sin caché ni datos personales', function () {
    [, $user] = pbCtx();
    $res = test()->actingAs($user)->getJson(route('panel.badges'))->assertOk();

    expect($res->json())->toBe(['leads' => 0, 'contacts' => 0, 'social' => 0]);
    expect($res->headers->get('Cache-Control'))->toContain('no-store');

    // Sin sesión no hay indicadores.
    auth()->logout();
    test()->getJson(route('panel.badges'))->assertUnauthorized();
});

it('un mensaje entrante sube el indicador Social; ecos, respuestas automáticas, historial y reintentos no', function () {
    [, $user, $ch] = pbCtx();
    $ingest = app(SocialIngestService::class);
    \Illuminate\Support\Facades\Http::fake();

    $ingest->ingest(pbMessage($ch, 'mid_1'));
    expect(pbGet($user)['social'])->toBe(1);

    $ingest->ingest(pbMessage($ch, 'mid_1'));                       // reintento del webhook
    $ingest->ingest(pbMessage($ch, 'mid_2', 'outbound', 'app'));     // eco de Meta / respuesta desde la app
    $ingest->ingest(pbMessage($ch, 'mid_3', 'outbound', 'bot'));     // respuesta del asesor inteligente
    $ingest->ingest(pbMessage($ch, 'mid_4', history: true));         // historial antiguo
    expect(pbGet($user)['social'])->toBe(1);

    $ingest->ingest(pbMessage($ch, 'mid_5'));
    expect(pbGet($user)['social'])->toBe(2);
});

it('leer la conversación en la Bandeja actualiza el indicador para todas las sesiones', function () {
    [$inst, $user, $ch] = pbCtx();
    $colleague = User::factory()->create(['institution_id' => $inst->id, 'role' => 'marketing', 'status' => 'active']);
    config(['auth.two_factor.exempt_emails' => [mb_strtolower($user->email), mb_strtolower($colleague->email)]]);
    app(SocialIngestService::class)->ingest(pbMessage($ch, 'mid_1'));
    app(SocialIngestService::class)->ingest(pbMessage($ch, 'mid_2'));
    expect(pbGet($user)['social'])->toBe(2)->and(pbGet($colleague)['social'])->toBe(2);

    $conv = SocialConversation::query()->sole();
    Livewire::actingAs($user)->test(Inbox::class)->call('select', $conv->id)->assertDispatched('social-unread-updated', total: 0);

    expect(pbGet($user)['social'])->toBe(0)->and(pbGet($colleague)['social'])->toBe(0);
});

it('un lead o contacto nuevo, desde cualquier origen, sube su indicador; una actualización o un duplicado no', function () {
    [, $user] = pbCtx();
    pbGet($user); // línea base del usuario: lo anterior ya está visto
    $this->travel(1)->minutes();

    // Altas como las de la API de leads, n8n o procesos automáticos (no pasan por la pantalla de Leads).
    $contact = Contact::factory()->create(['email' => 'nuevo@example.test']);
    $lead = Lead::factory()->create(['contact_id' => $contact->id, 'source' => 'api']);
    expect(pbGet($user))->toMatchArray(['leads' => 1, 'contacts' => 1]);

    // Actualizar el lead o volver a registrar el mismo contacto no cuenta como nuevo.
    $lead->forceFill(['status' => 'contacted'])->save();
    Contact::query()->firstOrCreate(['email' => 'nuevo@example.test'], ['first_name' => 'Duplicado']);
    expect(pbGet($user))->toMatchArray(['leads' => 1, 'contacts' => 1]);
});

it('entrar a Leads limpia solo ese indicador y solo para ese usuario', function () {
    [$inst, $user] = pbCtx();
    $other = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin', 'status' => 'active']);
    config(['auth.two_factor.exempt_emails' => [mb_strtolower($user->email), mb_strtolower($other->email)]]);
    pbGet($user);
    pbGet($other);
    $this->travel(1)->minutes();
    Lead::factory()->count(2)->create();
    expect(pbGet($user)['leads'])->toBe(2);

    Livewire::actingAs($user)->test(LeadsIndex::class);
    expect(pbGet($user)['leads'])->toBe(0)->and(pbGet($user)['contacts'])->toBe(2)
        ->and(pbGet($other)['leads'])->toBe(2); // la marca de lectura es por usuario
});

it('una institución nunca ve los indicadores de otra', function () {
    [, $userA, $chA] = pbCtx();
    pbGet($userA);
    [, $userB] = pbCtx();
    pbGet($userB);
    $this->travel(1)->minutes();

    app(CurrentInstitution::class)->set($userA->institution_id);
    Lead::factory()->count(3)->create();
    app(SocialIngestService::class)->ingest(pbMessage($chA, 'mid_1'));

    expect(pbGet($userA))->toMatchArray(['leads' => 3, 'social' => 1])
        ->and(pbGet($userB))->toBe(['leads' => 0, 'contacts' => 0, 'social' => 0]);
});

it('sin permiso, el indicador ni se calcula ni se devuelve (tampoco con una petición manual)', function () {
    [, $user] = pbCtx();
    $noRole = (clone $user)->forceFill(['role' => null]);
    expect(app(PanelBadges::class)->for($noRole))->toBe([]);

    Gate::before(fn () => false); // sin acceso al panel
    test()->actingAs($user)->getJson(route('panel.badges'))->assertForbidden();
});

it('el sincronizador único está en todas las pantallas del panel y sigue al servidor con pausas y reintentos', function () {
    [, $user] = pbCtx('admin');
    $html = test()->actingAs($user)->get(route('crm.agenda.index'))->assertOk()->getContent();

    expect(substr_count($html, 'window.__mcaPanelBadges'))->toBe(2) // una sola instancia (guarda + marca)
        ->and($html)->toMatch('~panel(\\\\)?/indicadores~')
        ->toContain("document.visibilityState !== 'visible'")   // en segundo plano no consulta
        ->toContain('visibilitychange')->toContain("addEventListener('online'")
        ->toContain('Math.min(MAX, wait * 2)')                    // espera creciente ante errores
        ->toContain('[401, 403, 419]')                            // sesión caducada: se detiene
        ->toContain("new BroadcastChannel('mca-panel-badges')")   // varias pestañas coherentes
        ->toContain("new CustomEvent('social-unread-updated'")->toContain("new CustomEvent('crm-badges-updated'");

    // El notifier de la topbar ya no calcula los indicadores (sin consultas duplicadas).
    Livewire::actingAs($user)->test(\Modules\Crm\Livewire\NewLeadNotifier::class)->call('check')->assertNotDispatched('crm-badges-updated');
});
