<?php

declare(strict_types=1);

namespace Modules\Social\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\Social\Models\SocialConversation;
use Modules\Social\Models\SocialWhatsAppTemplate;
use Modules\Social\Services\SocialOutboundService;
use RuntimeException;

/**
 * Bandeja social unificada (Bloque 2, solo UI). Cumple el rol de "InboxController" en este
 * CRM basado en Livewire (el patrón de panel es full-page Livewire, no controllers sueltos):
 * lista las conversaciones de la institución activa (scope del trait) ordenadas por
 * last_message_at DESC, y muestra el hilo de la seleccionada (mensajes ASC por
 * provider_timestamp, fallback created_at). Sin API real todavía: la caja de redacción
 * está deshabilitada. Acceso: mismo patrón del panel (grupo can:access-panel) + canWorkCrm.
 */
#[Layout('layouts.app')]
class Inbox extends Component
{
    use WithFileUploads;

    public ?int $selectedId = null;

    public string $draft = '';

    /**
     * Adjunto saliente de WhatsApp (imagen/video/audio/documento). La validación fina
     * (MIME real + límites oficiales por tipo) la hace WhatsAppMediaService al enviar.
     *
     * @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null
     */
    public $attachment = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->canWorkCrm() ?? false, 403);
    }

    /** Selecciona una conversación (validada contra el scope de institución) y la marca leída. */
    public function select(int $id): void
    {
        $conversation = SocialConversation::query()->find($id);
        if ($conversation === null) {
            $this->selectedId = null;

            return;
        }

        // Al abrir la conversación se marca como leída (no leídos → 0).
        if ($conversation->unread_count > 0) {
            $conversation->unread_count = 0;
            $conversation->save();
        }

        $this->selectedId = $conversation->id;
        $this->draft = '';
        $this->attachment = null;
        $this->resetErrorBag('attachment');
    }

    /** Validación temprana del adjunto (el límite fino por tipo lo aplica el servicio). */
    public function updatedAttachment(): void
    {
        $this->resetErrorBag('attachment');
        $this->validateOnly('attachment', ['attachment' => ['nullable', 'file', 'max:102400']], [
            'attachment.max' => __('El archivo supera el límite de 100 MB de WhatsApp.'),
        ]);
    }

    public function removeAttachment(): void
    {
        $this->attachment = null;
        $this->resetErrorBag('attachment');
    }

    // ---------------------------------------------------- plantillas (WhatsApp)

    public bool $showTemplates = false;

    public ?int $templateId = null;

    /** @var array<int, string> Valores de las variables {{n}} de la plantilla elegida. */
    public array $templateParams = [];

    public function openTemplates(): void
    {
        $this->showTemplates = true;
        $this->templateId = null;
        $this->templateParams = [];
        $this->resetErrorBag('template');
    }

    public function closeTemplates(): void
    {
        $this->showTemplates = false;
        $this->templateId = null;
        $this->templateParams = [];
        $this->resetErrorBag('template');
    }

    /** Elige una plantilla APROBADA del canal de la conversación (scoped por institución). */
    public function chooseTemplate(int $id): void
    {
        $template = $this->selectableTemplate($id);
        if ($template === null) {
            return;
        }
        $this->templateId = $template->id;
        $this->templateParams = array_fill_keys($template->positionalVariables(), '');
        $this->resetErrorBag('template');
    }

    public function sendTemplate(SocialOutboundService $outbound): bool
    {
        $conversation = $this->selectedId !== null ? SocialConversation::query()->find($this->selectedId) : null;
        $template = $this->templateId !== null ? $this->selectableTemplate($this->templateId) : null;
        if ($conversation === null || $conversation->provider !== 'whatsapp' || $template === null) {
            return false;
        }

        try {
            $outbound->sendWhatsAppTemplate($conversation, $template, $this->templateParams, auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('template', $e->getMessage());

            return false;
        }

        $this->closeTemplates();
        $this->draft = '';

        return true;
    }

    /** Solo plantillas APPROVED, sin header de media, del canal de la conversación. */
    private function selectableTemplate(int $id): ?SocialWhatsAppTemplate
    {
        $conversation = $this->selectedId !== null ? SocialConversation::query()->find($this->selectedId) : null;
        if ($conversation === null) {
            return null;
        }

        $template = SocialWhatsAppTemplate::query()
            ->where('id', $id)
            ->where('social_channel_id', $conversation->social_channel_id)
            ->where('status', SocialWhatsAppTemplate::SENDABLE_STATUS)
            ->first();

        return $template !== null && ! $template->hasMediaHeader() ? $template : null;
    }

    /**
     * Ventana de servicio de 24h: abierta si el ÚLTIMO entrante del contacto es de hace
     * menos de 24h. Comparación entre instantes absolutos (provider_timestamp se guarda
     * en UTC): nunca con la hora local "ingenua". Es una regla PREVENTIVA de UI/backend;
     * la validación definitiva sigue siendo Meta (131047 → failed_window).
     */
    private function whatsappWindowOpen(SocialConversation $conversation): bool
    {
        $lastInbound = $conversation->messages()
            ->where('direction', 'inbound')
            ->orderByRaw('COALESCE(provider_timestamp, created_at) desc')
            ->first();
        if ($lastInbound === null) {
            return false; // sin entrante del usuario no hay ventana: solo plantillas
        }

        $at = $lastInbound->provider_timestamp ?? $lastInbound->created_at;

        return $at !== null && $at->greaterThan(now()->subHours(24));
    }

    /**
     * Envía la respuesta del agente (Instagram/Messenger/WhatsApp). En WhatsApp admite un
     * adjunto: el texto de la caja hace de caption. El scoping por institución lo garantiza
     * la consulta (solo encuentra conversaciones de la institución activa).
     */
    public function send(SocialOutboundService $outbound, string $text = ''): bool
    {
        // El texto llega desde la caja (Alpine, wire:ignore); $this->draft es el respaldo.
        $text = trim($text !== '' ? $text : $this->draft);
        if (($text === '' && $this->attachment === null) || $this->selectedId === null) {
            return false;
        }

        $conversation = SocialConversation::query()->find($this->selectedId);
        if ($conversation === null || ! in_array($conversation->provider, SocialOutboundService::SENDABLE, true)) {
            return false;
        }

        // Ventana de 24h (WhatsApp): fuera de ella el mensaje libre se bloquea TAMBIÉN en
        // backend (la UI ya lo previene); solo se permite responder con plantilla aprobada.
        if ($conversation->provider === 'whatsapp' && ! $this->whatsappWindowOpen($conversation)) {
            $this->addError('draft', __('La ventana de atención de 24 horas ha finalizado. Para contactar nuevamente debes utilizar una plantilla aprobada.'));

            return false;
        }

        if ($this->attachment !== null) {
            if ($conversation->provider !== 'whatsapp') {
                return false; // adjuntos solo por WhatsApp en este bloque
            }
            try {
                $outbound->sendWhatsAppMedia($conversation, $this->attachment, $text, auth()->user());
            } catch (RuntimeException $e) {
                // Mensaje ya apto para el usuario (formato/tamaño); nunca datos del API.
                $this->addError('attachment', $e->getMessage());

                return false;
            }
            $this->attachment = null;
        } else {
            $outbound->send($conversation, $text, auth()->user());
        }

        $this->draft = '';

        return true;
    }

    public function render(): View
    {
        $conversations = SocialConversation::query()
            ->with('channel')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $selected = $this->selectedId !== null
            ? SocialConversation::query()->with('channel')->find($this->selectedId)
            : null;

        $messages = $selected !== null
            ? $selected->messages()
                ->orderByRaw('COALESCE(provider_timestamp, created_at) asc')
                ->orderBy('id')
                ->get()
            : collect();

        $canReply = $selected !== null
            && in_array($selected->provider, SocialOutboundService::SENDABLE, true);

        // WhatsApp: estado de la ventana de 24h, plantillas elegibles y alerta offboarded.
        $waWindowOpen = true;
        $waTemplates = collect();
        $waOffboarded = false;
        $waChosen = null;
        if ($selected !== null && $selected->provider === 'whatsapp') {
            $waWindowOpen = $this->whatsappWindowOpen($selected);
            $waOffboarded = ! ($selected->channel?->canSendViaApi() ?? true);
            if ($this->showTemplates) {
                $waTemplates = SocialWhatsAppTemplate::query()
                    ->where('social_channel_id', $selected->social_channel_id)
                    ->where('status', SocialWhatsAppTemplate::SENDABLE_STATUS)
                    ->orderBy('name')
                    ->get()
                    ->reject(fn (SocialWhatsAppTemplate $t): bool => $t->hasMediaHeader())
                    ->values();
                $waChosen = $this->templateId !== null
                    ? $waTemplates->firstWhere('id', $this->templateId)
                    : null;
            }
        }

        // Badge del menú: total de no leídos de la institución. Se emite en cada ciclo del
        // poll (2 s) para que el badge del sidebar se refresque en vivo mientras se ve la bandeja.
        $this->dispatch('social-unread-updated', total: (int) SocialConversation::query()->sum('unread_count'));

        return view('social::inbox', [
            'conversations' => $conversations,
            'selected' => $selected,
            'messages' => $messages,
            'canReply' => $canReply,
            'waWindowOpen' => $waWindowOpen,
            'waTemplates' => $waTemplates,
            'waOffboarded' => $waOffboarded,
            'waChosen' => $waChosen,
        ]);
    }
}
