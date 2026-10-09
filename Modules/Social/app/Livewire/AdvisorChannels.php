<?php

declare(strict_types=1);

namespace Modules\Social\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Institutions\Models\Bot;
use Modules\Social\Exceptions\AdvisorAssignmentConflict;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Services\AdvisorChannelAssignment;
use Modules\Social\Support\AdvisorDispatcher;

/**
 * «Canales de atención automática» en la ficha de CUALQUIER asesor inteligente (no hay nada
 * específico de Sophia): Web Chat (estado actual, sin cambios) y, por cada cuenta conectada de
 * Instagram, Messenger y WhatsApp de la institución, un interruptor, el estado de la conexión, el
 * asesor que la atiende si es otro (con reasignación explícita) y quién/cuándo la cambió.
 *
 * Solo para quien administra integraciones (mismo permiso que «Canales»). Todo dentro de la
 * institución activa: el scope de los modelos impide ver o tocar cuentas de otra.
 */
final class AdvisorChannels extends Component
{
    public int $botId;

    public ?string $notice = null;

    public ?string $error = null;

    public function mount(int $botId): void
    {
        abort_unless((bool) auth()->user()?->canManageIntegrations(), 403);
        $this->botId = Bot::query()->findOrFail($botId)->getKey();
    }

    /** Interruptor de una cuenta: encender (si no la atiende otro) o apagar. */
    public function toggle(int $channelId, AdvisorChannelAssignment $assignment): void
    {
        [$bot, $channel] = $this->resolve($channelId);
        $this->reset('notice', 'error');

        $on = $channel->advisor_enabled && (int) $channel->advisor_bot_id === (int) $bot->getKey();
        try {
            if ($on) {
                $assignment->disable($channel, $bot, auth()->user());
                $this->notice = __('Atención automática desactivada en :channel. Las conversaciones y mensajes se conservan.', ['channel' => $channel->display_name]);
            } else {
                $assignment->enable($channel, $bot, auth()->user());
                $this->notice = __(':advisor atenderá automáticamente :channel a partir de los mensajes nuevos.', ['advisor' => $bot->assistant_name, 'channel' => $channel->display_name]);
            }
        } catch (AdvisorAssignmentConflict $e) {
            $this->error = $e->getMessage();
        }
    }

    /** Reasignación EXPLÍCITA de una cuenta que atiende otro asesor (confirmada en la vista). */
    public function reassign(int $channelId, ?int $fromBotId, AdvisorChannelAssignment $assignment): void
    {
        [$bot, $channel] = $this->resolve($channelId);
        $this->reset('notice', 'error');

        try {
            $assignment->reassign($channel, $bot, auth()->user(), $fromBotId);
            $this->notice = __(':channel reasignada a :advisor.', ['channel' => $channel->display_name, 'advisor' => $bot->assistant_name]);
        } catch (AdvisorAssignmentConflict $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render(): View
    {
        $bot = Bot::query()->findOrFail($this->botId);
        $channels = SocialChannel::query()
            ->with(['advisorBot', 'advisorAssigner'])
            ->orderBy('display_name')
            ->get();

        return view('social::advisor-channels', [
            'bot' => $bot,
            'groups' => collect(SocialChannel::PROVIDERS)
                ->map(fn (string $label, string $provider): array => ['label' => $label, 'channels' => $channels->where('provider', $provider)->values()]),
            'ready' => AdvisorDispatcher::ready(),
            'pendingReason' => AdvisorDispatcher::pendingReason(),
        ]);
    }

    /** @return array{0: Bot, 1: SocialChannel} */
    private function resolve(int $channelId): array
    {
        abort_unless((bool) auth()->user()?->canManageIntegrations(), 403);

        return [Bot::query()->findOrFail($this->botId), SocialChannel::query()->findOrFail($channelId)];
    }
}
