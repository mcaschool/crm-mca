<?php

declare(strict_types=1);

namespace Modules\Ai\Livewire\Advisor;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Ai\Models\AdvisorFeedback;
use Modules\Ai\Services\AdvisorCorrections;
use Modules\Ai\Services\AdvisorPreviewLinkService;
use Modules\Ai\Services\AdvisorTurn;
use Modules\Ai\Services\AdvisorTurnService;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Models\Message;
use Modules\Institutions\Models\Bot;
use Throwable;

/**
 * «Probar asesor»: página independiente (enlace privado /asesores/prueba/{token}) para que el
 * equipo converse con el asesor REAL —mismo modelo, prompt, conocimiento y programas— antes de
 * activarlo. Va por la capa común AdvisorTurnService con canal 'preview' (is_test): sin leads,
 * contactos, eventos ni envíos a canales. Cada respuesta se puede valorar («Correcta» /
 * «Necesita mejora» + observación) como evidencia para el equipo.
 *
 * El token se re-valida en CADA petición (un enlace revocado deja de funcionar al instante) y fija
 * el contexto de la institución del asesor. La conversación de prueba sobrevive a recargas en la
 * sesión del navegador; «Reiniciar» empieza otra.
 */
#[Layout('ai::layouts.preview')]
class Preview extends Component
{
    #[Locked]
    public string $token = '';

    /** Id externo de la conversación de prueba en curso (null = sin empezar). */
    #[Locked]
    public ?string $sessionKey = null;

    public string $lang = 'es';

    public string $draft = '';

    /** Respuesta con el cuadro de observación abierto. */
    public ?int $noteFor = null;

    public string $note = '';

    /**
     * Qué es el texto de «Necesita mejora»: approved («Esta es la respuesta correcta», solo para
     * quien puede aprobar; ver approver()) | comment.
     */
    public string $noteKind = 'comment';

    public ?string $notice = null;

    private ?Bot $resolvedBot = null;

    public function mount(string $token): void
    {
        $this->token = $token;
        $bot = $this->bot();
        $this->lang = in_array($bot->default_language, ['es', 'en'], true) ? $bot->default_language : 'es';

        $stored = session($this->storeKey());
        $this->sessionKey = is_string($stored) ? $stored : null;
        if ($this->conversation() === null) {
            $this->sessionKey = null;
        }
    }

    public function hydrate(): void
    {
        $this->bot(); // revalida el enlace y fija la institución en cada petición
    }

    /** «¡Conversemos!»: abre la conversación de prueba con el saludo del asesor. */
    public function start(AdvisorTurnService $turns): void
    {
        if ($this->sessionKey !== null && $this->conversation() !== null) {
            return;
        }
        $bot = $this->bot();

        $this->sessionKey = (string) Str::uuid();
        session([$this->storeKey() => $this->sessionKey]);
        $this->notice = null;

        try {
            $turns->open((int) $bot->institution_id, (int) $bot->getKey(), AdvisorTurnService::TEST_CHANNEL, $this->sessionKey, true, $this->lang);
        } catch (Throwable) {
            $this->notice = __('No se pudo iniciar la conversación de prueba. Inténtalo de nuevo en unos segundos.');
        }
    }

    public function send(AdvisorTurnService $turns): void
    {
        $this->validate(['draft' => ['required', 'string', 'max:1000']], [
            'draft.required' => __('Escribe un mensaje.'),
            'draft.max' => __('Máximo 1000 caracteres.'),
        ]);

        $bot = $this->bot();
        $limiterKey = 'advisor-preview:'.AdvisorPreviewLinkService::hash($this->token);
        if (RateLimiter::tooManyAttempts($limiterKey, (int) config('crm.widget.preview_rate_per_min', 20))) {
            $this->notice = __('Demasiados mensajes seguidos. Espera un minuto y vuelve a intentarlo.');

            return;
        }
        RateLimiter::hit($limiterKey, 60);

        if ($this->sessionKey === null || $this->conversation() === null) {
            $this->start($turns);
        }

        $this->notice = null;
        $text = trim($this->draft);

        try {
            $result = $turns->process(new AdvisorTurn(
                institutionId: (int) $bot->institution_id,
                botId: (int) $bot->getKey(),
                channel: AdvisorTurnService::TEST_CHANNEL,
                externalConversationId: (string) $this->sessionKey,
                text: $text,
                externalMessageId: (string) Str::uuid(),
                locale: $this->lang,
                isTest: true,
                metadata: ['source' => 'advisor_preview'],
            ));
            $this->draft = '';

            // Mensaje comprensible para el equipo, sin claves, prompts ni trazas.
            $this->notice = match ($result->status) {
                'unavailable' => __('El proveedor de IA no respondió o el asesor no tiene un proceso de IA configurado. Revisa su «Configuración de IA».'),
                'limit_reached' => __('Se alcanzó el límite de mensajes de esta conversación. Pulsa «Reiniciar conversación» para seguir probando.'),
                default => null,
            };
        } catch (Throwable) {
            $this->notice = __('No se pudo obtener la respuesta del asesor. Inténtalo de nuevo en unos segundos.');
        }
    }

    /** Empieza otra conversación de prueba (la anterior queda cerrada). */
    public function restart(): void
    {
        $conversation = $this->conversation();
        if ($conversation !== null) {
            $conversation->status = 'closed';
            $conversation->save();
        }

        session()->forget($this->storeKey());
        $this->reset(['sessionKey', 'draft', 'noteFor', 'note', 'notice']);
    }

    public function setLang(string $lang): void
    {
        if (in_array($lang, ['es', 'en'], true)) {
            $this->lang = $lang;
        }
    }

    /** «Correcta» / «Necesita mejora» sobre una respuesta del asesor de ESTA conversación. */
    public function rate(int $messageId, string $rating): void
    {
        if (! in_array($rating, [AdvisorFeedback::CORRECT, AdvisorFeedback::NEEDS_IMPROVEMENT], true)) {
            return;
        }
        $message = $this->advisorMessage($messageId);
        if ($message === null) {
            return;
        }

        $feedback = AdvisorFeedback::query()->updateOrCreate(
            ['message_id' => $message->getKey()],
            [
                'bot_id' => $this->bot()->getKey(),
                'conversation_id' => $message->conversation_id,
                'rating' => $rating,
                'comment' => $rating === AdvisorFeedback::CORRECT ? null : AdvisorFeedback::query()->where('message_id', $message->getKey())->value('comment'),
                'user_id' => auth()->id(),
            ],
        );

        // «Correcta» retira una respuesta aprobada anterior de esa misma respuesta (solo quien puede aprobar).
        if ($rating === AdvisorFeedback::CORRECT && $this->approver() !== null) {
            app(AdvisorCorrections::class)->forget($feedback);
        }

        $this->noteFor = $rating === AdvisorFeedback::NEEDS_IMPROVEMENT ? (int) $message->getKey() : null;
        $this->note = $this->noteFor !== null ? (string) $feedback->comment : '';
        $this->noteKind = $this->approver() !== null ? 'approved' : 'comment';
    }

    /**
     * Guarda el texto de «Necesita mejora»: como RESPUESTA APROBADA (se aplica al instante a las
     * preguntas equivalentes del mismo tema; si lo corregido es el saludo, pasa a ser el saludo del
     * asesor) o como simple comentario, igual que antes.
     */
    public function saveNote(AdvisorCorrections $corrections): void
    {
        $approved = $this->noteKind === 'approved';
        $approver = $this->approver();
        // Validación en el SERVIDOR: aprobar cambia lo que responde el asesor.
        if ($approved && $approver === null) {
            $this->addError('noteKind', __('Solo un administrador de asesores de esta institución, con sesión iniciada en el panel, puede aprobar respuestas.'));

            return;
        }
        $this->validate([
            'note' => [$approved ? 'required' : 'nullable', 'string', 'max:1000'],
            'noteKind' => ['required', 'in:approved,comment'],
        ], [
            'note.required' => __('Escribe la respuesta correcta.'),
            'note.max' => __('Máximo 1000 caracteres.'),
        ]);
        $message = $this->noteFor !== null ? $this->advisorMessage($this->noteFor) : null;
        $feedback = $message !== null ? AdvisorFeedback::query()->where('message_id', $message->getKey())->first() : null;
        if ($message === null || $feedback === null) {
            return;
        }

        $feedback->forceFill(['comment' => trim($this->note) !== '' ? trim($this->note) : null, 'user_id' => auth()->id()])->save();

        if ($approved) {
            // El autor es siempre el usuario con sesión que aprueba (garantizado arriba).
            $applied = $corrections->approve($feedback, $message, trim($this->note), (int) $approver->getKey());
            $this->notice = $applied === 'greeting'
                ? __('Saludo inicial actualizado: el asesor ya saluda así en las conversaciones nuevas.')
                : __('Respuesta aprobada: el asesor ya la usa para preguntas equivalentes sobre el mismo tema.');
        } elseif ($approver !== null) {
            // «Solo comentario» retira una aprobación anterior (también requiere poder aprobar).
            $corrections->forget($feedback);
        }
        $this->reset(['noteFor', 'note', 'noteKind']);
    }

    /**
     * Quien puede APROBAR respuestas (y cambiar el saludo) desde la prueba: usuario con sesión en el
     * panel, con el permiso de administrar asesores y de la MISMA institución del asesor. Quien solo
     * tiene el enlace puede valorar y comentar, pero no cambiar el comportamiento del asesor.
     */
    private function approver(): ?User
    {
        $user = auth()->user();

        return $user instanceof User && $user->canManageIntegrations() && (int) $user->institution_id === (int) $this->bot()->institution_id
            ? $user
            : null;
    }

    public function render(): View
    {
        $bot = $this->bot();
        $conversation = $this->conversation();
        $messages = $conversation === null ? collect() : Message::query()
            ->where('conversation_id', $conversation->getKey())
            ->whereIn('sender_type', ['user', 'celia'])
            ->orderBy('id')
            ->get(['id', 'sender_type', 'content', 'message_type', 'meta']);

        $ratings = $messages->isEmpty() ? collect() : AdvisorFeedback::query()
            ->whereIn('message_id', $messages->pluck('id'))
            ->pluck('rating', 'message_id');

        return view('ai::livewire.advisor.preview', [
            'bot' => $bot,
            'avatarUrl' => $bot->avatarUrl(),
            'texts' => $bot->effectiveWidgetTexts($this->lang),
            'messages' => $messages,
            'ratings' => $ratings,
            'started' => $conversation !== null,
            'canApprove' => $this->approver() !== null,
            // «Está escribiendo…»: nombre del asesor que responde, en el idioma de la conversación, y su espera mínima.
            'typingText' => __(':name está escribiendo…', ['name' => $bot->assistant_name], $this->lang),
            'typingDelayMs' => $bot->typingDelay() * 1000,
        ])->title(__('Prueba de :name', ['name' => $bot->assistant_name]));
    }

    /** Texto de una respuesta, escapado y con enlaces seguros (nueva pestaña, sin referrer). */
    public static function formatReply(string $text): string
    {
        $escaped = nl2br(e($text), false);

        return (string) preg_replace_callback('#https?://[^\s<]+#i', function (array $m): string {
            $url = rtrim($m[0], '.,;:!?)');
            $trail = substr($m[0], strlen($url));

            return '<a href="'.$url.'" target="_blank" rel="noopener noreferrer">'.$url.'</a>'.$trail;
        }, $escaped);
    }

    /**
     * Asesor del enlace (revalidado en cada petición). Enlace inválido o revocado → 404, sin
     * decir si existió. Fija el contexto de la institución del asesor.
     */
    private function bot(): Bot
    {
        if ($this->resolvedBot !== null) {
            return $this->resolvedBot;
        }

        $bot = app(AdvisorPreviewLinkService::class)->resolve($this->token);
        abort_if($bot === null, 404);
        app(CurrentInstitution::class)->set((int) $bot->institution_id);

        return $this->resolvedBot = $bot;
    }

    private function conversation(): ?Conversation
    {
        if ($this->sessionKey === null) {
            return null;
        }

        return Conversation::query()
            ->where('bot_id', $this->bot()->getKey())
            ->where('channel', AdvisorTurnService::TEST_CHANNEL)
            ->where('is_test', true)
            ->where('external_id', $this->sessionKey)
            ->where('status', 'open')
            ->first();
    }

    /** Respuesta del asesor que pertenece a la conversación de prueba en curso. */
    private function advisorMessage(int $messageId): ?Message
    {
        $conversation = $this->conversation();
        if ($conversation === null) {
            return null;
        }

        return Message::query()
            ->where('conversation_id', $conversation->getKey())
            ->where('sender_type', 'celia')
            ->find($messageId);
    }

    /** La conversación de prueba sobrevive a recargas (por enlace y navegador). */
    private function storeKey(): string
    {
        return 'advisor_preview.'.substr(AdvisorPreviewLinkService::hash($this->token), 0, 24);
    }
}
