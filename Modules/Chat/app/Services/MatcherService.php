<?php

declare(strict_types=1);

namespace Modules\Chat\Services;

use Illuminate\Support\Collection;
use Modules\Catalog\Models\Program;
use Modules\Catalog\Models\ProgramCategory;
use Modules\Chat\Support\LevelMapper;
use Modules\Crm\Enums\EventType;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Services\EventService;
use Modules\Crm\Services\LeadService;
use Modules\Crm\Services\ProgramInterestService;
use Modules\Institutions\Models\Bot;

/**
 * Emparejador determinista (Opcion B, sin IA). Mapea las 5 respuestas a filtros
 * del catalogo y aplica la regla de degradacion (nunca vacio). Registra en el CRM:
 * evento used_matcher, un program_interest por programa mostrado y un lead.
 *
 * Mapeo (confirmado, no cambiar):
 *  - area  -> category del programa
 *  - meta  -> columna goal del programa
 *  - seniority + educacion -> level (ver LevelMapper)
 *  - motivacion -> NO filtra; se guarda como senal (en el evento y en el lead).
 */
class MatcherService
{
    private const MAX_RESULTS = 6;

    public function __construct(
        private readonly EventService $events,
        private readonly ProgramInterestService $interests,
        private readonly LeadService $leads,
    ) {}

    /**
     * @param  array<string,string>  $answers  motivacion, meta, area, seniority, educacion
     */
    public function match(Bot $bot, ?Contact $contact, ?Conversation $conversation, array $answers): MatcherResult
    {
        $categoryId = isset($answers['area']) && $answers['area'] !== '' ? (int) $answers['area'] : null;
        $goal = ($answers['meta'] ?? '') !== '' ? $answers['meta'] : null;
        $level = LevelMapper::resolve($answers['seniority'] ?? 'inicio', $answers['educacion'] ?? 'secundaria');
        $motivacion = ($answers['motivacion'] ?? '') !== '' ? $answers['motivacion'] : null;

        [$programs, $tier] = $this->findWithDegradation($categoryId, $level, $goal);

        $this->recordCrm($bot, $contact, $conversation, $programs, $categoryId, $goal, $level, $motivacion, $answers);

        return new MatcherResult($programs, $tier, $level, $goal, $categoryId);
    }

    /**
     * Degradacion: 1) area+nivel+meta, 2) area+meta, 3) mejores del area, 4) vacio.
     * Solo programas ACTIVOS.
     *
     * @return array{0: Collection<int, Program>, 1: int}
     */
    private function findWithDegradation(?int $categoryId, string $level, ?string $goal): array
    {
        $base = fn () => Program::query()
            ->where('status', 'active')
            ->when($categoryId !== null, fn ($q) => $q->where('category_id', $categoryId))
            ->orderBy('display_order')
            ->orderBy('name_es');

        // Nivel 1: area + nivel + meta.
        $tier1 = $base()
            ->where('level', $level)
            ->when($goal !== null, fn ($q) => $q->where('goal', $goal))
            ->limit(self::MAX_RESULTS)->get();
        if ($tier1->isNotEmpty()) {
            return [$tier1, 1];
        }

        // Nivel 2: area + meta (cualquier nivel).
        $tier2 = $base()
            ->when($goal !== null, fn ($q) => $q->where('goal', $goal))
            ->limit(self::MAX_RESULTS)->get();
        if ($tier2->isNotEmpty()) {
            return [$tier2, 2];
        }

        // Nivel 3: los mejores del area (por peso).
        $tier3 = $base()->limit(self::MAX_RESULTS)->get();
        if ($tier3->isNotEmpty()) {
            return [$tier3, 3];
        }

        // Nivel 4: nada -> nunca vacio, se sugiere Celia / catalogo.
        return [collect(), 4];
    }

    /**
     * @param  Collection<int, Program>  $programs
     * @param  array<string,string>  $answers
     */
    private function recordCrm(
        Bot $bot,
        ?Contact $contact,
        ?Conversation $conversation,
        Collection $programs,
        ?int $categoryId,
        ?string $goal,
        string $level,
        ?string $motivacion,
        array $answers,
    ): void {
        $this->events->record(EventType::UsedMatcher, [
            'contact_id' => $contact?->getKey(),
            'conversation_id' => $conversation?->getKey(),
            'bot_id' => $bot->getKey(),
            'data' => ['answers' => $answers, 'level' => $level, 'motivacion' => $motivacion, 'results' => $programs->count()],
        ]);

        if ($contact === null) {
            return;
        }

        foreach ($programs as $program) {
            $this->interests->record($contact, $program, $bot->getKey(), 'matcher');
        }

        $areaName = $categoryId !== null
            ? optional(ProgramCategory::query()->find($categoryId))->name_es
            : null;

        // Lead con la senal comercial; la motivacion viaja como nota/senal.
        $this->leads->recordIntent($contact, [
            'bot_id' => $bot->getKey(),
            'program_id' => $programs->first()?->getKey(),
            'area' => $areaName,
            'goal' => $goal,
            'level' => $level,
            'source' => 'widget_matcher',
            'interest_level' => 'medium',
            'motivacion' => $motivacion,
        ]);
    }
}
