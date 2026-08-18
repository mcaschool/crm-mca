<?php

declare(strict_types=1);

use Modules\Audit\Models\AuditLog;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\Message;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;

it('purga los mensajes mas antiguos que el umbral y respeta los recientes', function () {
    config(['crm.retention.messages_months' => 24]);

    $institution = Institution::factory()->create();

    [$oldId, $recentId, $leadId, $contactId] = app(CurrentInstitution::class)->runFor($institution->id, function () {
        $bot = Bot::factory()->create();
        $contact = Contact::factory()->create();
        $lead = Lead::factory()->create(['contact_id' => $contact->id, 'bot_id' => $bot->id]);
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id, 'contact_id' => $contact->id]);

        $old = Message::factory()->create(['conversation_id' => $conversation->id]);
        $recent = Message::factory()->create(['conversation_id' => $conversation->id]);

        // Se envejece el mensaje "viejo" 25 meses (sin tocar otros timestamps).
        Message::query()->whereKey($old->id)->update(['created_at' => now()->subMonths(25)]);
        Message::query()->whereKey($recent->id)->update(['created_at' => now()->subMonth()]);

        return [$old->id, $recent->id, $lead->id, $contact->id];
    });

    $this->artisan('crm:purge-retention', ['--institution' => $institution->id])->assertSuccessful();

    app(CurrentInstitution::class)->runFor($institution->id, function () use ($oldId, $recentId, $leadId, $contactId) {
        // El mensaje viejo se purga; el reciente se conserva.
        expect(Message::query()->withoutGlobalScopes()->find($oldId))->toBeNull();
        expect(Message::query()->withoutGlobalScopes()->find($recentId))->not->toBeNull();

        // NO se tocan contactos ni leads.
        expect(Lead::query()->find($leadId))->not->toBeNull();
        expect(Contact::query()->find($contactId))->not->toBeNull();
    });
});

it('con --dry-run no borra nada NI audita', function () {
    config(['crm.retention.messages_months' => 24]);
    $institution = Institution::factory()->create();

    $oldId = app(CurrentInstitution::class)->runFor($institution->id, function () {
        $bot = Bot::factory()->create();
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);
        $old = Message::factory()->create(['conversation_id' => $conversation->id]);
        Message::query()->whereKey($old->id)->update(['created_at' => now()->subMonths(30)]);

        return $old->id;
    });

    $this->artisan('crm:purge-retention', ['--institution' => $institution->id, '--dry-run' => true])->assertSuccessful();

    app(CurrentInstitution::class)->runFor($institution->id, function () use ($oldId) {
        expect(Message::query()->withoutGlobalScopes()->find($oldId))->not->toBeNull();
    });
    // Dry-run NO debe escribir auditoria.
    expect(AuditLog::withoutGlobalScopes()->where('action', 'retention.purged')->exists())->toBeFalse();
});

it('audita la purga real por institución (conteo, sin valores borrados)', function () {
    config(['crm.retention.messages_months' => 24]);
    $institution = Institution::factory()->create();

    app(CurrentInstitution::class)->runFor($institution->id, function () {
        $bot = Bot::factory()->create();
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);
        $old = Message::factory()->create(['conversation_id' => $conversation->id, 'content' => 'TEXTO-SECRETO-VIEJO']);
        Message::query()->whereKey($old->id)->update(['created_at' => now()->subMonths(30)]);
    });

    $this->artisan('crm:purge-retention', ['--institution' => $institution->id])->assertSuccessful();

    $row = AuditLog::withoutGlobalScopes()->where('action', 'retention.purged')->first();
    expect($row)->not->toBeNull();
    expect($row->institution_id)->toBe($institution->id);           // respeta la institución
    expect($row->auditable_type)->toBe('Modules\\Institutions\\Models\\Institution');
    expect($row->changes['entity'])->toBe('messages');
    expect($row->changes['deleted'])->toBe(1);
    expect($row->changes['older_than_months'])->toBe(24);
    // NUNCA el contenido del mensaje borrado.
    expect(json_encode($row->changes))->not->toContain('TEXTO-SECRETO-VIEJO');
});
