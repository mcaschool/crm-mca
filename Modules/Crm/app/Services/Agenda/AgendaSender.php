<?php

declare(strict_types=1);

namespace Modules\Crm\Services\Agenda;

use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Notifications\Models\EmailSender;

/**
 * Remitente de los correos de la Agenda de la institución ACTIVA, sin adivinar:
 *  1) el marcado explícitamente como «Agenda» en Ajustes › Remitentes (si está activo);
 *  2) si no hay marca, el remitente solo cuando es el ÚNICO activo de la institución.
 * En cualquier otro caso no hay remitente (el envío se registra como fallido y puede
 * reintentarse). Nunca usa un remitente de otra institución ni el correo del sistema.
 */
class AgendaSender
{
    private ?string $problem = null;

    public function __construct(private readonly CurrentInstitution $context) {}

    public function resolve(): ?EmailSender
    {
        $this->problem = null;
        $institutionId = $this->context->idOrFail('agenda');
        $active = fn () => EmailSender::query()->where('institution_id', $institutionId)->where('status', 'active');

        $sender = $active()->where('agenda_default', true)->first();
        if ($sender === null) {
            if (EmailSender::query()->where('institution_id', $institutionId)->where('agenda_default', true)->exists()) {
                return $this->fail('El remitente marcado para el Calendario está inactivo.');
            }
            $candidates = $active()->limit(2)->get();
            if ($candidates->count() !== 1) {
                return $this->fail($candidates->isEmpty()
                    ? 'La institución no tiene ningún remitente activo.'
                    : 'Hay varios remitentes activos y ninguno está marcado para el Calendario.');
            }
            $sender = $candidates->first();
        }

        if (trim((string) $sender->from_address) === ''
            || (string) $sender->secret('host', '') === ''
            || (string) $sender->secret('username', '') === ''
            || (string) $sender->secret('password', '') === '') {
            return $this->fail('El remitente del Calendario no tiene la configuración SMTP completa.');
        }

        return $sender;
    }

    /** Motivo interno del último resolve() sin remitente (para registro, nunca para invitados). */
    public function problem(): ?string
    {
        return $this->problem;
    }

    private function fail(string $reason): null
    {
        $this->problem = $reason;

        return null;
    }
}
