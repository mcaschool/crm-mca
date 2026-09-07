<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Support\SecretMasker;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use Modules\Social\Livewire\Channels;
use Modules\Social\Models\SocialChannel;

/**
 * Admin de canales sociales (Bloque 3b, dentro de Configuraciones). CRUD solo-Admin con
 * credenciales cifradas y enmascaradas, varios canales por proveedor, scoping por institución
 * y la ayuda de webhook (callback URL + verify token).
 */
function channelsCtx(): array
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);
    $admin = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin']);

    return [$institution, $admin];
}

// ----------------------------------------------------------------------------------
// Alta por proveedor + VARIOS WhatsApp
// ----------------------------------------------------------------------------------
it('crea un canal por proveedor y admite varios números de WhatsApp', function () {
    [$institution, $admin] = channelsCtx();

    $component = Livewire::actingAs($admin)->test(Channels::class);
    $rows = [
        ['whatsapp', 'Admisiones · Línea 1', 'wa_pn_1'],
        ['messenger', 'Página MCA School', 'page_1'],
        ['instagram', '@mca.school', 'ig_1'],
        ['whatsapp', 'Admisiones · Línea 2', 'wa_pn_2'],
    ];
    foreach ($rows as [$provider, $name, $ext]) {
        $component->call('create')
            ->set('provider', $provider)
            ->set('display_name', $name)
            ->set('external_id', $ext)
            ->set('token', 'TOKEN_'.$ext.'_permanente')
            ->set('is_active', true)
            ->call('save')
            ->assertHasNoErrors();
    }

    app(CurrentInstitution::class)->runFor($institution->id, function () {
        expect(SocialChannel::query()->count())->toBe(4);
        expect(SocialChannel::query()->where('provider', 'whatsapp')->count())->toBe(2);
    });
});

it('rechaza un canal duplicado (mismo proveedor + mismo identificador)', function () {
    [$institution, $admin] = channelsCtx();
    app(CurrentInstitution::class)->runFor($institution->id, fn () => SocialChannel::factory()->create(['provider' => 'whatsapp', 'external_id' => 'dupe', 'credentials' => ['token' => 'x']]));

    Livewire::actingAs($admin)->test(Channels::class)
        ->call('create')->set('provider', 'whatsapp')->set('display_name', 'Otro')->set('external_id', 'dupe')->set('token', 'y')
        ->call('save')
        ->assertHasErrors('external_id');
});

// ----------------------------------------------------------------------------------
// Cifrado + enmascarado
// ----------------------------------------------------------------------------------
it('guarda el token cifrado y lo muestra enmascarado (nunca en claro)', function () {
    [$institution, $admin] = channelsCtx();

    Livewire::actingAs($admin)->test(Channels::class)
        ->call('create')->set('provider', 'instagram')->set('display_name', 'IG')->set('external_id', 'ig_9')->set('token', 'SUPERSECRETO12345')
        ->call('save')->assertHasNoErrors();

    app(CurrentInstitution::class)->runFor($institution->id, function () {
        $channel = SocialChannel::query()->first();
        $raw = (string) DB::table('social_channels')->where('id', $channel->id)->value('credentials');
        expect(str_contains($raw, 'SUPERSECRETO'))->toBeFalse();      // cifrado en BD
        expect($channel->credentials['token'])->toBe('SUPERSECRETO12345'); // legible por el cast
    });

    // En la UI solo el enmascarado.
    Livewire::actingAs($admin)->test(Channels::class)
        ->assertSee(SecretMasker::mask('SUPERSECRETO12345'))
        ->assertDontSee('SUPERSECRETO12345');
});

// ----------------------------------------------------------------------------------
// Editar: no precarga el token; reemplaza solo si se escribe uno nuevo
// ----------------------------------------------------------------------------------
it('al editar no precarga el token; vacío conserva, nuevo reemplaza', function () {
    [$institution, $admin] = channelsCtx();
    $channel = app(CurrentInstitution::class)->runFor($institution->id, fn () => SocialChannel::factory()->create([
        'provider' => 'messenger', 'external_id' => 'pg_1', 'display_name' => 'Página', 'credentials' => ['token' => 'OLDTOKEN123456'],
    ]));

    // Editar: token vacío, se muestra el enmascarado del actual.
    $edit = Livewire::actingAs($admin)->test(Channels::class)->call('edit', $channel->id)
        ->assertSet('token', '')
        ->assertSet('currentTokenMask', SecretMasker::mask('OLDTOKEN123456'));

    // Guardar sin token → conserva el actual, cambia el nombre.
    $edit->set('display_name', 'Página editada')->set('token', '')->call('save')->assertHasNoErrors();
    $channel->refresh();
    expect($channel->credentials['token'])->toBe('OLDTOKEN123456');
    expect($channel->display_name)->toBe('Página editada');

    // Guardar con token nuevo → reemplaza.
    Livewire::actingAs($admin)->test(Channels::class)->call('edit', $channel->id)->set('token', 'NEWTOKEN999888')->call('save');
    expect($channel->refresh()->credentials['token'])->toBe('NEWTOKEN999888');
});

// ----------------------------------------------------------------------------------
// is_active on/off
// ----------------------------------------------------------------------------------
it('activa y desactiva un canal', function () {
    [$institution, $admin] = channelsCtx();
    $channel = app(CurrentInstitution::class)->runFor($institution->id, fn () => SocialChannel::factory()->create(['is_active' => true, 'credentials' => ['token' => 'x']]));

    Livewire::actingAs($admin)->test(Channels::class)->call('toggle', $channel->id);
    expect($channel->refresh()->is_active)->toBeFalse();

    Livewire::actingAs($admin)->test(Channels::class)->call('toggle', $channel->id);
    expect($channel->refresh()->is_active)->toBeTrue();
});

// ----------------------------------------------------------------------------------
// Scoping por institución
// ----------------------------------------------------------------------------------
it('no muestra ni edita canales de otra institución', function () {
    [$institutionA] = channelsCtx();
    $channelA = app(CurrentInstitution::class)->runFor($institutionA->id, fn () => SocialChannel::factory()->create(['display_name' => 'Canal Ajeno A', 'external_id' => 'a1', 'credentials' => ['token' => 'x']]));

    $institutionB = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institutionB->id);
    $adminB = User::factory()->create(['institution_id' => $institutionB->id, 'role' => 'admin']);

    // No lo ve en la lista.
    Livewire::actingAs($adminB)->test(Channels::class)->assertDontSee('Canal Ajeno A');

    // No lo puede editar (el scope lo oculta → no encontrado).
    expect(fn () => Livewire::actingAs($adminB)->test(Channels::class)->call('edit', $channelA->id))
        ->toThrow(ModelNotFoundException::class);
});

// ----------------------------------------------------------------------------------
// Solo Admin
// ----------------------------------------------------------------------------------
it('solo Admin entra; Marketing recibe el bloqueo del guard (403)', function () {
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);

    // Marketing NO gestiona credenciales → 403 (mismo patrón que catálogo/integraciones).
    $marketing = User::factory()->create(['institution_id' => $institution->id, 'role' => 'marketing']);
    $this->actingAs($marketing)->get('/social/canales')->assertForbidden();

    $admin = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin']);
    $this->actingAs($admin)->get('/social/canales')->assertOk();
});

// ----------------------------------------------------------------------------------
// Ayuda de webhook
// ----------------------------------------------------------------------------------
it('muestra la Callback URL por proveedor y el Verify Token', function () {
    [, $admin] = channelsCtx();
    config(['social.webhook_verify_token' => 'VERIFY_TOKEN_XYZ']);

    Livewire::actingAs($admin)->test(Channels::class)
        ->assertSee(url('/api/social/webhook/whatsapp'))
        ->assertSee(url('/api/social/webhook/instagram'))
        ->assertSee(url('/api/social/webhook/messenger'))
        ->assertSee('VERIFY_TOKEN_XYZ');
});
