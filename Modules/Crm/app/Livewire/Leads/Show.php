<?php

declare(strict_types=1);

namespace Modules\Crm\Livewire\Leads;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Audit\Services\AuditService;
use Modules\Crm\Enums\LeadStatus;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Models\Event;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\LeadNote;
use Modules\Crm\Services\LeadService;
use Modules\Institutions\Models\Bot;
use Modules\Notifications\Models\EmailAttachment;
use Modules\Notifications\Models\EmailMessage;
use Modules\Notifications\Models\EmailSender;
use Modules\Notifications\Services\EmailDispatcher;
use Modules\Notifications\Support\AttachmentValidator;
use Modules\Notifications\Support\SentEmailRenderer;

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
    use WithFileUploads;

    // Se guarda el ID (no el modelo) para no serializar el Eloquent en cada
    // round-trip de Livewire; el lead se recarga al usarlo (siempre bajo scope).
    public int $leadId;

    public string $status = '';

    /** Cuerpo de la nueva nota interna. */
    public string $newNote = '';

    /** Destino seleccionado en el desplegable de transferencia (ia:/user:/dept:). */
    public string $transferTarget = '';

    public bool $statusMenuOpen = false;

    // --- Enviar correo desde la ficha ---
    public bool $composeOpen = false;

    /** Remitente ELEGIDO manualmente (id de email_senders). El sistema no elige solo. */
    public string $emailSenderId = '';

    public string $emailSubject = '';

    /** HTML del editor (contenteditable). Se SANITIZA en el servidor al enviar. */
    public string $emailBody = '';

    /**
     * Adjuntos en composición (archivos temporales de Livewire). Se validan en el
     * SERVIDOR al enviar (tamaño/total/tipo), no solo en el navegador.
     *
     * @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile>
     */
    public array $emailAttachments = [];

    /** Subida puntual de una imagen inline (se procesa y se limpia enseguida). */
    public mixed $inlineUpload = null;

    /**
     * Imágenes inline en composición (se embeben por CID al enviar) y sus cid.
     *
     * @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile>
     */
    public array $inlineImages = [];

    /** @var array<int, string> cid de cada imagen inline (mismo índice que inlineImages) */
    public array $inlineCids = [];

    /** Correo enviado que se está viendo en detalle (id de email_messages) o null. */
    public ?int $viewingEmailId = null;

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

            // Abrir la ficha apaga la senal de "volvio a contactar" de este contacto.
            $lead->contact->markRecontactSeen();
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

    /** Abre el compositor de correo (permiso de envio: todos menos Marketing). */
    public function openCompose(): void
    {
        abort_unless(auth()->user()?->canSendEmail() ?? false, 403);

        // Remitente por defecto: el primero activo. El usuario puede cambiarlo; el
        // sistema NUNCA elige solo (siempre es una seleccion manual explicita).
        $this->emailSenderId = (string) (EmailSender::query()->where('status', 'active')->orderBy('name')->value('id') ?? '');
        $this->emailSubject = '';
        $this->emailBody = '';
        $this->emailAttachments = [];
        $this->inlineImages = [];
        $this->inlineCids = [];
        $this->inlineUpload = null;
        $this->resetErrorBag(['emailSenderId', 'emailSubject', 'emailBody', 'emailAttachments', 'inlineUpload']);
        $this->composeOpen = true;
    }

    /** Quita un adjunto en composición. */
    public function removeAttachment(int $index): void
    {
        unset($this->emailAttachments[$index]);
        $this->emailAttachments = array_values($this->emailAttachments);
    }

    /**
     * Al subir una imagen inline: se VALIDA en el servidor (imagen real + tamaño) y,
     * si pasa, se inserta en el editor con su marca data-cid (se embeberá por CID al
     * enviar). El navegador no es la barrera: la validación es server-side.
     */
    public function updatedInlineUpload(AttachmentValidator $validator): void
    {
        $file = $this->inlineUpload;
        if (! $file instanceof TemporaryUploadedFile) {
            return;
        }

        $error = $validator->imageError($file);
        if ($error !== null) {
            $this->addError('inlineUpload', $error);
            $this->inlineUpload = null;

            return;
        }

        $cid = 'img'.Str::random(20);
        $this->inlineImages[] = $file;
        $this->inlineCids[] = $cid;

        // Inserta la imagen en el editor (preview con URL temporal + marca data-cid).
        $this->dispatch('insert-inline-image', url: $file->temporaryUrl(), cid: $cid);

        $this->inlineUpload = null;
        $this->resetErrorBag('inlineUpload');
    }

    /** Envia el correo por el SMTP del remitente ELEGIDO y lo registra en el historial. */
    public function sendEmail(EmailDispatcher $dispatcher, AttachmentValidator $attachments): void
    {
        $lead = $this->lead()->load('contact');
        abort_unless(auth()->user()?->canSendEmail() ?? false, 403);

        $this->validate([
            'emailSenderId' => [
                'required',
                function (string $attr, mixed $value, callable $fail): void {
                    $ok = EmailSender::query()->where('id', $value)->where('status', 'active')->exists();
                    if (! $ok) {
                        $fail('Elige un remitente válido.');
                    }
                },
            ],
            'emailSubject' => ['required', 'string', 'max:200'],
            'emailBody' => ['required', 'string', 'max:100000'],
        ], [], [
            'emailSenderId' => 'remitente',
            'emailSubject' => 'asunto',
            'emailBody' => 'cuerpo',
        ]);

        // El cuerpo debe tener contenido real (no solo etiquetas vacías del editor).
        if (trim(strip_tags($this->emailBody)) === '') {
            $this->addError('emailBody', 'Escribe el mensaje.');

            return;
        }

        // Validación de ADJUNTOS en el SERVIDOR (tamaño/total/tipo): no se puede
        // saltar el límite manipulando el navegador.
        $attachErrors = $attachments->validate($this->emailAttachments);
        if ($attachErrors !== []) {
            $this->addError('emailAttachments', implode(' ', $attachErrors));

            return;
        }

        $contact = $lead->contact;
        if ($contact === null || (string) $contact->email === '') {
            $this->addError('emailSubject', 'Este contacto no tiene correo.');

            return;
        }

        $sender = EmailSender::query()->findOrFail((int) $this->emailSenderId);

        // Descriptores para el envío + el historial (nombre/tipo/tamaño reales).
        $files = array_map(fn ($file): array => [
            'path' => (string) $file->getRealPath(),
            'name' => $file->getClientOriginalName(),
            'mime' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
        ], $this->emailAttachments);

        // Imágenes inline: cid => imagen. El embebedor solo usará las referenciadas
        // en el cuerpo (las que el usuario dejó en el editor).
        $inline = [];
        foreach ($this->inlineImages as $i => $file) {
            $cid = $this->inlineCids[$i] ?? null;
            if ($cid !== null) {
                $inline[$cid] = [
                    'path' => (string) $file->getRealPath(),
                    'mime' => (string) $file->getMimeType(),
                    'size' => (int) $file->getSize(),
                ];
            }
        }

        $message = $dispatcher->send($sender, $contact, $lead->getKey(), auth()->user(), $this->emailSubject, $this->emailBody, $files, $inline);

        if ($message->status === 'sent') {
            $this->composeOpen = false;
            $this->reset(['emailSubject', 'emailBody', 'emailAttachments', 'inlineImages', 'inlineCids', 'inlineUpload']);
            session()->flash('status', 'Correo enviado a '.$contact->email.' como '.$sender->from_address.'.');
        } else {
            // El intento fallido queda en el historial; se avisa sin romper la ficha.
            $this->addError('emailBody', 'No se pudo enviar (revisa el SMTP del remitente). El intento quedó registrado en el historial.');
        }
    }

    /**
     * Historial de correos enviados a este contacto (mas recientes primero).
     *
     * @return Collection<int, EmailMessage>
     */
    private function emailHistory(Lead $lead): Collection
    {
        return EmailMessage::query()
            ->where('contact_id', $lead->contact_id)
            ->with('sentByUser:id,name')
            ->withCount('files')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get();
    }

    /** Abre un correo enviado para verlo tal como se envió (solo del contacto actual). */
    public function openSentEmail(int $id): void
    {
        $exists = EmailMessage::query()
            ->where('id', $id)
            ->where('contact_id', $this->lead()->contact_id)
            ->exists();

        $this->viewingEmailId = $exists ? $id : null;
    }

    public function closeSentEmail(): void
    {
        $this->viewingEmailId = null;
    }

    /** Descarga un adjunto de un correo del contacto actual (gated + con scope). */
    public function downloadAttachment(int $id): mixed
    {
        abort_unless(auth()->user()?->canWorkCrm() ?? false, 403);

        $contactId = $this->lead()->contact_id;
        $attachment = EmailAttachment::query()
            ->where('id', $id)
            ->whereHas('message', fn ($q) => $q->where('contact_id', $contactId))
            ->firstOrFail();

        abort_if($attachment->path === null || ! \Illuminate\Support\Facades\Storage::disk('local')->exists($attachment->path), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->download($attachment->path, $attachment->filename);
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

        // Detalle de un correo enviado (para "abrirlo y verlo tal como se envió").
        $viewingEmail = null;
        $viewingBody = null;
        if ($this->viewingEmailId !== null) {
            $viewingEmail = EmailMessage::query()
                ->where('id', $this->viewingEmailId)
                ->where('contact_id', $lead->contact_id)
                ->with(['sentByUser:id,name', 'files', 'inlineImages'])
                ->first();
            if ($viewingEmail !== null) {
                $viewingBody = app(SentEmailRenderer::class)->displayBody($viewingEmail);
            }
        }

        return view('crm::livewire.leads.show', [
            'lead' => $lead,
            'messages' => $this->conversationMessages($lead),
            'events' => $this->events($lead),
            'emails' => $this->emailHistory($lead),
            'viewingEmail' => $viewingEmail,
            'viewingBody' => $viewingBody,
            'senders' => EmailSender::query()->where('status', 'active')->orderBy('name')->get(),
            'emailTags' => $lead->contact !== null
                ? app(\Modules\Notifications\Support\TagResolver::class)->available($lead->contact, $lead)
                : [],
            'statuses' => LeadStatus::cases(),
            'transferOptions' => $this->transferOptions($lead),
            'motivacion' => is_string($motivacion) ? $motivacion : null,
            'isTerminal' => $lead->status->isTerminal(),
            'phoneDisplay' => $this->formatPhone($lead->contact?->phone),
            'canAct' => auth()->user()?->can('update', $lead) ?? false,
            'canEmail' => auth()->user()?->canSendEmail() ?? false,
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
