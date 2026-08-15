<?php

declare(strict_types=1);

namespace Modules\Crm\Services;

use Modules\Catalog\Models\Program;
use Modules\Crm\Enums\EventType;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\ProgramInterest;

/**
 * Registra el interes de un contacto por un programa del catalogo. Cada interes
 * deja ademas un evento de rastro (viewed_program / program_interest).
 */
class ProgramInterestService
{
    public function __construct(private readonly EventService $events) {}

    public function record(
        Contact $contact,
        Program $program,
        int $botId,
        string $source = 'matcher',
    ): ProgramInterest {
        $interest = new ProgramInterest;
        $interest->contact_id = $contact->getKey();
        $interest->program_id = $program->getKey();
        $interest->bot_id = $botId;
        $interest->source = $source;
        $interest->save();

        // Rastro del comportamiento asociado al contacto y programa.
        $this->events->record(EventType::ProgramInterest, [
            'contact_id' => $contact->getKey(),
            'bot_id' => $botId,
            'data' => ['program_id' => $program->getKey(), 'source' => $source],
        ]);

        return $interest;
    }
}
