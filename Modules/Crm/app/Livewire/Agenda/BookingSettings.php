<?php

declare(strict_types=1);

namespace Modules\Crm\Livewire\Agenda;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Catalog\Models\Program;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Enums\ActivityMedium;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\MeetingProfile;
use Modules\Crm\Models\MeetingType;
use Modules\Crm\Policies\ActivityPolicy;
use Modules\Crm\Services\Agenda\AgendaDirectory;
use Modules\Crm\Services\Booking\BookingPublisher;
use Modules\Institutions\Models\Institution;

/**
 * «Mi enlace de reservas» y «Tipos de reunión», dentro de Calendario › Perfil de reuniones. Cada
 * usuario operativo gestiona los suyos; la administración, los de su institución. Un tipo solo se
 * publica si su medio tiene el dato de conexión en el perfil; las reservas, si hay horario.
 */
class BookingSettings extends Component
{
    #[Locked]
    public int $ownerId;

    public bool $bookingEnabled = false;

    public string $bookingSlug = '';

    public ?int $editingId = null;

    public bool $showTypeForm = false;

    public string $name = '';

    public string $slug = '';

    public string $description = '';

    public int $duration = 30;

    public string $medium = 'google_meet';

    public string $instructions = '';

    public ?int $programId = null;

    public bool $typeActive = true;

    public bool $typePublic = true;

    public string $notice = '';

    public function mount(int $ownerId): void
    {
        $this->ownerId = $ownerId;
        $this->guard();
        $p = MeetingProfile::forUser($ownerId);
        $this->bookingEnabled = (bool) $p->booking_enabled;
        $this->bookingSlug = (string) ($p->booking_slug ?: Str::slug((string) (\App\Models\User::query()->find($ownerId)->name ?? 'asesor')));
    }

    public function saveLink(BookingPublisher $publisher): void
    {
        $this->guard();
        $this->bookingSlug = Str::slug($this->bookingSlug);
        $this->validate(['bookingSlug' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/',
            Rule::unique('crm_meeting_profiles', 'booking_slug')->where('institution_id', $this->institutionId())->ignore($this->ownerId, 'user_id')]]);

        $profile = MeetingProfile::query()->firstOrNew(['user_id' => $this->ownerId]);
        if (! $profile->exists) {
            $profile->timezone = (string) config('crm.agenda.default_timezone', 'UTC');
        }
        $profile->booking_slug = $this->bookingSlug;
        if ($this->bookingEnabled && ($problems = $publisher->profileProblems($profile)) !== []) {
            throw ValidationException::withMessages(['bookingEnabled' => implode(' ', $problems)]);
        }
        $profile->booking_enabled = $this->bookingEnabled;
        $profile->save();
        $this->notice = $this->bookingEnabled ? __('Reservas públicas activadas.') : __('Enlace guardado. Las reservas públicas están desactivadas.');
    }

    public function newType(): void
    {
        $this->guard();
        $this->resetType();
        $profile = MeetingProfile::forUser($this->ownerId);
        $this->medium = in_array($profile->default_medium->value, MeetingType::PUBLIC_MEDIA, true) ? $profile->default_medium->value : 'google_meet';
        $this->duration = $profile->default_duration_minutes;
        $this->showTypeForm = true;
    }

    public function editType(int $id): void
    {
        $this->guard();
        $t = MeetingType::query()->where('user_id', $this->ownerId)->findOrFail($id);
        $this->fill([
            'editingId' => $t->id, 'name' => $t->name, 'slug' => $t->slug, 'description' => (string) $t->description,
            'duration' => $t->duration_minutes, 'medium' => $t->medium->value, 'instructions' => (string) $t->instructions,
            'programId' => $t->program_id, 'typeActive' => $t->is_active, 'typePublic' => $t->is_public,
        ]);
        $this->showTypeForm = true;
    }

    public function saveType(BookingPublisher $publisher): void
    {
        $this->guard();
        $this->slug = Str::slug($this->slug !== '' ? $this->slug : $this->name);
        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('crm_meeting_types', 'slug')->where('institution_id', $this->institutionId())->where('user_id', $this->ownerId)->ignore($this->editingId)],
            'description' => ['nullable', 'string', 'max:2000'],
            'duration' => ['required', 'integer', 'min:10', 'max:240'],
            'medium' => ['required', Rule::in(MeetingType::PUBLIC_MEDIA)],
            'instructions' => ['nullable', 'string', 'max:500'],
            'programId' => ['nullable', 'integer'],
        ]);
        if ($this->programId !== null && ! Program::query()->whereKey($this->programId)->exists()) {
            throw ValidationException::withMessages(['programId' => __('Programa no válido.')]);
        }

        $type = $this->editingId ? MeetingType::query()->where('user_id', $this->ownerId)->findOrFail($this->editingId) : new MeetingType(['user_id' => $this->ownerId]);
        $type->fill([
            'name' => trim($this->name), 'slug' => $this->slug, 'description' => trim($this->description) ?: null,
            'duration_minutes' => $this->duration, 'medium' => $this->medium, 'instructions' => trim($this->instructions) ?: null,
            'program_id' => $this->programId, 'is_active' => $this->typeActive, 'is_public' => $this->typePublic,
        ]);
        if ($type->is_public && $type->is_active && ($problems = $publisher->typeProblems($type, MeetingProfile::forUser($this->ownerId))) !== []) {
            throw ValidationException::withMessages(['typePublic' => implode(' ', $problems).' '.__('Guárdalo como no público o completa el perfil de reuniones.')]);
        }
        $type->save();
        $this->resetType();
        $this->notice = __('Tipo de reunión guardado.');
    }

    public function toggleType(int $id, string $field, BookingPublisher $publisher): void
    {
        $this->guard();
        $type = MeetingType::query()->where('user_id', $this->ownerId)->findOrFail($id);
        if ($field === 'is_public' || $field === 'is_active') {
            $type->{$field} = ! $type->{$field};
            if ($type->is_public && $type->is_active && ($problems = $publisher->typeProblems($type, MeetingProfile::forUser($this->ownerId))) !== []) {
                $this->notice = implode(' ', $problems);

                return;
            }
            $type->save();
        }
    }

    public function deleteType(int $id): void
    {
        $this->guard();
        MeetingType::query()->where('user_id', $this->ownerId)->whereKey($id)->delete();
        $this->resetType();
        $this->notice = __('Tipo de reunión eliminado. Las reservas existentes se conservan.');
    }

    public function cancelType(): void
    {
        $this->resetType();
    }

    /** El perfil de reuniones se guardó arriba: se recalculan los avisos de publicación. */
    #[On('meeting-profile-saved')]
    public function refreshAfterProfile(): void {}

    public function render(BookingPublisher $publisher): View
    {
        $profile = MeetingProfile::forUser($this->ownerId);
        $institution = Institution::query()->find($this->institutionId());
        $types = MeetingType::query()->where('user_id', $this->ownerId)->orderBy('sort')->orderBy('name')->get();
        $base = $institution && $profile->booking_slug ? route('booking.advisor', [$institution->getAttribute('slug'), $profile->booking_slug]) : null;

        return view('crm::livewire.agenda.booking-settings', [
            'profile' => $profile,
            'publicUrl' => $base,
            'typeUrl' => fn (MeetingType $t) => $institution && $profile->booking_slug ? route('booking.type', [$institution->getAttribute('slug'), $profile->booking_slug, $t->slug]) : null,
            'types' => $types,
            'typeProblems' => $types->mapWithKeys(fn (MeetingType $t) => [$t->id => $publisher->typeProblems($t, $profile)]),
            'profileProblems' => $publisher->profileProblems($profile),
            'media' => array_map(fn (string $m) => ActivityMedium::from($m), MeetingType::PUBLIC_MEDIA),
            'programs' => Program::query()->where('status', 'active')->orderBy('id')->get(['id', 'name_es', 'name_en']),
            'bookingsCount' => Activity::query()->where('assigned_to_user_id', $this->ownerId)->whereHas('booking')->where('status', 'scheduled')->where('starts_at', '>=', now())->count(),
        ]);
    }

    private function guard(): void
    {
        $actor = auth()->user();
        abort_unless($actor !== null && $actor->can('create', Activity::class), 403);
        if ($this->ownerId !== $actor->id) {
            abort_unless((new ActivityPolicy)->manageOthers($actor), 403);
            abort_unless(app(AgendaDirectory::class)->assignableUsers()->contains('id', $this->ownerId), 404);
        }
    }

    private function institutionId(): int
    {
        return app(CurrentInstitution::class)->idOrFail('reservas');
    }

    private function resetType(): void
    {
        $this->reset(['editingId', 'name', 'slug', 'description', 'instructions', 'programId']);
        $this->duration = 30;
        $this->medium = 'google_meet';
        $this->typeActive = true;
        $this->typePublic = true;
        $this->showTypeForm = false;
        $this->resetErrorBag();
    }
}
