<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use Modules\Social\Livewire\Inbox;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;

/*
| Bandeja social · búsqueda y filtros (etapa 7): por nombre o identificador, por cuenta, por estado
| de atención y solo no leídas. Son consultas locales: no cambian el estado de lectura, no llaman a
| Meta, respetan la institución y no cierran la conversación abierta.
*/

beforeEach(function () {
    Http::fake();
});

/** @return array{0:User,1:array<string,SocialConversation>,2:array<string,SocialChannel>} */
function filtersCtx(): array
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);
    $user = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin', 'status' => 'active']);

    $wa = SocialChannel::factory()->create(['provider' => 'whatsapp', 'display_name' => 'Admisiones', 'external_id' => 'WA_F', 'credentials' => ['token' => 'X']]);
    $ig = SocialChannel::factory()->create(['provider' => 'instagram', 'display_name' => '@escuela', 'external_id' => 'IG_F', 'credentials' => ['token' => 'X']]);

    $c = fn (SocialChannel $ch, string $name, string $ext, int $unread, string $state, int $minutes) => SocialConversation::factory()->create([
        'social_channel_id' => $ch->id, 'provider' => $ch->provider, 'contact_name' => $name, 'external_conversation_id' => 'conv_'.$ext,
        'contact_external_id' => $ext, 'unread_count' => $unread, 'automation_state' => $state, 'last_message_at' => now()->subMinutes($minutes),
    ]);
    $convs = [
        'camila' => $c($wa, 'Camila Restrepo', '+57 300 111 2233', 3, 'bot', 1),
        'jorge' => $c($wa, 'Jorge Díaz', '+57 300 999 8877', 0, 'waiting_human', 2),
        'ana' => $c($ig, 'Ana Torres', 'ana.torres.ig', 1, 'human', 3),
        'legacy' => $c($ig, 'Pedro Legado', 'pedro.ig', 0, 'paused', 4),
    ];

    return [$user, $convs, ['wa' => $wa, 'ig' => $ig]];
}

/** @return list<string> nombres visibles en la lista, en orden */
function listNames(object $t): array
{
    return $t->viewData('conversations')->pluck('contact_name')->all();
}

it('busca por nombre y por identificador del contacto', function () {
    [$user] = filtersCtx();
    $t = Livewire::actingAs($user)->test(Inbox::class);

    expect(listNames($t->set('search', 'camila')))->toBe(['Camila Restrepo'])
        ->and(listNames($t->set('search', '999 8877')))->toBe(['Jorge Díaz'])
        ->and(listNames($t->set('search', 'ana.torres')))->toBe(['Ana Torres'])
        ->and(listNames($t->set('search', '100%_')))->toBe([]);   // comodines tratados como texto
    $t->assertSee('Ninguna conversación coincide.');
});

it('filtra por cuenta, por estado de atención y por no leídas', function () {
    [$user, , $ch] = filtersCtx();
    $t = Livewire::actingAs($user)->test(Inbox::class);

    expect(listNames($t->set('channel', (string) $ch['ig']->id)))->toBe(['Ana Torres', 'Pedro Legado']);
    $t->set('channel', '');
    expect(listNames($t->set('attentionFilter', 'waiting_human')))->toBe(['Jorge Díaz'])
        ->and(listNames($t->set('attentionFilter', 'paused')))->toBe(['Pedro Legado']);
    $t->set('attentionFilter', '');
    expect(listNames($t->call('toggleUnreadOnly')))->toBe(['Camila Restrepo', 'Ana Torres']);

    // Combinados y limpieza.
    expect(listNames($t->set('channel', (string) $ch['wa']->id)))->toBe(['Camila Restrepo']);
    expect(listNames($t->call('clearFilters')))->toHaveCount(4);
    expect($t->get('unreadOnly'))->toBeFalse();
});

it('filtrar no cambia el estado de lectura, no llama a Meta y conserva la conversación abierta', function () {
    [$user, $convs] = filtersCtx();
    SocialMessage::query()->create(['social_conversation_id' => $convs['ana']->id, 'external_message_id' => 'mf1', 'direction' => 'inbound', 'type' => 'text',
        'body' => 'Hola desde Instagram', 'status' => 'received', 'sender_type' => 'contact', 'provider_timestamp' => now()]);

    $t = Livewire::actingAs($user)->test(Inbox::class)
        ->call('select', $convs['ana']->id)                 // abrir sí marca leída (comportamiento existente)
        ->set('search', 'camila')->set('attentionFilter', 'bot')->call('toggleUnreadOnly');

    expect(listNames($t))->toBe(['Camila Restrepo'])
        ->and($t->get('selectedId'))->toBe($convs['ana']->id);
    $t->assertSee('Hola desde Instagram');
    expect($convs['camila']->fresh()->unread_count)->toBe(3)      // filtrar no marca nada como leído
        ->and($convs['jorge']->fresh()->unread_count)->toBe(0)
        ->and($convs['ana']->fresh()->unread_count)->toBe(0);
    Http::assertNothingSent();
});

it('la cabecera muestra los totales de la bandeja y el tramo filtrado', function () {
    [$user] = filtersCtx();
    Livewire::actingAs($user)->test(Inbox::class)
        ->set('search', 'a')->call('toggleUnreadOnly')
        ->assertSee('4 conversaciones')->assertSee('4 mensajes sin leer')
        ->assertSee('2 de 4');
});

it('la búsqueda nunca muestra conversaciones de otra institución', function () {
    filtersCtx();                                            // institución A
    $foreignCamila = SocialConversation::query()->where('contact_name', 'Camila Restrepo')->value('id');

    [$userB] = filtersCtx();                                 // institución B (su propia Camila)
    $t = Livewire::actingAs($userB)->test(Inbox::class)->set('search', 'Camila');

    expect($t->viewData('conversations')->pluck('id')->all())->not->toContain($foreignCamila)
        ->and($t->viewData('conversations'))->toHaveCount(1)
        ->and($t->viewData('channels'))->toHaveCount(2);
});

it('sin sesión no se accede, y una conversación de otra institución no se puede abrir ni marcar leída', function () {
    [, $foreign] = filtersCtx();                             // institución A
    [$userB] = filtersCtx();                                 // institución B

    $this->get('/social/bandeja')->assertRedirect('/login');

    $t = Livewire::actingAs($userB)->test(Inbox::class)->call('select', $foreign['camila']->id);
    expect($t->get('selectedId'))->toBeNull()
        ->and(SocialConversation::withoutGlobalScopes()->find($foreign['camila']->id)->unread_count)->toBe(3);
});
