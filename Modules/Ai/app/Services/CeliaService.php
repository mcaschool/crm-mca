<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Illuminate\Support\Str;
use Modules\Catalog\Models\Program;
use Modules\Crm\Enums\EventType;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Models\ProgramInterest;
use Modules\Crm\Services\ConversationService;
use Modules\Crm\Services\EventService;
use Modules\Crm\Services\LeadConversionService;
use Modules\Crm\Services\MessageService;
use Modules\Institutions\Models\Bot;
use Throwable;

/**
 * "Modo Celia": asesora academica INTELIGENTE. Se activa solo cuando el prospecto
 * pide "Hablar con Celia". Reutiliza todo el Bloque 5 (arbol, matcher, eventos).
 *
 * Celia RESPONDE: toda consulta pasa a la IA (Qwen), que contesta con la base de
 * conocimiento autorizada y solo ofrece el menu/emparejador como ULTIMO recurso,
 * cuando de verdad no tiene el dato. NO se enruta a botones por palabra clave: eso
 * "eludia" preguntas que el conocimiento si responde. Cada llamada registra
 * provider/model/tokens/latency en messages.meta (base del AI Deflection Rate).
 *
 * Las barreras de conducta viven en config (system_prompt), no en el codigo ni en
 * los .md. El cliente de IA es agnostico y en pruebas se sustituye por un doble.
 */
class CeliaService
{
    public function __construct(
        private readonly AiChatClient $ai,
        private readonly AiProcessResolver $resolver,
        private readonly KnowledgeRetriever $knowledge,
        private readonly TopicRouter $router,
        private readonly ConversationService $conversations,
        private readonly MessageService $messages,
        private readonly EventService $events,
        private readonly LeadConversionService $leadConversion,
    ) {}

    /**
     * Activa el modo Celia: cambia de modo, registra el evento y saluda CON
     * CONTEXTO (nombre, idioma, programas vistos, si uso el emparejador). El saludo
     * es plantilla personalizada: NO gasta tokens de IA.
     *
     * @return array<string,mixed>
     */
    public function greet(Conversation $conversation, ?Contact $contact, string $locale): array
    {
        $this->conversations->switchMode($conversation, 'celia');
        $this->events->record(EventType::StartedCelia, [
            'contact_id' => $conversation->contact_id,
            'conversation_id' => $conversation->getKey(),
            'bot_id' => $conversation->bot_id,
        ]);

        $reply = $this->buildGreeting($conversation, $contact, $locale);
        $this->messages->record($conversation, 'celia', $reply, 'text');

        return $this->response($conversation, reply: $reply, action: 'answer', usedAi: false);
    }

    /**
     * Procesa un mensaje del prospecto en modo Celia. Aplica limite de coste,
     * enrutamiento en dos pasos y registro en CRM.
     *
     * @return array<string,mixed>
     */
    public function handle(Conversation $conversation, ?Contact $contact, string $message, string $locale): array
    {
        // Siempre se registra lo que dijo el usuario.
        $this->messages->record($conversation, 'user', $message, 'text');

        // Control de costos: al alcanzar el limite se deja de llamar a la IA.
        if ($this->aiMessageCount($conversation) >= $this->limit()) {
            $reply = $this->trans('celia.limit_reached', $locale, ['catalog' => $this->catalogUrl($locale)]);
            $this->messages->record($conversation, 'celia', $reply, 'text');

            return $this->response($conversation, reply: $reply, action: 'limit', usedAi: false, limitReached: true);
        }

        // Celia RESPONDE: toda consulta pasa a la IA, que contesta con el
        // conocimiento autorizado y solo ofrece el menu/emparejador como ULTIMO
        // recurso. Ya no se enruta a botones por palabra clave (eludia preguntas
        // que el conocimiento si responde, p. ej. "cuanto dura" o "metodos de pago").
        return $this->converse($conversation, $contact, $message, $locale);
    }

    /**
     * @return array<string,mixed>
     */
    private function converse(Conversation $conversation, ?Contact $contact, string $message, string $locale): array
    {
        $resolved = $this->resolver->resolve((int) $conversation->bot_id, 'conversation');

        // Sin proveedor configurado: honesto y deriva (no inventa, no promete humano).
        if ($resolved === null) {
            $reply = $this->trans('celia.ai_unavailable', $locale, ['catalog' => $this->catalogUrl($locale)]);
            $this->messages->record($conversation, 'celia', $reply, 'text');
            $this->recordUnresolved($conversation, $message);

            return $this->response($conversation, reply: $reply, action: 'unavailable', usedAi: false);
        }

        $corporate = $this->router->isCorporate($message);
        if ($corporate) {
            $this->events->record('corporate_interest', [
                'contact_id' => $conversation->contact_id,
                'conversation_id' => $conversation->getKey(),
                'bot_id' => $conversation->bot_id,
            ]);

            // Disparador de conversion: el interes corporativo/InCompany crea lead
            // SI O SI (antes solo dejaba el evento y el prospecto se perdia).
            $this->leadConversion->convert($contact, (int) $conversation->bot_id, 'corporate_interest');
        }

        $prompt = $this->systemPrompt($locale, $this->knowledge->retrieve((int) $conversation->bot_id, $message, $locale), $corporate);
        $chat = array_merge(
            [['role' => 'system', 'content' => $prompt]],
            $this->history($conversation, $locale),
            [['role' => 'user', 'content' => $message]],
        );

        try {
            $result = $this->ai->chat(
                $resolved['integration'],
                $resolved['model'],
                $chat,
                array_merge($resolved['params'], ['json' => true, 'max_tokens' => 500]),
            );
        } catch (Throwable) {
            $reply = $this->trans('celia.ai_unavailable', $locale, ['catalog' => $this->catalogUrl($locale)]);
            $this->messages->record($conversation, 'celia', $reply, 'text');
            $this->recordUnresolved($conversation, $message);

            return $this->response($conversation, reply: $reply, action: 'unavailable', usedAi: false);
        }

        [$reply, $action] = $this->parse($result->content);
        $reply = $this->truncate($reply, $locale);

        // Se registra el mensaje de IA con su meta (base del AI Deflection Rate).
        $this->messages->record($conversation, 'celia', $reply, 'ai', $result->meta());

        if ($action === 'unresolved') {
            $this->recordUnresolved($conversation, $message);
        }

        // Interes por programa: si la respuesta menciona una ficha del catalogo, se
        // registra (rastro program_interest). El detalle del programa vive en la web.
        $this->detectProgramInterest($conversation, $contact, $reply);

        return $this->response($conversation, reply: $reply, action: $action, usedAi: true);
    }

    // --- Contexto / saludo -------------------------------------------------

    private function buildGreeting(Conversation $conversation, ?Contact $contact, string $locale): string
    {
        $name = $contact !== null ? (string) $contact->first_name : '';
        // El nombre del asesor sale de la ficha (bots.assistant_name), no de un literal.
        $bot = Bot::query()->find($conversation->bot_id);
        $advisor = $bot !== null ? (string) $bot->assistant_name : 'Celia';
        $parts = [$this->trans('celia.greeting', $locale, ['name' => $name, 'advisor' => $advisor])];

        $viewed = $this->viewedPrograms($contact, $locale);
        if ($viewed !== []) {
            $parts[] = $this->trans('celia.context_viewed_programs', $locale, ['programs' => implode(', ', $viewed)]);
        }

        if ($locale === 'en') {
            $parts[] = $this->trans('celia.greeting_language_note', $locale);
        }

        // Limpia el espacio sobrante que deja un :name vacio (dobles espacios y
        // el espacio antes de la coma del saludo).
        $text = implode(' ', $parts);
        $text = preg_replace('/\s{2,}/', ' ', $text) ?? $text;
        $text = str_replace(' ,', ',', $text);

        return trim($text);
    }

    /**
     * @return array<int,string>
     */
    private function viewedPrograms(?Contact $contact, string $locale): array
    {
        if ($contact === null) {
            return [];
        }

        $programIds = ProgramInterest::query()
            ->where('contact_id', $contact->getKey())
            ->orderByDesc('id')
            ->limit(3)
            ->pluck('program_id')
            ->unique()
            ->all();

        if ($programIds === []) {
            return [];
        }

        return Program::query()
            ->whereIn('id', $programIds)
            ->get()
            ->map(fn (Program $p) => (string) $p->translate('name', $locale))
            ->filter()
            ->values()
            ->all();
    }

    // --- IA: prompt, historial, parseo ------------------------------------

    private function systemPrompt(string $locale, string $knowledge, bool $corporate = false): string
    {
        $base = (string) config('crm.celia.system_prompt.'.$locale, config('crm.celia.system_prompt.es'));
        $topics = $this->router->topicMap();
        $kb = $knowledge !== '' ? $knowledge : '(sin conocimiento cargado)';

        $prompt = $base."\n\n".$topics."\n\nCONOCIMIENTO AUTORIZADO (unica fuente de hechos):\n".$kb;

        // Encaminamiento corporativo (InCompany), solo cuando el mensaje lo dispara.
        // Discrecional: si en realidad es una consulta personal, se responde normal.
        if ($corporate) {
            $email = (string) config('crm.celia.corporate_email');
            $form = (string) config('crm.celia.corporate_form_url.'.$locale, config('crm.celia.corporate_form_url.es'));
            $prompt .= "\n\nFORMACION CORPORATIVA (InCompany): si el prospecto pregunta por capacitar a su empresa, equipo o personal (varios empleados, plan corporativo, descuento por volumen...), encaminalo de forma NATURAL y conversada al canal corporativo, incluyendo SIEMPRE el correo ".$email.' y el formulario '.$form.'. Preséntalo con tus palabras, no como un bloque rigido. Si en realidad es una consulta personal, respondela con normalidad.';
        }

        return $prompt;
    }

    /**
     * Ultimos N mensajes como memoria de la conversacion (rol user/assistant).
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function history(Conversation $conversation, string $locale): array
    {
        $limit = (int) config('crm.celia.history_messages', 6);

        return $conversation->messages()
            ->whereIn('sender_type', ['user', 'celia'])
            ->orderByDesc('id')
            ->limit($limit + 1) // +1 porque el mensaje actual ya se registro
            ->get()
            ->reverse()
            ->slice(0, $limit)
            ->map(fn ($m) => [
                'role' => $m->sender_type === 'celia' ? 'assistant' : 'user',
                'content' => (string) $m->content,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{0: string, 1: string} [reply, action]
     */
    private function parse(string $content): array
    {
        $content = trim($content);

        // Tolera vallado de codigo ```json ... ```.
        if (Str::startsWith($content, '```')) {
            $content = trim((string) preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $content));
        }

        $decoded = json_decode($content, true);
        if (is_array($decoded) && isset($decoded['reply'])) {
            $action = isset($decoded['action']) && is_string($decoded['action']) ? $decoded['action'] : 'answer';
            $action = in_array($action, ['answer', 'unresolved', 'start_matcher'], true) ? $action : 'answer';

            return [(string) $decoded['reply'], $action];
        }

        // Si el modelo no devolvio JSON, se usa el texto tal cual como respuesta.
        return [$content, 'answer'];
    }

    // --- Registro / helpers ------------------------------------------------

    private function recordUnresolved(Conversation $conversation, string $question): void
    {
        $this->events->record(EventType::UnresolvedQuestion, [
            'contact_id' => $conversation->contact_id,
            'conversation_id' => $conversation->getKey(),
            'bot_id' => $conversation->bot_id,
            'data' => ['question' => Str::limit($question, 300)],
        ]);
    }

    private function detectProgramInterest(Conversation $conversation, ?Contact $contact, string $reply): void
    {
        if ($contact === null || $reply === '') {
            return;
        }

        $program = Program::query()
            ->where('status', 'active')
            ->whereNotNull('url')
            ->get()
            ->first(fn (Program $p) => $p->url !== '' && str_contains($reply, (string) $p->url));

        if ($program instanceof Program) {
            $this->events->record(EventType::ProgramInterest, [
                'contact_id' => $contact->getKey(),
                'conversation_id' => $conversation->getKey(),
                'bot_id' => $conversation->bot_id,
                'data' => ['program_id' => $program->getKey(), 'source' => 'celia'],
            ]);

            // Disparador de conversion: interes en un programa concreto -> lead.
            $this->leadConversion->convert($contact, (int) $conversation->bot_id, 'program_interest', [
                'program_id' => $program->getKey(),
            ]);
        }
    }

    private function aiMessageCount(Conversation $conversation): int
    {
        return $conversation->messages()
            ->where('sender_type', 'celia')
            ->where('message_type', 'ai')
            ->count();
    }

    private function limit(): int
    {
        return (int) config('crm.celia.message_limit', 15);
    }

    private function truncate(string $reply, string $locale): string
    {
        $max = (int) config('crm.celia.max_reply_chars', 900);

        return mb_strlen($reply) > $max ? mb_substr($reply, 0, $max) : $reply;
    }

    private function catalogUrl(string $locale): string
    {
        return (string) config('crm.celia.catalog_url.'.$locale, config('crm.celia.catalog_url.es'));
    }

    /**
     * @param  array<string,string>  $replace
     */
    private function trans(string $key, string $locale, array $replace = []): string
    {
        return (string) trans($key, $replace, $locale);
    }

    /**
     * @param  array<string,mixed>|null  $node
     * @return array<string,mixed>
     */
    private function response(
        Conversation $conversation,
        ?string $reply,
        string $action,
        bool $usedAi,
        ?array $node = null,
        bool $limitReached = false,
    ): array {
        $used = $this->aiMessageCount($conversation);

        return [
            'reply' => $reply,
            'mode' => 'celia',
            'action' => $action,
            'node' => $node,
            'used_ai' => $usedAi,
            'messages_left' => max(0, $this->limit() - $used),
            'limit_reached' => $limitReached || $used >= $this->limit(),
        ];
    }
}
