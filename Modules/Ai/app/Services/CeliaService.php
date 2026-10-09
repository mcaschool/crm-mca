<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Illuminate\Support\Str;
use Modules\Ai\Exceptions\AdvisorAiUnavailable;
use Modules\Ai\Support\LinkGuard;
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
        private readonly ProgramAssignmentService $programAssignments,
        private readonly AdvisorPromptBuilder $prompts,
        private readonly AdvisorCorrections $corrections,
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
        if (! $this->isTest($conversation)) {
            $this->events->record(EventType::StartedCelia, [
                'contact_id' => $conversation->contact_id,
                'conversation_id' => $conversation->getKey(),
                'bot_id' => $conversation->bot_id,
            ]);
        }

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
    public function handle(Conversation $conversation, ?Contact $contact, string $message, string $locale, ?string $externalMessageId = null, bool $retryOnAiFailure = false): array
    {
        // Siempre se registra lo que dijo el usuario (con el id del canal de origen, si lo hay).
        $this->messages->record($conversation, 'user', $message, 'text', [], $externalMessageId);

        // Control de costos: al alcanzar el limite (el del asesor o el general) se deja de llamar a la IA.
        if ($this->aiMessageCount($conversation) >= $this->limit($conversation)) {
            $reply = $this->fallbackText('limit_reached', $conversation, $locale);
            $this->messages->record($conversation, 'celia', $reply, 'text');

            return $this->response($conversation, reply: $reply, action: 'limit', usedAi: false, limitReached: true);
        }

        // Celia RESPONDE: toda consulta pasa a la IA, que contesta con el
        // conocimiento autorizado y solo ofrece el menu/emparejador como ULTIMO
        // recurso. Ya no se enruta a botones por palabra clave (eludia preguntas
        // que el conocimiento si responde, p. ej. "cuanto dura" o "metodos de pago").
        return $this->converse($conversation, $contact, $message, $locale, $retryOnAiFailure);
    }

    /**
     * @return array<string,mixed>
     */
    private function converse(Conversation $conversation, ?Contact $contact, string $message, string $locale, bool $retryOnAiFailure = false): array
    {
        $resolved = $this->resolver->resolve((int) $conversation->bot_id, 'conversation');

        // Sin proveedor configurado: honesto y deriva (no inventa, no promete humano).
        if ($resolved === null) {
            $reply = $this->fallbackText('ai_unavailable', $conversation, $locale);
            $this->messages->record($conversation, 'celia', $reply, 'text');
            $this->recordUnresolved($conversation, $message);

            return $this->response($conversation, reply: $reply, action: 'unavailable', usedAi: false);
        }

        $corporate = $this->router->isCorporate($message);
        // En una conversación de PRUEBA el prompt es el mismo, pero no se deja rastro comercial.
        if ($corporate && ! $this->isTest($conversation)) {
            $this->events->record('corporate_interest', [
                'contact_id' => $conversation->contact_id,
                'conversation_id' => $conversation->getKey(),
                'bot_id' => $conversation->bot_id,
            ]);

            // Disparador de conversion: el interes corporativo/InCompany crea lead
            // SI O SI (antes solo dejaba el evento y el prospecto se perdia).
            $this->leadConversion->convert($contact, (int) $conversation->bot_id, 'corporate_interest');
        }

        $bot = $this->bot($conversation);
        $precise = $bot !== null && $bot->usesPreciseRetrieval();
        // Mensajes anteriores del usuario: dan el TEMA ACTIVO de los seguimientos.
        $history = $this->previousUserMessages($conversation);
        // Respuesta APROBADA por el equipo para una pregunta equivalente del mismo tema (cualquier asesor).
        $match = $this->corrections->match((int) $conversation->bot_id, $message, $history, $locale);
        $approved = $match !== null && $match['applied']
            ? ['question' => (string) $match['correction']->question, 'answer' => (string) $match['correction']->answer]
            : null;
        $knowledge = $this->knowledge->retrieveWithSources((int) $conversation->bot_id, $message, $locale, null, $precise ? $history : []);
        // Prompt del asesor: el global de siempre (Celia) o su identidad e instrucciones propias.
        $prompt = $this->prompts->build($bot ?? new Bot(['uses_legacy_prompt' => true]), $locale, (string) $conversation->channel, $knowledge['text'], $corporate, $approved);
        $chat = array_merge(
            [['role' => 'system', 'content' => $prompt]],
            $this->history($conversation, $locale),
            [['role' => 'user', 'content' => $message]],
        );

        $integration = $resolved['integration'];
        $context = new AiExecutionContext(
            institutionId: (int) $conversation->institution_id,
            // Mismo proceso/modelo; el uso de una conversación de prueba queda etiquetado aparte.
            process: $this->isTest($conversation) ? 'conversation_test' : 'conversation',
            integrationId: (int) $integration->getKey(),
            provider: (string) ($integration->provider ?? ''),
            model: (string) $resolved['model'],
            botId: (int) $conversation->bot_id,
            agentId: null,
        );

        try {
            $result = $this->ai->chat(
                $integration,
                $resolved['model'],
                $chat,
                array_merge($resolved['params'], ['structured' => true, 'max_tokens' => 500]),
                $context,
            );
        } catch (Throwable $e) {
            // Canal con despacho persistente: se reintenta más tarde en lugar de contestar «no disponible».
            if ($retryOnAiFailure) {
                throw new AdvisorAiUnavailable($e->getMessage(), 0, $e);
            }
            $reply = $this->fallbackText('ai_unavailable', $conversation, $locale);
            $this->messages->record($conversation, 'celia', $reply, 'text');
            $this->recordUnresolved($conversation, $message);

            return $this->response($conversation, reply: $reply, action: 'unavailable', usedAi: false);
        }

        [$reply, $action] = $this->parse($result->content);

        $preview = $this->isTest($conversation) && (string) $conversation->channel === AdvisorTurnService::TEST_CHANNEL;
        $extra = [];
        if ($approved !== null) {
            $extra['correction'] = ['id' => (int) $match['correction']->getKey(), 'score' => $match['score']];
        }
        // Modo de prueba: si se comprobaron respuestas aprobadas, cuál fue la mejor y si se aplicó.
        if ($preview && $match !== null) {
            $extra['correction_check'] = [
                'id' => (int) $match['correction']->getKey(), 'question' => (string) $match['correction']->question,
                'score' => $match['score'], 'threshold' => $match['threshold'], 'applied' => $match['applied'], 'line' => $match['line'],
            ];
        }

        // Búsqueda precisa: solo sobreviven los enlaces que están TEXTUALMENTE en lo recuperado o en
        // la respuesta aprobada que se aplicó.
        if ($precise) {
            [$reply, $removed] = LinkGuard::keepKnown($reply, $knowledge['text'].($approved !== null ? "\n".$approved['answer'] : ''));
            if ($removed !== []) {
                $extra['links_removed'] = count($removed);
            }
            // Modo de prueba: diagnóstico de la búsqueda (palabras, línea, programa, secciones y puntaje).
            if ($preview) {
                $extra['retrieval'] = ($knowledge['diagnostics'] ?? []) + ($removed !== [] ? ['links_removed' => $removed] : []);
            }
        }
        $reply = $this->truncate($reply, $locale);

        // Se registra el mensaje de IA con su meta (base del AI Deflection Rate) y las fuentes de
        // conocimiento usadas (trazabilidad interna; no viajan al usuario).
        $this->messages->record($conversation, 'celia', $reply, 'ai', array_merge($result->meta(), ['knowledge_sources' => $knowledge['sources'], 'action' => $action], $extra));

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
        $bot = $this->bot($conversation);
        $advisor = $bot !== null ? (string) $bot->assistant_name : 'Celia';
        // Celia (prompt global) conserva su saludo; un asesor con identidad propia se presenta con
        // SU función, sin hablar de Microcredenciales.
        $custom = $bot !== null && ! $bot->usesGlobalPrompt();
        // Saludo propio del asesor (ficha o respuesta aprobada en «Probar asesor»), si lo tiene.
        $own = $bot?->greeting($locale);
        $parts = [match (true) {
            $own !== null => str_replace(':name', $name, $own),
            $custom => $this->trans('celia.greeting_custom', $locale, ['name' => $name, 'advisor' => $advisor, 'role' => $this->roleOf($bot, $locale)]),
            default => $this->trans('celia.greeting', $locale, ['name' => $name, 'advisor' => $advisor]),
        }];

        $viewed = $this->viewedPrograms($contact, (int) $conversation->bot_id, $locale);
        if ($viewed !== []) {
            $parts[] = $this->trans('celia.context_viewed_programs', $locale, ['programs' => implode(', ', $viewed)]);
        }

        if ($locale === 'en' && ! $custom) {
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
     * Programas vistos por el contacto que el asesor ACTUAL puede recomendar (Bloque 4c): los
     * no asignados a este bot no se citan; si no queda ninguno, el saludo va sin esa frase.
     *
     * @return array<int,string>
     */
    private function viewedPrograms(?Contact $contact, int $botId, string $locale): array
    {
        if ($contact === null) {
            return [];
        }

        $programIds = ProgramInterest::query()
            ->where('contact_id', $contact->getKey())
            ->whereIn('program_id', $this->programAssignments->assignedIdsQuery($botId))
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

    /**
     * Ultimos N mensajes como memoria de la conversacion (rol user/assistant).
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function history(Conversation $conversation, string $locale): array
    {
        $limit = (int) config('crm.celia.history_messages', 6);

        // El mensaje del turno ACTUAL ya se registró en handle(); es el más reciente.
        // Se descarta SIEMPRE (slice(1) sobre el orden descendente) para no duplicarlo,
        // porque converse() lo vuelve a añadir explícitamente como el último 'user'.
        return $conversation->messages()
            ->whereIn('sender_type', ['user', 'celia'])
            ->orderByDesc('id')
            ->offset(1)         // salta el mensaje más reciente (el turno actual, ya registrado)
            ->limit($limit)
            ->get()
            ->reverse()         // orden cronológico para el modelo
            ->map(fn ($m) => [
                'role' => $m->sender_type === 'celia' ? 'assistant' : 'user',
                'content' => (string) $m->content,
            ])
            ->values()
            ->all();
    }

    /**
     * Mensajes ANTERIORES del usuario (sin el del turno actual), del más reciente al más antiguo.
     *
     * @return list<string>
     */
    private function previousUserMessages(Conversation $conversation): array
    {
        return $conversation->messages()
            ->where('sender_type', 'user')
            ->orderByDesc('id')
            ->offset(1)         // el turno actual ya está registrado
            ->limit((int) config('crm.knowledge.retrieval.history_messages', 6))
            ->pluck('content')
            ->map(fn ($c): string => (string) $c)
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
            $action = in_array($action, ['answer', 'unresolved', 'start_matcher', 'handoff'], true) ? $action : 'answer';

            return [(string) $decoded['reply'], $action];
        }

        // Si el modelo no devolvio JSON, se usa el texto tal cual como respuesta.
        return [$content, 'answer'];
    }

    // --- Registro / helpers ------------------------------------------------

    private function bot(Conversation $conversation): ?Bot
    {
        return Bot::query()->find($conversation->bot_id);
    }

    /** Función con la que se presenta un asesor con identidad propia. */
    private function roleOf(Bot $bot, string $locale): string
    {
        $role = trim((string) $bot->role_description);

        return $role !== '' ? $role : $this->trans('celia.default_role', $locale);
    }

    /**
     * Texto de respaldo (límite alcanzado / IA no disponible): el de Celia con el catálogo de
     * Microcredenciales, o uno neutro para un asesor con identidad propia.
     */
    private function fallbackText(string $key, Conversation $conversation, string $locale): string
    {
        $bot = $this->bot($conversation);
        if ($bot !== null && ! $bot->usesGlobalPrompt()) {
            return $this->trans('celia.'.$key.'_custom', $locale);
        }

        return $this->trans('celia.'.$key, $locale, ['catalog' => $this->catalogUrl($locale)]);
    }

    /** Conversación de PRUEBA interna: sin eventos, leads ni intereses (no es producción). */
    private function isTest(Conversation $conversation): bool
    {
        return (bool) $conversation->is_test;
    }

    private function recordUnresolved(Conversation $conversation, string $question): void
    {
        if ($this->isTest($conversation)) {
            return;
        }

        $this->events->record(EventType::UnresolvedQuestion, [
            'contact_id' => $conversation->contact_id,
            'conversation_id' => $conversation->getKey(),
            'bot_id' => $conversation->bot_id,
            'data' => ['question' => Str::limit($question, 300)],
        ]);
    }

    private function detectProgramInterest(Conversation $conversation, ?Contact $contact, string $reply): void
    {
        if ($contact === null || $reply === '' || $this->isTest($conversation)) {
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

    /** Respuestas de IA permitidas en la conversación: las del asesor o las generales. */
    private function limit(Conversation $conversation): int
    {
        return $this->bot($conversation)?->aiMessageLimit() ?? (int) config('crm.celia.message_limit', 15);
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
            'messages_left' => max(0, $this->limit($conversation) - $used),
            'limit_reached' => $limitReached || $used >= $this->limit($conversation),
        ];
    }
}
