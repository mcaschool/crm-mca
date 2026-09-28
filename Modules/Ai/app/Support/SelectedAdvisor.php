<?php

declare(strict_types=1);

namespace Modules\Ai\Support;

use Illuminate\Support\Collection;
use Modules\Institutions\Models\Bot;

/**
 * Agente (bot) SELECCIONADO en la zona de IA, persistido en sesión para toda la navegación
 * del módulo (ficha legada, panel de conocimiento, pestaña Agentes). Solo bots ACTIVOS de la
 * institución (el scope global de Bot acota la institución). Si no hay selección válida, se
 * usa el primer bot activo (comportamiento previo, por compatibilidad).
 */
final class SelectedAdvisor
{
    public const SESSION_KEY = 'ai.advisor_id';

    /** @return Collection<int, Bot> Bots activos elegibles, en orden estable. */
    public static function options(): Collection
    {
        return Bot::query()->where('status', 'active')->orderBy('id')->get();
    }

    /** Bot seleccionado (válido y activo) o, en su defecto, el primer bot activo. */
    public static function current(): ?Bot
    {
        $id = session(self::SESSION_KEY);
        if (is_numeric($id)) {
            $bot = Bot::query()->where('status', 'active')->find((int) $id);
            if ($bot !== null) {
                return $bot;
            }
        }

        return Bot::query()->where('status', 'active')->orderBy('id')->first();
    }

    /** Fija el bot seleccionado si es un bot activo de la institución. */
    public static function set(int $botId): bool
    {
        $bot = Bot::query()->where('status', 'active')->find($botId);
        if ($bot === null) {
            return false;
        }
        session([self::SESSION_KEY => $bot->getKey()]);

        return true;
    }
}
