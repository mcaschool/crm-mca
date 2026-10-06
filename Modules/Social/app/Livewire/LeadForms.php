<?php

declare(strict_types=1);

namespace Modules\Social\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Catalog\Models\Program;
use Modules\Institutions\Models\Bot;
use Modules\Social\Models\MetaLeadForm;
use Modules\Social\Models\MetaLeadReceipt;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Services\MetaLeadAccessCheck;
use Modules\Social\Services\MetaLeadFormService;

/**
 * «Formularios publicitarios» (Facebook e Instagram → CRM): por formulario, el programa y el
 * asesor responsable con los que entran sus contactos, y los últimos contactos recibidos.
 * Preparado y desactivado mientras Meta no apruebe el acceso a los formularios. Solo Admin.
 */
#[Layout('layouts.app')]
class LeadForms extends Component
{
    public ?string $notice = null;

    /**
     * Resultado de «Comprobar acceso» por Página: estado de cada causa (ok|fail|unknown) y
     * formularios encontrados. Solo lectura en Meta; no depende de la aprobación.
     *
     * @var array<int, array{page: string, business: string, app: string, forms: int|null}>
     */
    public array $access = [];

    public function mount(): void
    {
        $this->authorize('viewAny', SocialChannel::class);
    }

    /** «Actualizar formularios» de una Página. */
    public function sync(int $channelId, MetaLeadFormService $service): void
    {
        $page = SocialChannel::query()->where('provider', 'messenger')->findOrFail($channelId);
        $this->authorize('update', $page);

        $this->notice = $service->syncForms($page)['message'];
    }

    /** «Comprobar acceso»: con la conexión actual de la Página, sin activar nada. */
    public function checkAccess(int $channelId, MetaLeadAccessCheck $check): void
    {
        $page = SocialChannel::query()->where('provider', 'messenger')->findOrFail($channelId);
        $this->authorize('update', $page);

        $result = $check->run($page);
        $forms = collect($result['steps'])->firstWhere('step', 'forms');
        $this->access[$channelId] = [
            'page' => $result['verdict']['page']['status'],
            'business' => $result['verdict']['business']['status'],
            'app' => $result['verdict']['app']['status'],
            'forms' => ($forms['ok'] ?? false) ? count((array) ($forms['response']['data'] ?? [])) : null,
        ];
    }

    public function setProgram(int $formId, string $programId): void
    {
        $form = $this->form($formId);
        $form->program_id = $programId !== '' && Program::query()->whereKey((int) $programId)->exists() ? (int) $programId : null;
        $form->save();
    }

    public function setAdvisor(int $formId, string $botId): void
    {
        $form = $this->form($formId);
        $form->bot_id = $botId !== '' && Bot::query()->whereKey((int) $botId)->exists() ? (int) $botId : null;
        $form->save();
    }

    public function toggle(int $formId): void
    {
        $form = $this->form($formId);
        if (! $form->is_active && $form->program_id === null) {
            $this->notice = __('Asigna un programa antes de activar el formulario.');

            return;
        }
        $form->is_active = ! $form->is_active;
        $form->save();
    }

    public function render(MetaLeadFormService $service): View
    {
        return view('social::lead-forms', [
            'enabled' => $service->enabled(),
            'pages' => SocialChannel::query()->where('provider', 'messenger')->orderBy('display_name')->get(['id', 'display_name']),
            'forms' => MetaLeadForm::query()->with('channel:id,display_name')->orderBy('name')->get(),
            'programs' => Program::query()->where('status', 'active')->orderBy('name_es')->get(['id', 'code', 'name_es']),
            'bots' => Bot::query()->where('type', '!=', 'human')->orderBy('assistant_name')->get(['id', 'assistant_name']),
            'receipts' => MetaLeadReceipt::query()->latest('id')->limit(15)->get(),
        ]);
    }

    private function form(int $formId): MetaLeadForm
    {
        $form = MetaLeadForm::query()->with('channel')->findOrFail($formId);
        $this->authorize('update', $form->channel);

        return $form;
    }
}
