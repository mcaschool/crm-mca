<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Catalog\Models\Program;
use Modules\Chat\Models\ConversationNode;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Models\Event;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\Message;
use Modules\Crm\Models\ProgramInterest;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;

it('borra los datos de CRM de prueba pero conserva catalogo, arbol y conocimiento', function () {
    $institution = Institution::factory()->create();

    app(CurrentInstitution::class)->runFor($institution->id, function () {
        $bot = Bot::factory()->create();
        $program = Program::factory()->create();
        KnowledgeSource::factory()->create(['bot_id' => $bot->id]);
        ConversationNode::factory()->create(['bot_id' => $bot->id]);

        $contact = Contact::factory()->create();
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id, 'contact_id' => $contact->id]);
        Message::factory()->create(['conversation_id' => $conversation->id]);
        Event::factory()->create(['conversation_id' => $conversation->id, 'contact_id' => $contact->id, 'bot_id' => $bot->id]);
        Lead::factory()->create(['contact_id' => $contact->id, 'bot_id' => $bot->id]);
        ProgramInterest::factory()->create(['contact_id' => $contact->id, 'program_id' => $program->id, 'bot_id' => $bot->id]);
    });

    // Antes: hay datos de CRM.
    foreach (['contacts', 'leads', 'conversations', 'messages', 'events', 'program_interests'] as $table) {
        expect(DB::table($table)->count())->toBeGreaterThan(0);
    }

    $this->artisan('crm:reset-demo --force')->assertSuccessful();

    // Despues: CRM vacio.
    foreach (['contacts', 'leads', 'conversations', 'messages', 'events', 'program_interests'] as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }

    // Conservados: catalogo, arbol y conocimiento intactos.
    expect(DB::table('programs')->count())->toBeGreaterThan(0);
    expect(DB::table('knowledge_sources')->count())->toBeGreaterThan(0);
    expect(DB::table('conversation_nodes')->count())->toBeGreaterThan(0);
    expect(DB::table('bots')->count())->toBeGreaterThan(0);
});

it('esta bloqueado en produccion sin --force', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('crm:reset-demo')->assertFailed();

    expect(true)->toBeTrue();
});
