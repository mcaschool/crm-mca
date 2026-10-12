<?php

declare(strict_types=1);

namespace Modules\Crm\Livewire\Agenda;

use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Core\Support\PhoneNumber;
use Modules\Crm\Enums\ActivityMedium;
use Modules\Crm\Livewire\Agenda\Concerns\ResolvesAgendaOwner;
use Modules\Crm\Models\MeetingProfile;
use Modules\Crm\Policies\ActivityPolicy;

/**
 * Perfil de reuniones del asesor (por institución y usuario): zona horaria, medio por
 * defecto, enlace PERMANENTE de participante de Meet y de Zoom (HTTPS; nunca enlaces de
 * anfitrión), WhatsApp y teléfono en formato internacional, dirección presencial y estado.
 * Enlaces y números se guardan cifrados. Cambiarlo NO altera las actividades ya creadas
 * (cada una guarda su propia foto del enlace).
 */
#[Layout('layouts.app')]
class MeetingProfileForm extends Component
{
    use ResolvesAgendaOwner;

    public string $timezone = 'UTC';

    public string $defaultMedium = 'google_meet';

    public string $meetUrl = '';

    public string $zoomUrl = '';

    public string $whatsappNumber = '';

    public string $phone = '';

    public string $address = '';

    public bool $isActive = true;

    public bool $saved = false;

    public function mount(): void
    {
        $this->resolveOwner();
        $p = MeetingProfile::forUser($this->ownerId);
        $this->fill([
            'timezone' => $p->timezone,
            'defaultMedium' => $p->default_medium->value,
            'meetUrl' => (string) $p->meet_url,
            'zoomUrl' => (string) $p->zoom_url,
            'whatsappNumber' => (string) $p->whatsapp_number,
            'phone' => (string) $p->phone,
            'address' => (string) $p->address,
            'isActive' => (bool) $p->is_active,
        ]);
    }

    public function save(): void
    {
        $this->guardOwner();
        $this->meetUrl = trim($this->meetUrl);
        $this->zoomUrl = trim($this->zoomUrl);
        $this->validate([
            'timezone' => ['required', 'timezone:all'],
            'defaultMedium' => ['required', Rule::in(ActivityMedium::values())],
            'meetUrl' => ['nullable', 'string', 'max:500', function (string $attr, mixed $value, \Closure $fail): void {
                if ($value !== '' && ! self::validMeetUrl((string) $value)) {
                    $fail(__('Debe ser un enlace HTTPS de participante de Google Meet (https://meet.google.com/…).'));
                }
            }],
            'zoomUrl' => ['nullable', 'string', 'max:500', function (string $attr, mixed $value, \Closure $fail): void {
                if ($value !== '' && ! self::validZoomUrl((string) $value)) {
                    $fail(__('Debe ser un enlace HTTPS de participante de Zoom (…zoom.us/j/…). No uses el enlace de anfitrión.'));
                }
            }],
            'whatsappNumber' => ['nullable', 'string', 'max:30', function (string $attr, mixed $value, \Closure $fail): void {
                if ($value !== '' && PhoneNumber::normalize((string) $value) === null) {
                    $fail(__('Usa el formato internacional con prefijo de país, por ejemplo +34 600 000 000.'));
                }
            }],
            'phone' => ['nullable', 'string', 'max:30', function (string $attr, mixed $value, \Closure $fail): void {
                if ($value !== '' && PhoneNumber::normalize((string) $value) === null) {
                    $fail(__('Usa el formato internacional con prefijo de país, por ejemplo +34 900 000 000.'));
                }
            }],
            'address' => ['nullable', 'string', 'max:255'],
            'isActive' => ['boolean'],
        ]);

        $profile = MeetingProfile::query()->firstOrNew(['user_id' => $this->ownerId]);
        $profile->fill([
            'timezone' => $this->timezone,
            'default_medium' => $this->defaultMedium,
            'meet_url' => $this->meetUrl !== '' ? $this->meetUrl : null,
            'zoom_url' => $this->zoomUrl !== '' ? $this->zoomUrl : null,
            'whatsapp_number' => $this->whatsappNumber !== '' ? PhoneNumber::normalize($this->whatsappNumber) : null,
            'phone' => $this->phone !== '' ? PhoneNumber::normalize($this->phone) : null,
            'address' => trim($this->address) !== '' ? trim($this->address) : null,
            'is_active' => $this->isActive,
        ])->save();

        $this->whatsappNumber = (string) $profile->whatsapp_number;
        $this->phone = (string) $profile->phone;
        $this->saved = true;
    }

    public static function validMeetUrl(string $url): bool
    {
        $parts = parse_url($url);

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && ($parts['scheme'] ?? '') === 'https'
            && strtolower($parts['host'] ?? '') === 'meet.google.com'
            && preg_match('#^/[a-z0-9-]{3,}#i', $parts['path'] ?? '') === 1;
    }

    /** Solo enlaces de participante (/j/ o /my/): nunca de anfitrión (/s/ o con «zak=»). */
    public static function validZoomUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && ($parts['scheme'] ?? '') === 'https'
            && ($host === 'zoom.us' || str_ends_with($host, '.zoom.us') || $host === 'zoom.com' || str_ends_with($host, '.zoom.com'))
            && preg_match('#^/(j|my|w)/#', $path) === 1
            && ! str_contains(strtolower($parts['query'] ?? ''), 'zak=');
    }

    public function render(): View
    {
        $actor = auth()->user();

        return view('crm::livewire.agenda.profile', [
            'media' => ActivityMedium::cases(),
            'timezones' => DateTimeZone::listIdentifiers(),
            'canPickUser' => (new ActivityPolicy)->manageOthers($actor),
            'users' => (new ActivityPolicy)->manageOthers($actor) ? $this->assignable() : collect(),
            'ownerName' => $this->assignable()->firstWhere('id', $this->ownerId)->name ?? $actor->name,
        ]);
    }
}
