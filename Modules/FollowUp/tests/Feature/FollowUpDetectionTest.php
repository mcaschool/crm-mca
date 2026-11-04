<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\FollowUp\Livewire\Opportunities;
use Modules\FollowUp\Models\FollowUpOpportunity;
use Modules\FollowUp\Services\OpportunityDetector;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialMessage;
use Modules\Social\Services\NormalizedMessage;
use Modules\Social\Services\SocialIngestService;

beforeEach(function () {
    config([
        'followup.detection_enabled' => true,
        'followup.silence_hours' => 24,
        'followup.lookback_days' => 90,
        'followup.batch_size' => 100,
        'social.advisor.autoreply_enabled' => false,
    ]);
});

/**
 * @return array{institution:Institution,bot:Bot,channel:SocialChannel,conversation:SocialConversation,inbound:SocialMessage,outbound:SocialMessage}
 */
function followUpSilentConversation(string $provider = 'whatsapp', string $question = 'Quiero inscribirme, ¿como hago el pago?', ?Institution $institution = null): array
{
    $institution ??= Institution::factory()->create();

    return app(CurrentInstitution::class)->runFor($institution->id, function () use ($institution, $provider, $question): array {
        $bot = Bot::factory()->create(['assistant_name' => 'Sofia']);
        $channel = SocialChannel::factory()->create([
            'provider' => $provider,
            'external_id' => 'channel_'.uniqid(),
            'advisor_bot_id' => $bot->id,
            'advisor_enabled' => true,
            'is_active' => true,
        ]);
        $conversation = SocialConversation::factory()->create([
            'social_channel_id' => $channel->id,
            'provider' => $provider,
            'external_conversation_id' => 'thread_'.uniqid(),
            'contact_name' => 'Andrea Prospecto',
            'contact_external_id' => $provider === 'whatsapp' ? '573001112233' : 'social_'.uniqid(),
            'automation_state' => 'bot',
            'assigned_to' => null,
            'last_message_at' => now()->subHours(26),
        ]);
        $inbound = SocialMessage::factory()->create([
            'social_conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'sender_type' => 'contact',
            'body' => $question,
            'provider_timestamp' => now()->subHours(30),
            'created_at' => now()->subHours(30),
            'updated_at' => now()->subHours(30),
        ]);
        $outbound = SocialMessage::factory()->create([
            'social_conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'sender_type' => 'bot',
            'body' => 'Te comparto los pasos oficiales de inscripción.',
            'status' => 'sent',
            'provider_timestamp' => now()->subHours(26),
            'created_at' => now()->subHours(26),
            'updated_at' => now()->subHours(26),
        ]);

        return compact('institution', 'bot', 'channel', 'conversation', 'inbound', 'outbound');
    });
}

it('detecta y clasifica una conversación inactiva sin generar mensajes ni modificar el lead', function () {
    $data = followUpSilentConversation();

    app(CurrentInstitution::class)->runFor($data['institution']->id, function () use ($data): void {
        $contact = Contact::factory()->phoneOnly('+57 300 111 2233')->create();
        $lead = Lead::factory()->create([
            'contact_id' => $contact->id,
            'bot_id' => $data['bot']->id,
            'status' => 'qualified',
        ]);
        $leadBefore = $lead->fresh()->getAttributes();
        $messageCount = SocialMessage::query()->count();

        $result = app(OpportunityDetector::class)->detectDue();

        expect($result)->toMatchArray(['detected' => 1, 'updated' => 0, 'cancelled' => 0])
            ->and(SocialMessage::query()->count())->toBe($messageCount)
            ->and($lead->fresh()->getAttributes())->toBe($leadBefore);

        $opportunity = FollowUpOpportunity::query()->sole();
        expect($opportunity->status)->toBe('open')
            ->and($opportunity->classification)->toBe('enrollment_intent')
            ->and($opportunity->priority)->toBe('high')
            ->and($opportunity->bot_id)->toBe($data['bot']->id)
            ->and($opportunity->social_conversation_id)->toBe($data['conversation']->id)
            ->and($opportunity->contact_id)->toBe($contact->id)
            ->and($opportunity->lead_id)->toBe($lead->id)
            ->and($opportunity->context_snapshot)->not->toHaveKey('message_text');
    });
});

it('es idempotente y la restricción única impide duplicados incluso bajo una segunda reclamación', function () {
    $data = followUpSilentConversation();

    app(CurrentInstitution::class)->runFor($data['institution']->id, function (): void {
        $detector = app(OpportunityDetector::class);
        expect($detector->detectDue()['detected'])->toBe(1)
            ->and($detector->detectDue()['detected'])->toBe(0)
            ->and(FollowUpOpportunity::query()->count())->toBe(1);

        $existing = FollowUpOpportunity::query()->sole();
        $duplicate = $existing->replicate();
        $duplicate->detected_at = now();

        expect(fn () => $duplicate->save())->toThrow(UniqueConstraintViolationException::class);
    });
});

it('cancela inmediatamente la oportunidad cuando el prospecto responde mediante la ingesta real', function () {
    $data = followUpSilentConversation();

    app(CurrentInstitution::class)->runFor($data['institution']->id, fn () => app(OpportunityDetector::class)->detectDue());

    $result = app(SocialIngestService::class)->ingest(new NormalizedMessage(
        provider: 'whatsapp',
        channelExternalId: $data['channel']->external_id,
        conversationExternalId: $data['conversation']->external_conversation_id,
        contactName: 'Andrea Prospecto',
        contactExternalId: '573001112233',
        contactAvatarUrl: null,
        messageExternalId: 'wamid.RESPONSE001',
        type: 'text',
        body: 'Gracias, ya lo revisé.',
        attachments: null,
        providerTimestamp: now()->toImmutable(),
    ));

    expect($result->status)->toBe('created');
    app(CurrentInstitution::class)->runFor($data['institution']->id, function () use ($result): void {
        $opportunity = FollowUpOpportunity::query()->sole();
        expect($opportunity->status)->toBe('cancelled')
            ->and($opportunity->cancellation_reason)->toBe('prospect_replied')
            ->and($opportunity->source_last_message_id)->toBe($result->messageId)
            ->and($opportunity->cancelled_at)->not->toBeNull();
    });
});

it('reabre la misma oportunidad como un nuevo ciclo después de otra respuesta y un nuevo silencio', function () {
    $data = followUpSilentConversation();

    app(CurrentInstitution::class)->runFor($data['institution']->id, function () use ($data): void {
        $detector = app(OpportunityDetector::class);
        $detector->detectDue();
        $reply = SocialMessage::factory()->create([
            'social_conversation_id' => $data['conversation']->id,
            'direction' => 'inbound',
            'sender_type' => 'contact',
            'provider_timestamp' => now()->subHours(28),
            'created_at' => now()->subHours(28),
        ]);
        $detector->recordProspectResponse($data['conversation']->id, $reply->id);
        $newOutbound = SocialMessage::factory()->create([
            'social_conversation_id' => $data['conversation']->id,
            'direction' => 'outbound',
            'sender_type' => 'bot',
            'status' => 'sent',
            'provider_timestamp' => now()->subHours(25),
            'created_at' => now()->subHours(25),
        ]);
        $data['conversation']->forceFill(['last_message_at' => now()->subHours(25)])->save();

        expect($detector->detectDue()['updated'])->toBe(1);
        $opportunity = FollowUpOpportunity::query()->sole();
        expect($opportunity->status)->toBe('open')
            ->and($opportunity->cycle)->toBe(2)
            ->and($opportunity->source_last_message_id)->toBe($newOutbound->id);
    });
});

it('excluye de forma determinista bajas y leads matriculados o descartados sin modificarlos', function (string $status, string $reason) {
    $data = followUpSilentConversation();

    app(CurrentInstitution::class)->runFor($data['institution']->id, function () use ($data, $status, $reason): void {
        $contact = Contact::factory()->phoneOnly('+573001112233')->create();
        $lead = Lead::factory()->create(['contact_id' => $contact->id, 'bot_id' => $data['bot']->id, 'status' => $status]);
        $before = $lead->fresh()->getAttributes();

        app(OpportunityDetector::class)->detectDue();

        $opportunity = FollowUpOpportunity::query()->sole();
        expect($opportunity->status)->toBe('suppressed')
            ->and($opportunity->cancellation_reason)->toBe($reason)
            ->and($lead->fresh()->getAttributes())->toBe($before);
    });
})->with([
    'matriculado' => ['enrolled', 'lead_enrolled'],
    'descartado' => ['discarded', 'lead_discarded'],
]);

it('aísla las oportunidades por institución en consultas y en la vista administrativa', function () {
    $first = followUpSilentConversation();
    $second = followUpSilentConversation(institution: Institution::factory()->create());
    app(CurrentInstitution::class)->runFor($first['institution']->id, fn () => app(OpportunityDetector::class)->detectDue());
    app(CurrentInstitution::class)->runFor($second['institution']->id, fn () => app(OpportunityDetector::class)->detectDue());

    $admin = User::factory()->create(['institution_id' => $first['institution']->id, 'role' => 'admin', 'status' => 'active']);
    app(CurrentInstitution::class)->set($first['institution']->id);

    expect(FollowUpOpportunity::query()->count())->toBe(1);
    Livewire::actingAs($admin)->test(Opportunities::class)
        ->assertSee('Andrea Prospecto')
        ->assertSee('Sofia');
});

it('reserva la vista administrativa a administradores', function (string $role) {
    $institution = Institution::factory()->create();
    $user = User::factory()->create(['institution_id' => $institution->id, 'role' => $role, 'status' => 'active']);
    app(CurrentInstitution::class)->set($institution->id);

    Livewire::actingAs($user)->test(Opportunities::class)->assertForbidden();
})->with(['marketing', 'admissions']);

it('el comando procesa instituciones explícitas y no hace nada si el interruptor está apagado', function () {
    $data = followUpSilentConversation();

    $this->artisan('followup:detect', ['--institution' => $data['institution']->id])
        ->expectsOutputToContain('1 nuevas')
        ->assertSuccessful();

    config(['followup.detection_enabled' => false]);
    $this->artisan('followup:detect', ['--institution' => $data['institution']->id])
        ->expectsOutputToContain('deteccion desactivada')
        ->assertSuccessful();
});
