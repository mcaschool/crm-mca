<?php

declare(strict_types=1);

namespace Modules\Crm\Services\Booking;

use App\Models\User;
use Modules\Core\Support\PhoneNumber;
use Modules\Crm\Enums\ActivityMedium;
use Modules\Crm\Livewire\Agenda\MeetingProfileForm;
use Modules\Crm\Models\AvailabilityRule;
use Modules\Crm\Models\MeetingProfile;
use Modules\Crm\Models\MeetingType;

/**
 * Reglas para publicar reservas: un tipo de reunión solo es público si su medio tiene el dato de
 * conexión correcto en el perfil del asesor (Meet/Zoom HTTPS de participante, WhatsApp/teléfono
 * internacional, dirección presencial) y el asesor tiene horario configurado.
 */
class BookingPublisher
{
    /** @return array<int,string> problemas que impiden recibir reservas con este perfil */
    public function profileProblems(MeetingProfile $profile): array
    {
        $problems = [];
        if (! $profile->exists || ! $profile->is_active) {
            $problems[] = __('El perfil de reuniones está inactivo.');
        }
        if (trim((string) $profile->booking_slug) === '') {
            $problems[] = __('Falta el alias público del enlace de reservas.');
        }
        if (! AvailabilityRule::query()->where('user_id', $profile->user_id)->exists()) {
            $problems[] = __('Configura tu horario en «Mi disponibilidad» para ofrecer huecos.');
        }

        return $problems;
    }

    /** @return array<int,string> problemas que impiden publicar este tipo de reunión */
    public function typeProblems(MeetingType $type, MeetingProfile $profile): array
    {
        if (! in_array($type->medium->value, MeetingType::PUBLIC_MEDIA, true)) {
            return [__('Este medio no admite reservas públicas.')];
        }
        $ok = match ($type->medium) {
            ActivityMedium::GoogleMeet => is_string($profile->meet_url) && MeetingProfileForm::validMeetUrl($profile->meet_url),
            ActivityMedium::Zoom => is_string($profile->zoom_url) && MeetingProfileForm::validZoomUrl($profile->zoom_url),
            ActivityMedium::WhatsApp => PhoneNumber::normalize($profile->whatsapp_number) !== null,
            ActivityMedium::Phone => PhoneNumber::normalize($profile->phone) !== null,
            ActivityMedium::InPerson => trim((string) $profile->address) !== '',
            default => false,
        };

        return $ok ? [] : [match ($type->medium) {
            ActivityMedium::GoogleMeet => __('Falta un enlace válido de Google Meet en el perfil de reuniones.'),
            ActivityMedium::Zoom => __('Falta un enlace válido de Zoom en el perfil de reuniones.'),
            ActivityMedium::WhatsApp => __('Falta el número de WhatsApp internacional en el perfil de reuniones.'),
            ActivityMedium::Phone => __('Falta el teléfono internacional en el perfil de reuniones.'),
            default => __('Falta la dirección presencial en el perfil de reuniones.'),
        }];
    }

    /** ¿El asesor puede recibir reservas (activo, operativo y con perfil publicable)? */
    public function advisorCanReceive(?User $advisor, MeetingProfile $profile): bool
    {
        return $advisor !== null && $advisor->isActive() && $advisor->canWorkCrm() && ! $advisor->crmReadOnly()
            && $profile->booking_enabled && $this->profileProblems($profile) === [];
    }

    /** ¿Este tipo se puede reservar ahora mismo desde la página pública? */
    public function isBookable(MeetingType $type, MeetingProfile $profile): bool
    {
        return $type->isBookable() && $type->user_id === $profile->user_id && $this->typeProblems($type, $profile) === [];
    }
}
