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
| Bandeja social (modernización visual, etapa 5B): conversaciones, hilo y datos del contacto con el
| kit compartido; navegación móvil lista → conversación → volver. Solo presentación: mismas acciones
| wire:, mismo sondeo y mismas reglas de envío. Nada sale hacia Meta (Http::fake sin respuestas).
*/

beforeEach(function () {
    Http::fake();
});

/** @return array{0:User,1:SocialConversation,2:SocialConversation,3:SocialConversation} */
function inboxCtx(): array
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);
    $user = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin', 'status' => 'active']);
    config(['auth.two_factor.exempt_emails' => [mb_strtolower($user->email)]]);

    $wa = SocialChannel::factory()->create(['provider' => 'whatsapp', 'display_name' => 'Admisiones', 'external_id' => 'WA_1', 'credentials' => ['token' => 'X']]);
    $ig = SocialChannel::factory()->create(['provider' => 'instagram', 'display_name' => '@escuela', 'external_id' => 'IG_1', 'credentials' => ['token' => 'X']]);

    $open = SocialConversation::factory()->create(['social_channel_id' => $wa->id, 'provider' => 'whatsapp', 'contact_name' => 'Camila Restrepo',
        'external_conversation_id' => 'WA_C', 'contact_external_id' => '+57 300 000 0000', 'unread_count' => 3, 'last_message_at' => now()]);
    $waiting = SocialConversation::factory()->create(['social_channel_id' => $ig->id, 'provider' => 'instagram', 'contact_name' => null,
        'external_conversation_id' => 'IG_C', 'contact_external_id' => 'IG_C', 'unread_count' => 0, 'automation_state' => 'waiting_human', 'last_message_at' => now()->subHour()]);
    $closed = SocialConversation::factory()->create(['social_channel_id' => $wa->id, 'provider' => 'whatsapp', 'contact_name' => 'Ricardo Peña',
        'external_conversation_id' => 'WA_R', 'contact_external_id' => 'WA_R', 'unread_count' => 0, 'last_message_at' => now()->subDays(3)]);

    $msg = fn (SocialConversation $c, string $dir, ?string $body, $at, array $extra = []) => SocialMessage::query()->create(array_merge([
        'social_conversation_id' => $c->id, 'external_message_id' => uniqid('m'), 'direction' => $dir, 'type' => 'text', 'body' => $body,
        'status' => $dir === 'inbound' ? 'received' : 'sent', 'sender_type' => $dir === 'inbound' ? 'contact' : 'agent', 'provider_timestamp' => $at,
    ], $extra));
    $msg($open, 'inbound', 'Hola, quería información.', now()->subDay());
    $msg($open, 'outbound', 'Claro, te ayudo.', now()->subDay()->addMinute(), ['sender_type' => 'bot']);
    $msg($open, 'inbound', null, now()->subMinutes(10), ['type' => 'document', 'attachments' => [['type' => 'document', 'storage_path' => 'social-wa/'.$institution->id.'/programa.pdf', 'filename' => 'Programa.pdf']]]);
    $msg($open, 'inbound', "Mensaje muy largo\ncon varias líneas.", now()->subMinutes(5));
    $msg($open, 'outbound', 'No llegó', now()->subMinutes(4), ['status' => 'failed']);
    $msg($closed, 'inbound', 'Lo pienso.', now()->subDays(3));

    return [$user, $open, $waiting, $closed];
}

it('la lista usa el kit, marca no leídos y las conversaciones que esperan a una persona', function () {
    [$user, $open, $waiting] = inboxCtx();

    $html = Livewire::actingAs($user)->test(Inbox::class)->html();

    expect($html)->not->toContain('class="sb-')->not->toContain('social-inbox{')
        ->toContain('class="ui-inbox"')->toContain('class="ui-card ui-inbox-list"')
        ->toContain('3 mensajes sin leer')
        ->toContain('wire:click="select('.$open->id.')" class="ui-conv is-unread"')
        ->toContain('class="ui-conv-flag ui-tone--warning"')->toContain('Esperando a una persona')
        ->toContain('Contacto sin nombre')
        ->toContain('Selecciona una conversación')
        ->and(substr_count($html, 'class="ui-conv-src"'))->toBe(3);
});

it('el hilo separa entrantes y salientes, agrupa por día y muestra adjuntos, IA y fallos', function () {
    [$user, $open] = inboxCtx();

    $html = Livewire::actingAs($user)->test(Inbox::class)->call('select', $open->id)->html();

    expect($html)->toContain('data-selected')->toContain('aria-current="true"')
        ->toContain("wire:click=\"\$set('selectedId', null)\"") // volver a la lista (móvil)
        ->toContain('class="ui-inbox-day"')->toContain('Ayer')->toContain('Hoy')
        ->toContain('ui-bubble ui-bubble--in')->toContain('ui-bubble ui-bubble--out')
        ->toContain('data-testid="ai-tag"')->toContain('class="ui-bubble-doc"')->toContain('Programa.pdf')
        ->toContain('is-failed')->toContain('No se pudo enviar el mensaje. Inténtalo de nuevo.')
        ->toContain('Mensaje muy largo<br />')
        ->toContain('class="ui-input ui-inbox-text"')->toContain('$wire.send(draft)')
        ->toContain('id="inbox-info"')->toContain('Datos del contacto')->toContain('+57 300 000 0000');
    expect($open->fresh()->unread_count)->toBe(0); // abrir la sigue marcando como leída
});

it('fuera de la ventana de 24 h solo ofrece plantillas y el selector usa el modal del kit', function () {
    [$user, , , $closed] = inboxCtx();

    Livewire::actingAs($user)->test(Inbox::class)->call('select', $closed->id)
        ->assertSee('La ventana de atención de 24 horas ha finalizado.')
        ->assertDontSeeHtml('class="ui-input ui-inbox-text"')
        ->call('openTemplates')
        ->assertSeeHtml('class="ui-modal ui-modal--sm" role="dialog"')
        ->assertSee('No hay plantillas aprobadas disponibles para este canal.')
        ->call('closeTemplates')
        ->assertDontSeeHtml('role="dialog"');

    Http::assertNothingSent();
});

it('volver a la lista solo cambia la vista: no envía ni altera la conversación', function () {
    [$user, $open] = inboxCtx();

    Livewire::actingAs($user)->test(Inbox::class)->call('select', $open->id)
        ->set('selectedId', null)
        ->assertSet('selectedId', null)
        ->assertSee('Selecciona una conversación');

    Http::assertNothingSent();
    expect(SocialMessage::query()->where('social_conversation_id', $open->id)->count())->toBe(5);
});
