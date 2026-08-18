<?php

declare(strict_types=1);

namespace Modules\Ai\Livewire\Advisor;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Institutions\Models\Bot;
use Modules\Integrations\Models\AiProcessConfig;

/**
 * Asesores Inteligentes — lista. Muestra los asesores (bots) de la institucion:
 * avatar, nombre, tipo (IA/Humano), idioma+modelo, estado y nº de documentos de
 * conocimiento. Visible para todos los roles del panel (lectura); crear/editar es
 * solo de Administrador (gating en el formulario y ocultando acciones aqui).
 */
#[Layout('layouts.app')]
class Index extends Component
{
    public function render(): View
    {
        $bots = Bot::query()->orderBy('id')->get();

        // Modelo/integracion del proceso 'conversation' por bot (para mostrar).
        $configs = AiProcessConfig::query()
            ->where('process', 'conversation')
            ->with('integration')
            ->get()
            ->keyBy('bot_id');

        // Nº de documentos de conocimiento por bot.
        $counts = KnowledgeSource::query()
            ->selectRaw('bot_id, count(*) as c')
            ->groupBy('bot_id')
            ->pluck('c', 'bot_id');

        $advisors = $bots->map(function (Bot $bot) use ($configs, $counts): array {
            $cfg = $configs->get($bot->getKey());

            return [
                'id' => $bot->getKey(),
                'name' => $bot->assistant_name,
                'type' => $bot->type,
                'avatar' => $bot->avatarUrl(),
                'language' => strtoupper($bot->default_language),
                'model' => $cfg?->model,
                'provider' => $cfg?->integration?->provider,
                'status' => $bot->status,
                'docs' => (int) ($counts[$bot->getKey()] ?? 0),
            ];
        })->all();

        return view('ai::livewire.advisor.index', [
            'advisors' => $advisors,
            'canManage' => (bool) auth()->user()?->canManageIntegrations(),
        ]);
    }
}
