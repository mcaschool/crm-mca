<?php

declare(strict_types=1);

namespace Modules\FollowUp\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Crm\Enums\LeadStatus;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Social\Models\SocialMessage;

/** Clasificacion inicial completamente determinista; no consulta IA ni servicios externos. */
final class OpportunityClassifier
{
    private const ENROLLMENT_TERMS = [
        'matricul', 'inscrip', 'inscrib', 'registrarme', 'reservar cupo', 'hacer el pago',
        'pago', 'pagar', 'enrollment', 'enrolment', 'register', 'apply now',
    ];

    private const STRONG_ENROLLMENT_PHRASES = [
        'quiero inscribirme', 'quiero matricularme', 'como hago el pago', 'cómo hago el pago',
        'donde puedo pagar', 'reservar mi cupo', 'iniciar la inscripcion', 'start my enrollment',
        'i want to enroll', 'i want to register',
    ];

    private const COMMERCIAL_TERMS = [
        'precio', 'costo', 'coste', 'valor', 'programa', 'duracion', 'certificado',
        'beca', 'descuento', 'horario', 'modalidad', 'price', 'cost', 'course',
        'program', 'certificate', 'schedule',
    ];

    private const OPT_OUT_TERMS = [
        'no me contacten', 'no me escriban', 'no quiero recibir', 'dejen de escribir',
        'darme de baja', 'unsubscribe', 'stop messaging', 'do not contact',
    ];

    /**
     * @param  Collection<int, SocialMessage>  $messages
     * @return array{status:string,classification:string,priority:string,score:int,reasons:array<int,string>,context:array<string,int|bool|string|null>}
     */
    public function classify(Collection $messages, ?Contact $contact, ?Lead $lead): array
    {
        $inbound = $messages->where('direction', 'inbound');
        $outbound = $messages->where('direction', 'outbound');
        $text = Str::ascii(mb_strtolower($inbound->pluck('body')->filter()->implode("\n")));
        $reasons = ['silent_after_response'];

        $suppression = $this->suppressionReason($text, $contact, $lead);
        if ($suppression !== null) {
            return [
                'status' => 'suppressed',
                'classification' => 'not_eligible',
                'priority' => 'low',
                'score' => 0,
                'reasons' => [$suppression],
                'context' => $this->context($inbound->count(), $outbound->count(), $lead, false, false),
            ];
        }

        $enrollment = $this->containsAny($text, self::ENROLLMENT_TERMS);
        $strongEnrollment = $this->containsAny($text, self::STRONG_ENROLLMENT_PHRASES);
        $commercial = $this->containsAny($text, self::COMMERCIAL_TERMS);
        $score = 20;

        if ($strongEnrollment) {
            $score += 50;
            $reasons[] = 'verified_enrollment_phrase';
        } elseif ($enrollment) {
            $score += 25;
            $reasons[] = 'enrollment_term';
        } elseif ($commercial) {
            $score += 25;
            $reasons[] = 'commercial_question';
        }

        if ($inbound->count() >= 2) {
            $score += 10;
            $reasons[] = 'repeated_engagement';
        }

        if ($lead?->status === LeadStatus::Qualified) {
            $score += 20;
            $reasons[] = 'qualified_lead';
        } elseif ($lead?->status === LeadStatus::Contacted) {
            $score += 10;
            $reasons[] = 'contacted_lead';
        }

        $score = min(100, $score);
        $classification = ($strongEnrollment || $enrollment) ? 'enrollment_intent' : ($commercial ? 'commercial_interest' : 'general_interest');
        $priority = $score >= 70 ? 'high' : ($score >= 40 ? 'medium' : 'low');

        return [
            'status' => 'open',
            'classification' => $classification,
            'priority' => $priority,
            'score' => $score,
            'reasons' => array_values(array_unique($reasons)),
            'context' => $this->context($inbound->count(), $outbound->count(), $lead, $enrollment, $commercial, $strongEnrollment),
        ];
    }

    private function suppressionReason(string $text, ?Contact $contact, ?Lead $lead): ?string
    {
        if ($contact?->unsubscribed_at !== null) {
            return 'contact_unsubscribed';
        }

        if ($lead !== null && in_array($lead->status, [LeadStatus::Enrolled, LeadStatus::Discarded], true)) {
            return $lead->status === LeadStatus::Enrolled ? 'lead_enrolled' : 'lead_discarded';
        }

        return $this->containsAny($text, self::OPT_OUT_TERMS) ? 'explicit_opt_out' : null;
    }

    /** @param array<int,string> $terms */
    private function containsAny(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            if (str_contains($text, Str::ascii($term))) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,int|bool|string|null> */
    private function context(int $inbound, int $outbound, ?Lead $lead, bool $enrollment, bool $commercial, bool $strongEnrollment = false): array
    {
        return [
            'inbound_messages' => $inbound,
            'outbound_messages' => $outbound,
            'has_lead' => $lead !== null,
            'lead_status' => $lead?->status->value,
            'enrollment_signal' => $enrollment,
            'commercial_signal' => $commercial,
            'strong_enrollment_signal' => $strongEnrollment,
            'corroborating_signal_count' => (int) $enrollment + (int) $commercial + (int) $strongEnrollment + (int) ($inbound >= 2) + (int) ($lead?->status === LeadStatus::Qualified),
        ];
    }
}
