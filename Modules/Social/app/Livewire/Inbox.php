<?php

declare(strict_types=1);

namespace Modules\Social\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\Social\Models\SocialConversation;
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

        // Badge del menú: total de no leídos de la institución. Se emite en cada ciclo del
        // poll (2 s) para que el badge del sidebar se refresque en vivo mientras se ve la bandeja.
        $this->dispatch('social-unread-updated', total: (int) SocialConversation::query()->sum('unread_count'));

        return view('social::inbox', [
            'conversations' => $conversations,
            'selected' => $selected,
            'messages' => $messages,
            'canReply' => $canReply,
        ]);
    }
}
