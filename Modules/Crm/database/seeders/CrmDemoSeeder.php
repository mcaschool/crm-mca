<?php

declare(strict_types=1);

namespace Modules\Crm\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Catalog\Models\Program;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Enums\EventType;
use Modules\Crm\Enums\InterestLevel;
use Modules\Crm\Enums\LeadStatus;
use Modules\Crm\Models\Lead;
use Modules\Crm\Services\ContactService;
use Modules\Crm\Services\ConversationService;
use Modules\Crm\Services\EventService;
use Modules\Crm\Services\LeadService;
use Modules\Crm\Services\MessageService;
use Modules\Crm\Services\ProgramInterestService;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;

/**
 * DATOS DE DEMO — NO usar en produccion. Puebla el CRM con contactos, leads,
 * conversaciones, mensajes y eventos realistas para ver el panel funcionando.
 * No depende del Excel real: crea 3 programas de ejemplo para las relaciones.
 *
 * Ejecutar: php artisan db:seed --class="Modules\\Crm\\Database\\Seeders\\CrmDemoSeeder"
 */
class CrmDemoSeeder extends Seeder
{
    public function run(): void
    {
        $context = app(CurrentInstitution::class);

        /** @var Institution|null $institution */
        $institution = $context->runGlobally(fn () => Institution::query()->orderBy('id')->first());
        if ($institution === null) {
            $this->command->error('No hay institucion. Corre primero el seed base.');

            return;
        }

        $context->runFor($institution->id, function () use ($institution): void {
            $bot = Bot::query()->first() ?? Bot::factory()->create(['institution_id' => $institution->id]);

            $programs = collect(['DEMO-1' => 'Analitica de Datos', 'DEMO-2' => 'Gestion de Proyectos', 'DEMO-3' => 'Marketing Digital'])
                ->map(fn (string $name, string $code) => Program::query()->firstOrCreate(
                    ['code' => $code],
                    ['name_es' => $name, 'url' => 'https://mcaschool.us/'.strtolower($code), 'status' => 'active', 'level' => 'inicial', 'goal' => 'actualizar'],
                ))->values();

            $contacts = [
                ['first_name' => 'Ana', 'last_name' => 'Perez', 'email' => 'ana.perez@example.com', 'country' => 'MX', 'status' => LeadStatus::New, 'interest' => InterestLevel::High],
                ['first_name' => 'Luis', 'last_name' => 'Gomez', 'email' => 'luis.gomez@example.com', 'country' => 'CO', 'status' => LeadStatus::Contacted, 'interest' => InterestLevel::Medium],
                ['first_name' => 'Marta', 'last_name' => 'Diaz', 'email' => 'marta.diaz@example.com', 'country' => 'ES', 'status' => LeadStatus::Qualified, 'interest' => InterestLevel::High],
                ['first_name' => 'Carlos', 'last_name' => 'Ruiz', 'email' => 'carlos.ruiz@example.com', 'country' => 'US', 'status' => LeadStatus::Enrolled, 'interest' => InterestLevel::High],
                ['first_name' => 'Sofia', 'last_name' => 'Lopez', 'email' => 'sofia.lopez@example.com', 'country' => 'MX', 'status' => LeadStatus::Discarded, 'interest' => InterestLevel::Low],
            ];

            $contactService = app(ContactService::class);
            $leadService = app(LeadService::class);
            $conversationService = app(ConversationService::class);
            $messageService = app(MessageService::class);
            $eventService = app(EventService::class);
            $interestService = app(ProgramInterestService::class);

            foreach ($contacts as $i => $data) {
                $contact = $contactService->createOrUpdate([
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'email' => $data['email'],
                    'country' => $data['country'],
                    'preferred_language' => 'es',
                    'consent' => true,
                    'consent_source' => 'widget',
                ]);

                $program = $programs[$i % $programs->count()];

                $conversation = $conversationService->start([
                    'bot_id' => $bot->id,
                    'contact_id' => $contact->id,
                    'mode' => 'guided',
                    'language' => 'es',
                ]);

                $eventService->record(EventType::WidgetOpened, ['contact_id' => $contact->id, 'conversation_id' => $conversation->id, 'bot_id' => $bot->id]);
                $messageService->record($conversation, 'user', 'Hola, quiero informacion de microcredenciales.', 'text');
                $messageService->record($conversation, 'system', 'Con gusto. Aqui tienes el menu de areas.', 'menu');
                $eventService->record(EventType::UsedMatcher, ['contact_id' => $contact->id, 'conversation_id' => $conversation->id, 'bot_id' => $bot->id]);
                $interestService->record($contact, $program, $bot->id, 'matcher');

                $lead = $leadService->recordIntent($contact, [
                    'bot_id' => $bot->id,
                    'program_id' => $program->id,
                    'area' => 'Tecnologia',
                    'goal' => 'actualizar',
                    'level' => 'inicial',
                    'source' => 'widget_microcredenciales',
                    'interest_level' => $data['interest'],
                ]);

                // Estado de demo (se fija directo para no chocar con el terminal).
                Lead::query()->whereKey($lead->id)->update(['status' => $data['status']->value]);
            }

            $this->command->info('Demo CRM: '.count($contacts).' contactos con leads, conversaciones y eventos.');
        });
    }
}
