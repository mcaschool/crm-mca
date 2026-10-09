<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Modules\Ai\Models\AdvisorCorrection;
use Modules\Ai\Models\AdvisorFeedback;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Models\Message;
use Modules\Institutions\Models\Bot;

/**
 * Respuestas APROBADAS por el equipo («Necesita mejora» → «Esta es la respuesta correcta» en
 * «Probar asesor»), para cualquier asesor e institución, sin intervención técnica:
 *  - approve(): guarda la corrección (pregunta del usuario + tema activo + respuesta aprobada) o,
 *    si lo corregido es el SALUDO inicial (no hay pregunta previa), actualiza bots.greeting_{idioma};
 *  - match(): al responder, compara la pregunta nueva con las preguntas de las correcciones activas
 *    del asesor (PreciseKnowledgeRanker::questionSimilarity) y solo dentro del MISMO tema activo.
 *    Si el parecido llega al umbral (crm.knowledge.corrections.threshold), se aplica.
 * Todo acotado por el scope de institución y por bot_id.
 */
final class AdvisorCorrections
{
    public function __construct(private readonly PreciseKnowledgeRanker $ranker) {}

    /**
     * Aprueba $text como la respuesta correcta al mensaje del asesor valorado. $userId: quien
     * aprueba (usuario con sesión y permiso; lo comprueba quien llama).
     *
     * @return string correction | greeting
     */
    public function approve(AdvisorFeedback $feedback, Message $message, string $text, int $userId): string
    {
        $conversation = Conversation::query()->findOrFail($message->conversation_id);
        $previousUser = $this->userMessagesBefore($conversation, (int) $message->getKey());

        // Sin pregunta previa del usuario: lo corregido es el SALUDO inicial del asesor.
        if ($previousUser === []) {
            $bot = Bot::query()->findOrFail($feedback->bot_id);
            $lang = $conversation->language === 'en' ? 'en' : 'es';
            $bot->{'greeting_'.$lang} = $text;
            $bot->save();
            $this->forget($feedback);

            return 'greeting';
        }

        $question = array_shift($previousUser);
        AdvisorCorrection::query()->updateOrCreate(
            ['feedback_id' => $feedback->getKey()],
            [
                'institution_id' => $feedback->institution_id,
                'bot_id' => $feedback->bot_id,
                'question' => $question,
                'topic_line' => $this->ranker->topic($question, $previousUser)['line'],
                'answer' => $text,
                'active' => true,
                'user_id' => $userId,
            ],
        );

        return 'correction';
    }

    /** La valoración deja de aprobar una respuesta («Solo comentario» o «Correcta»). */
    public function forget(AdvisorFeedback $feedback): void
    {
        AdvisorCorrection::query()->where('feedback_id', $feedback->getKey())->delete();
    }

    /**
     * Corrección aplicable a la pregunta: activa, del mismo asesor, del mismo tema (o general) y
     * con parecido ≥ umbral. Devuelve también la mejor candidata aunque no llegue (diagnóstico).
     *
     * @param  list<string>  $history  mensajes anteriores del usuario, del más reciente al más antiguo
     * @return array{applied: bool, correction: AdvisorCorrection, score: float, threshold: float, line: ?string}|null
     */
    public function match(int $botId, string $question, array $history, string $locale): ?array
    {
        $corrections = AdvisorCorrection::query()->where('bot_id', $botId)->where('active', true)->orderBy('id')->get();
        if ($corrections->isEmpty()) {
            return null;
        }

        $line = $this->ranker->topic($question, $history)['line'];
        // Una corrección con tema solo vale en ese tema; una general (sin tema), en cualquiera.
        $eligible = $corrections->filter(fn (AdvisorCorrection $c): bool => $c->topic_line === null || $c->topic_line === $line)->values();
        if ($eligible->isEmpty()) {
            return null;
        }

        $scores = $this->ranker->questionSimilarity($question, $eligible->pluck('question')->map(fn ($q): string => (string) $q)->all(), $this->corpus($botId, $locale));
        $best = null;
        foreach ($eligible as $i => $correction) {
            // Mayor parecido; en empate, la del tema exacto y después la más reciente.
            $key = [$scores[$i], $correction->topic_line !== null ? 1 : 0, (int) $correction->getKey()];
            if ($best === null || $key > $best['key']) {
                $best = ['key' => $key, 'correction' => $correction, 'score' => $scores[$i]];
            }
        }
        $threshold = (float) config('crm.knowledge.corrections.threshold', 0.6);

        return $best === null ? null : [
            'applied' => $best['score'] >= $threshold,
            'correction' => $best['correction'],
            'score' => $best['score'],
            'threshold' => $threshold,
            'line' => $line,
        ];
    }

    /**
     * Mensajes del usuario ANTERIORES a un mensaje, del más reciente al más antiguo.
     *
     * @return list<string>
     */
    private function userMessagesBefore(Conversation $conversation, int $messageId): array
    {
        return $conversation->messages()
            ->where('sender_type', 'user')
            ->where('id', '<', $messageId)
            ->orderByDesc('id')
            ->limit((int) config('crm.knowledge.retrieval.history_messages', 6) + 1)
            ->pluck('content')
            ->map(fn ($c): string => (string) $c)
            ->values()
            ->all();
    }

    /**
     * Secciones del conocimiento activo del asesor: miden la RAREZA de cada palabra al comparar.
     *
     * @return list<string>
     */
    private function corpus(int $botId, string $locale): array
    {
        $texts = [];
        KnowledgeSource::query()
            ->where('knowledge_sources.status', 'active')
            ->join('bot_knowledge_source as bks', 'bks.knowledge_source_id', '=', 'knowledge_sources.id')
            ->where('bks.bot_id', $botId)
            ->where('bks.is_active', true)
            ->select('knowledge_sources.*')
            ->get()
            ->each(function (KnowledgeSource $s) use (&$texts, $locale): void {
                foreach (preg_split('/\n(?=## )/', (string) $s->translate('content', $locale)) ?: [] as $block) {
                    $texts[] = $block;
                }
            });

        return $texts;
    }
}
