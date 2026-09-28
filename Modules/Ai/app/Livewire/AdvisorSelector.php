<?php

declare(strict_types=1);

namespace Modules\Ai\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Ai\Support\SelectedAdvisor;

/**
 * Selector de agente reutilizable (dropdown) para la zona de IA. Al cambiar, persiste el bot
 * en sesión (SelectedAdvisor) y recarga la página actual para que toda la pantalla trabaje
 * con el agente elegido.
 */
class AdvisorSelector extends Component
{
    public ?int $botId = null;

    /** URL de la página que contiene el selector (a la que se vuelve tras cambiar). */
    #[Locked]
    public string $returnUrl = '';

    public function mount(): void
    {
        $this->botId = SelectedAdvisor::current()?->getKey();
        $this->returnUrl = url()->current();
    }

    public function updatedBotId(mixed $value): void
    {
        if (is_numeric($value) && SelectedAdvisor::set((int) $value)) {
            $this->redirect($this->returnUrl !== '' ? $this->returnUrl : url()->previous());
        }
    }

    public function render(): View
    {
        return view('ai::livewire.advisor-selector', [
            'options' => SelectedAdvisor::options(),
        ]);
    }
}
