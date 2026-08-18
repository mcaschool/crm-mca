<?php

declare(strict_types=1);

namespace Modules\Ai\Livewire\Knowledge;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Ai\Services\KnowledgeSyncService;
use Modules\Institutions\Models\Bot;

/**
 * Panel de la base de conocimiento de Celia. Muestra las fuentes cargadas y ofrece
 * "Sincronizar": reprocesa storage/app/knowledge/*.md y hace upsert por codigo.
 *
 * Solo Administrador (Policy). El aislamiento por institucion lo da el scope global
 * de KnowledgeSource; el bot se resuelve al unico activo de la institucion.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', KnowledgeSource::class);
    }

    public function sync(KnowledgeSyncService $service): void
    {
        $this->authorize('sync', KnowledgeSource::class);

        $bot = $this->bot();
        if ($bot === null) {
            session()->flash('status', __('No hay un bot activo para sincronizar.'));

            return;
        }

        $report = $service->sync($bot->getKey());

        session()->flash('status', __(':created creadas, :updated actualizadas, :skipped omitidas.', [
            'created' => $report['created'],
            'updated' => $report['updated'],
            'skipped' => $report['skipped'],
        ]));
    }

    public function render(): View
    {
        $bot = $this->bot();

        $sources = $bot === null
            ? collect()
            : KnowledgeSource::query()
                ->where('bot_id', $bot->getKey())
                ->orderByDesc('priority')
                ->orderBy('code')
                ->get();

        return view('ai::livewire.knowledge.index', [
            'sources' => $sources,
            'bot' => $bot,
        ]);
    }

    private function bot(): ?Bot
    {
        return Bot::query()->where('status', 'active')->first();
    }
}
