<?php

declare(strict_types=1);

namespace Modules\Crm\Livewire\Agenda;

use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Crm\Models\CalendarTemplate;
use Modules\Crm\Services\Booking\CalendarTemplates;

/**
 * Configuración › Plantillas de Calendario: asunto y cuerpo (texto plano con variables autorizadas)
 * de los correos de reservas, por institución e idioma, con vista previa y restauración del texto
 * predeterminado. Solo administración. No es un editor de campañas ni admite HTML.
 */
#[Layout('layouts.app')]
class CalendarTemplatesManage extends Component
{
    public string $key = 'booking_confirmation';

    public string $locale = 'es';

    public string $subject = '';

    public string $body = '';

    public bool $custom = false;

    public string $notice = '';

    public function mount(CalendarTemplates $templates): void
    {
        $this->guard();
        $this->load($templates);
    }

    public function updatedKey(CalendarTemplates $templates): void
    {
        $this->load($templates);
    }

    public function updatedLocale(CalendarTemplates $templates): void
    {
        $this->load($templates);
    }

    public function save(CalendarTemplates $templates): void
    {
        $this->guard();
        $this->validate([
            'key' => ['required', Rule::in(CalendarTemplates::KEYS)],
            'locale' => ['required', 'in:es,en'],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
        ]);
        $unknown = array_merge($templates->unknownVariables($this->subject), $templates->unknownVariables($this->body));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['body' => __('Variables no permitidas: :list', ['list' => implode(', ', array_map(fn ($v) => '{{'.$v.'}}', array_unique($unknown)))])]);
        }

        CalendarTemplate::query()->updateOrCreate(
            ['key' => $this->key, 'locale' => $this->locale],
            ['subject' => $this->subject, 'body' => $this->body, 'updated_by_user_id' => auth()->id()],
        );
        $this->custom = true;
        $this->notice = __('Plantilla guardada.');
    }

    public function restore(CalendarTemplates $templates): void
    {
        $this->guard();
        CalendarTemplate::query()->where('key', $this->key)->where('locale', $this->locale)->delete();
        $this->load($templates);
        $this->notice = __('Se restauró el texto predeterminado.');
    }

    public function render(CalendarTemplates $templates): View
    {
        $sample = $templates->sample($this->locale);

        return view('crm::livewire.agenda.calendar-templates', [
            'keys' => CalendarTemplates::KEYS,
            'variables' => CalendarTemplates::VARIABLES,
            'previewSubject' => $templates->renderSubject($this->subject, $sample),
            'previewHtml' => $templates->renderBody($this->body, $sample),
            'unknown' => array_unique(array_merge($templates->unknownVariables($this->subject), $templates->unknownVariables($this->body))),
        ]);
    }

    private function load(CalendarTemplates $templates): void
    {
        if (! in_array($this->key, CalendarTemplates::KEYS, true)) {
            $this->key = 'booking_confirmation';
        }
        $this->locale = $this->locale === 'en' ? 'en' : 'es';
        $tpl = $templates->get($this->key, $this->locale);
        $this->subject = $tpl['subject'];
        $this->body = $tpl['body'];
        $this->custom = $tpl['custom'];
        $this->notice = '';
        $this->resetErrorBag();
    }

    private function guard(): void
    {
        abort_unless(auth()->user()?->canManageSettings(), 403);
    }
}
