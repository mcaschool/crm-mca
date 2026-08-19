<?php

declare(strict_types=1);

namespace Modules\Crm\Enums;

/**
 * Vocabulario de eventos de comportamiento. "Todo comportamiento deja rastro",
 * aunque la respuesta sea un enlace. Se guarda como string en events.event_type;
 * este enum documenta el vocabulario conocido (se admiten otros valores).
 */
enum EventType: string
{
    case WidgetOpened = 'widget_opened';
    case ContactCreated = 'contact_created';
    case UsedMatcher = 'used_matcher';
    case ViewedProgram = 'viewed_program';
    case ViewedCertification = 'viewed_certification';
    case StartedCelia = 'started_celia';
    case ClickedEnrollment = 'clicked_enrollment';
    case UnresolvedQuestion = 'unresolved_question';
    case LeadCaptured = 'lead_captured';
    case ProgramInterest = 'program_interest';
    case Recontacted = 'recontacted';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
