<?php

declare(strict_types=1);

namespace Modules\Crm\Livewire\Leads;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Audit\Services\AuditService;
use Modules\Crm\Enums\LeadStatus;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Models\Event;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\LeadNote;
use Modules\Crm\Services\LeadService;
use Modules\Institutions\Models\Bot;

/**
 * Ficha de un lead: conversacion completa, datos personales (telefono completo con
 * codigo de pais; su acceso queda AUDITADO al abrir la ficha), resultado del
 * emparejador, eventos, transferencia de seguimiento, notas internas y bloque
 * Moodle dormido. 'enrolled' es terminal (LeadService lo impide). Nunca borra la
 * conversacion ni el lead. Acciones gated por LeadPolicy (Marketing solo lectura;
 * Soporte solo sus referidos).
 */
#[Layout('layouts.app')]
class Show extends Component
{
    // Se guarda el ID (no el modelo) para no serializar el Eloquent en cada
    // round-trip de Livewire; el lead se recarga al usarlo (siempre bajo scope).
    public int $leadId;

    public string $status = '';

    /** Cuerpo de la nueva nota interna. */
    public string $newNote = '';

    /** Destino seleccionado en el desplegable de transferencia (ia:/user:/dept:). */
    public string $transferTarget = '';

    public bool $statusMenuOpen = false;

    public function mount(Lead $lead, AuditService $audit): void
    {
        $this->authorize('view', $lead);
        $this->leadId = $lead->getKey();
        $this->status = $lead->status->value;
        $this->transferTarget = $this->currentAssignmentValue($lead);

        // Abrir la ficha = acceder a los datos personales del contacto (telefono
        // visible). Se AUDITA una vez por apertura (quien/cuando); nunca se guarda
        // el valor del dato, solo que se accedio.
        $lead->loadMissing('contact');
        if ($lead->contact !== null) {
            $audit->log('contact.personal_data_viewed', $lead->contact, [
                'fields' => ['phone', 'email', 'country'],
                'lead_id' => $lead->getKey(),
            ]);
        }
    }

    private function lead(): Lead
    {
        return Lead::query()->findOrFail($this->leadId);
    }

    /** Valor del option que representa la asignacion actual del lead. */
    private function currentAssignmentValue(Lead $lead): string
    {
        if ($lead->assigned_to_user_id !== null) {
            return 'user:'.$lead->assigned_to_user_id;
        }
        if ($lead->assigned_to_department !== null && $lead->assigned_to_department !== '') {
            return 'dept:'.$lead->assigned_to_department;
        }

        return 'ia:'.$lead->bot_id;
    }

    public function changeStatus(LeadService $service, string $status): void
    {
        $lead = $this->lead();
        $this->authorize('update', $lead);
        $this->statusMenuOpen = false;

        try {
            $service->changeStatus($lead, LeadStatus::from($status));
            $this->status = $status;
            session()->flash('status', __('Estado actualizado.'));
        } catch (InvalidArgumentException $e) {
            $this->status = $lead->status->value;
            $this->addError('status', $e->getMessage());
        }
    }

    /**
     * Exporta la ficha de este lead a CSV (mismos campos que la lista). No incluye
     * el telefono (dato personal sensible; se revela auditado en pantalla).
     */
    public function exportOne(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $lead = $this->lead()->load(['contact', 'bot']);
        $this->authorize('view', $lead);

        $hasCorporate = Event::query()
            ->where('event_type', 'corporate_interest')
            ->where('contact_id', $lead->contact_id)
            ->exists();

        $filename = 'lead_'.$lead->getKey().'_'.now()->format('Y-m-d').'.csv';

        return \Illuminate\Support\Facades\Response::streamDownload(function () use ($lead, $hasCorporate): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Nombre', 'Correo', 'Asesor de origen', 'Estado', 'Meta', 'Area', 'Empresa', 'Fecha']);
            fputcsv($out, [
                trim(($lead->contact->first_name ?? '').' '.($lead->contact->last_name ?? '')),
                $lead->contact->email ?? '',
                $lead->bot->assistant_name ?? '',
                $lead->status->label(),
                $lead->goal ?? '',
                $lead->area ?? '',
                $hasCorporate ? 'Si' : '',
                $lead->created_at->format('Y-m-d'),
            ]);
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function addNote(): void
    {
        $lead = $this->lead();
        $this->authorize('update', $lead);

        $body = trim($this->newNote);
        if ($body === '') {
            return;
        }

        $user = auth()->user();
        LeadNote::create([
            'lead_id' => $lead->getKey(),
            'user_id' => $user?->getKey(),
            'author_name' => $user?->name,
            'body' => $body,
        ]);

        $this->newNote = '';
        session()->flash('status', __('Nota añadida.'));
    }

    public function transfer(LeadService $service): void
    {
        $lead = $this->lead();
        $this->authorize('update', $lead);

        if ($this->transferTarget === '') {
            return;
        }

        $label = $this->transferOptions($lead)[$this->transferTarget] ?? $this->transferTarget;
        $actor = auth()->user();

        try {
            $service->transfer($lead, $this->transferTarget, $label, $actor?->name, $actor?->department);
            session()->flash('status', __('Seguimiento transferido a :dest.', ['dest' => $label]));
        } catch (InvalidArgumentException $e) {
            $this->addError('transferTarget', $e->getMessage());
        }
    }

    /**
     * Opciones de transferencia: asesores IA (bots), asesores humanos (usuarios
     * del equipo, de cualquier categoria) y departamentos. Mapa value => etiqueta.
     *
     * @return array<string, string>
     */
    private function transferOptions(Lead $lead): array
    {
        $options = [];

        foreach (Bot::query()->where('type', 'ia')->where('status', 'active')->orderBy('assistant_name')->get() as $bot) {
            $options['ia:'.$bot->getKey()] = $bot->assistant_name.' (IA)';
        }

        $users = \App\Models\User::query()
            ->where('institution_id', $lead->institution_id)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'department']);

        foreach ($users as $user) {
            $dept = $user->department !== null && $user->department !== '' ? ' · '.$user->department : '';
            $options['user:'.$user->getKey()] = $user->name.$dept;
        }

        foreach ((array) config('crm.departments', []) as $dept) {
            $options['dept:'.$dept] = 'Dept. '.$dept;
        }

        return $options;
    }

    /**
     * Mensajes de la conversacion mas reciente del contacto con este asesor.
     *
     * @return Collection<int, \Modules\Crm\Models\Message>
     */
    private function conversationMessages(Lead $lead): Collection
    {
        $conversation = Conversation::query()
            ->where('contact_id', $lead->contact_id)
            ->where('bot_id', $lead->bot_id)
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->first();

        if ($conversation === null) {
            return collect();
        }

        return $conversation->messages()->orderBy('created_at')->orderBy('id')->get();
    }

    /**
     * Eventos del contacto (mas recientes primero). "Todo comportamiento deja
     * rastro"; incluye interes corporativo y demas ya registrados por el widget.
     *
     * @return Collection<int, Event>
     */
    private function events(Lead $lead): Collection
    {
        return Event::query()
            ->where('contact_id', $lead->contact_id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(12)
            ->get();
    }

    public function render(): View
    {
        $lead = $this->lead()->load(['contact', 'program', 'bot', 'leadNotes']);

        // Motivacion: viaja en el evento del emparejador (no es columna del lead).
        $matcherEvent = Event::query()
            ->where('contact_id', $lead->contact_id)
            ->where('event_type', 'used_matcher')
            ->orderByDesc('created_at')
            ->first();
        $motivacion = is_array($matcherEvent?->event_data) ? ($matcherEvent->event_data['motivacion'] ?? null) : null;

        return view('crm::livewire.leads.show', [
            'lead' => $lead,
            'messages' => $this->conversationMessages($lead),
            'events' => $this->events($lead),
            'statuses' => LeadStatus::cases(),
            'transferOptions' => $this->transferOptions($lead),
            'motivacion' => is_string($motivacion) ? $motivacion : null,
            'isTerminal' => $lead->status->isTerminal(),
            'phoneDisplay' => $this->formatPhone($lead->contact?->phone),
            'canAct' => auth()->user()?->can('update', $lead) ?? false,
        ]);
    }

    /**
     * Telefono legible con codigo de pais. Se muestra el numero COMPLETO (requisito:
     * codigo de pais + numero). Para el caso comun +1 (NANP) agrupa "+1 305 555 4821";
     * en otros casos devuelve el numero tal como se capturo (ya trae codigo de pais).
     */
    private function formatPhone(?string $phone): ?string
    {
        $phone = $phone !== null ? trim($phone) : '';
        if ($phone === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone) ?? '';

        // NANP (+1 y 10 digitos nacionales): "+1 305 555 4821".
        if (str_starts_with($phone, '+') && strlen($digits) === 11 && $digits[0] === '1') {
            return '+1 '.substr($digits, 1, 3).' '.substr($digits, 4, 3).' '.substr($digits, 7);
        }

        return $phone;
    }
}
