<?php

declare(strict_types=1);

namespace Modules\Chat\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Catalog\Models\Program;
use Modules\Catalog\Models\ProgramCategory;
use Modules\Chat\Models\ConversationNode;
use Modules\Chat\Models\ConversationOption;
use Modules\Chat\Services\GuidedNavigationService;
use Modules\Chat\Services\MatcherService;
use Modules\Crm\Enums\EventType;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Services\ContactService;
use Modules\Crm\Services\ConversationService;
use Modules\Crm\Services\EventService;
use Modules\Institutions\Models\Bot;

/**
 * API publica del widget (stateless, sin cookies, JSON). La institucion/bot se
 * deduce de la public_key (middleware institution.bot); el cliente NUNCA envia
 * institution_id. Rate limiting y honeypot aplicados en las rutas.
 */
class WidgetController extends Controller
{
    /** Saludo previo a la captura (lo muestra el widget con su propio formulario). */
    private const WELCOME_KEY = 'NODE_WELCOME';

    /** Inicio del arbol guiado, tras la captura. */
    private const MAIN_KEY = 'NODE_MAIN';

    public function __construct(
        private readonly ConversationService $conversations,
        private readonly ContactService $contacts,
        private readonly EventService $events,
        private readonly GuidedNavigationService $guided,
        private readonly MatcherService $matcher,
    ) {}

    /** Inicia o recupera una conversacion (recuperacion de sesion por session_id). */
    public function session(Request $request): JsonResponse
    {
        $bot = $this->bot($request);
        $locale = app()->getLocale();
        $sessionId = (string) $request->input('session_id', '');

        $conversation = $sessionId !== ''
            ? Conversation::query()->where('session_id', $sessionId)->first()
            : null;

        if ($conversation === null) {
            $conversation = $this->conversations->start(['bot_id' => $bot->getKey(), 'language' => $locale, 'mode' => 'guided']);
            $this->events->record(EventType::WidgetOpened, ['conversation_id' => $conversation->getKey(), 'bot_id' => $bot->getKey()]);
        }

        $node = $this->currentNode($conversation, $bot->getKey());

        return response()->json([
            'session_id' => $conversation->session_id,
            'bot' => ['assistant_name' => $bot->assistant_name, 'default_language' => $bot->default_language],
            'contact_captured' => $conversation->contact_id !== null,
            'node' => $node !== null ? $this->guided->renderNode($node, $locale, $this->contactName($conversation)) : null,
        ]);
    }

    /** Captura del lead: nombre + correo + consentimiento (D2). */
    public function lead(Request $request): JsonResponse
    {
        $this->assertNotBot($request);
        $bot = $this->bot($request);

        $data = $request->validate([
            'session_id' => ['required', 'string'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'consent' => ['accepted'],
        ]);

        $conversation = $this->conversation($data['session_id']);
        $locale = app()->getLocale();

        $contact = $this->contacts->createOrUpdate([
            'first_name' => $data['name'],
            'email' => $data['email'],
            'preferred_language' => $locale,
            'consent' => true,
            'consent_source' => 'widget',
        ]);

        $this->conversations->attachContact($conversation, $contact);
        $this->events->record(EventType::ContactCreated, [
            'contact_id' => $contact->getKey(),
            'conversation_id' => $conversation->getKey(),
            'bot_id' => $bot->getKey(),
        ]);

        // Tras la captura, el arbol arranca en NODE_MAIN (sin duplicar la captura,
        // que la maneja el widget). NODE_WELCOME es solo el saludo previo.
        $main = $this->guided->findNode($bot->getKey(), self::MAIN_KEY);
        if ($main !== null) {
            $conversation->current_node_id = $main->getKey();
            $conversation->save();
        }

        return response()->json(['ok' => true, 'contact_captured' => true]);
    }

    /** Clic en una opcion del arbol: registra evento y avanza. */
    public function navigate(Request $request): JsonResponse
    {
        $bot = $this->bot($request);
        $data = $request->validate([
            'session_id' => ['required', 'string'],
            'option_id' => ['required', 'integer'],
        ]);

        $conversation = $this->conversation($data['session_id']);

        // La opcion debe pertenecer a un nodo de ESTE bot (scope global + verificacion).
        $option = ConversationOption::query()->findOrFail($data['option_id']);
        $node = ConversationNode::query()->where('bot_id', $bot->getKey())->findOrFail($option->node_id);

        $result = $this->guided->choose($conversation, $option, app()->getLocale(), $this->contactName($conversation));

        return response()->json($result);
    }

    /** Emparejador: 5 respuestas -> tarjetas de programa + lead/eventos en CRM. */
    public function match(Request $request): JsonResponse
    {
        $this->assertNotBot($request);
        $bot = $this->bot($request);

        $data = $request->validate([
            'session_id' => ['required', 'string'],
            'answers.area' => ['nullable', 'integer'],
            'answers.meta' => ['nullable', 'string'],
            'answers.seniority' => ['required', 'string'],
            'answers.educacion' => ['required', 'string'],
            'answers.motivacion' => ['nullable', 'string'],
        ]);

        $conversation = $this->conversation($data['session_id']);
        $contact = $conversation->contact_id !== null
            ? Contact::query()->find($conversation->contact_id)
            : null;

        $result = $this->matcher->match($bot, $contact, $conversation, $request->input('answers', []));

        return response()->json([
            'tier' => $result->tier,
            'suggest_celia' => $result->suggestCelia(),
            'level' => $result->level,
            'programs' => $result->programs->map(fn (Program $p) => [
                'name' => $p->translate('name'),
                'url' => $p->url,
            ])->values()->all(),
        ]);
    }

    /** Opciones de las 5 preguntas (area/meta del catalogo real; resto de config). */
    public function matcherOptions(Request $request): JsonResponse
    {
        $this->bot($request);
        $locale = app()->getLocale();

        $areas = ProgramCategory::query()->where('status', 'active')->orderBy('name_es')->get()
            ->map(fn (ProgramCategory $c) => ['value' => $c->getKey(), 'label' => $c->translate('name', $locale)])->all();

        $metas = Program::query()->where('status', 'active')->whereNotNull('goal')
            ->distinct()->orderBy('goal')->pluck('goal')
            ->map(fn (string $g) => ['value' => $g, 'label' => trans()->has('matcher.meta.'.$g) ? __('matcher.meta.'.$g) : $g])->all();

        return response()->json([
            'questions' => [
                'motivacion' => __('matcher.questions.motivacion'),
                'meta' => __('matcher.questions.meta'),
                'area' => __('matcher.questions.area'),
                'seniority' => __('matcher.questions.seniority'),
                'educacion' => __('matcher.questions.educacion'),
            ],
            'area' => $areas,
            'meta' => $metas,
            'seniority' => $this->options('seniority'),
            'educacion' => $this->options('educacion'),
            'motivacion' => $this->options('motivacion'),
        ]);
    }

    private function bot(Request $request): Bot
    {
        $bot = $request->attributes->get('bot');
        abort_unless($bot instanceof Bot, 400, 'Bot no resuelto.');

        return $bot;
    }

    private function conversation(string $sessionId): Conversation
    {
        return Conversation::query()->where('session_id', $sessionId)->firstOrFail();
    }

    private function currentNode(Conversation $conversation, int $botId): ?ConversationNode
    {
        if ($conversation->current_node_id !== null) {
            $node = ConversationNode::query()->find($conversation->current_node_id);
            if ($node !== null) {
                return $node;
            }
        }

        return $this->guided->findNode($botId, self::WELCOME_KEY);
    }

    /** Nombre del contacto de la conversacion (para personalizar [Nombre]/[Name]). */
    private function contactName(Conversation $conversation): ?string
    {
        if ($conversation->contact_id === null) {
            return null;
        }

        return optional(Contact::query()->find($conversation->contact_id))->first_name;
    }

    /** Honeypot (D8): un campo oculto que solo un bot rellenaria. */
    private function assertNotBot(Request $request): void
    {
        abort_if($request->filled('website'), 422, 'Solicitud rechazada.');
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function options(string $key): array
    {
        return array_map(
            fn (string $v) => ['value' => $v, 'label' => __('matcher.'.$key.'.'.$v)],
            (array) config('crm.matcher.'.$key, []),
        );
    }
}
