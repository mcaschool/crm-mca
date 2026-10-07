<?php

declare(strict_types=1);

namespace Modules\Social\Livewire;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Catalog\Models\Program;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Bot;
use Modules\Social\Models\MetaConnection;
use Modules\Social\Models\MetaLeadForm;
use Modules\Social\Models\MetaLeadPage;
use Modules\Social\Models\MetaLeadReceipt;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Services\MetaConnectionService;
use Modules\Social\Services\MetaLeadFormService;
use Modules\Social\Services\MetaLeadPageService;

/**
 * «Formularios publicitarios» de la EMPRESA activa (Facebook e Instagram → CRM). Todo desde el
 * panel: Conectar Meta → elegir Páginas y formularios → asignar programa y asesor → comprobar el
 * acceso → activar la recepción. Solo Administrador. Todos los registros se buscan con el ámbito
 * de la empresa: una empresa nunca ve ni modifica Páginas, formularios o contactos de otra.
 */
#[Layout('layouts.app')]
class LeadForms extends Component
{
    public ?string $notice = null;

    public ?string $error = null;

    public function mount(): void
    {
        $this->authorize('viewAny', SocialChannel::class);
    }

    public function selectPage(int $pageId, bool $on, MetaLeadPageService $pages): void
    {
        $page = $this->page($pageId);
        $this->attempt(fn () => $pages->select($page, $on), $on ? __('Página elegida para formularios.') : __('La Página ya no se usa para formularios.'));
    }

    public function sync(int $pageId, MetaLeadFormService $service): void
    {
        $result = $service->syncForms($this->page($pageId));
        $result['ok'] ? $this->say($result['message']) : $this->fail($result['message']);
    }

    public function checkAccess(int $pageId, MetaLeadPageService $pages): void
    {
        $page = $this->page($pageId);
        $this->attempt(fn () => $pages->verify($page), __('Comprobación terminada.'));
    }

    /** Crea un contacto de PRUEBA de Meta en el primer formulario, lo lee y lo borra. */
    public function testLead(int $pageId, MetaLeadPageService $pages): void
    {
        $page = $this->page($pageId);
        $this->attempt(fn () => $pages->verify($page, true), __('Prueba con contacto de prueba terminada.'));
    }

    public function setReceiving(int $pageId, bool $on, MetaLeadPageService $pages): void
    {
        $page = $this->page($pageId);
        $this->attempt(fn () => $pages->setReceiving($page, $on), $on ? __('Recepción de contactos activada.') : __('Recepción de contactos detenida.'));
    }

    public function setProgram(int $formId, string $programId): void
    {
        $form = $this->form($formId);
        // Solo programas de ESTA empresa (el ámbito lo garantiza).
        $form->program_id = $programId !== '' && Program::query()->whereKey((int) $programId)->exists() ? (int) $programId : null;
        if ($form->program_id === null) {
            $form->is_active = false;
        }
        $form->save();
    }

    public function setAdvisor(int $formId, string $botId): void
    {
        $form = $this->form($formId);
        $form->bot_id = $botId !== '' && Bot::query()->whereKey((int) $botId)->where('type', '!=', 'human')->exists() ? (int) $botId : null;
        $form->save();
    }

    public function toggle(int $formId): void
    {
        $form = $this->form($formId);
        if (! $form->is_active && $form->program_id === null) {
            $this->fail(__('Asigna un programa antes de activar el formulario.'));

            return;
        }
        $form->is_active = ! $form->is_active;
        if ($form->is_active && $form->receiving_since === null) {
            $form->receiving_since = now(); // los contactos se reciben desde que se activa
        }
        $form->save();
    }

    public function refreshConnection(MetaConnectionService $service): void
    {
        $this->authorize('create', SocialChannel::class);
        $connection = MetaConnection::query()->first();
        if ($connection !== null) {
            $service->refreshStatus($connection);
            $this->say(__('Estado de la conexión actualizado.'));
        }
    }

    public function disconnect(MetaConnectionService $service): void
    {
        $this->authorize('create', SocialChannel::class);
        $connection = MetaConnection::query()->first();
        if ($connection !== null) {
            $service->disconnect($connection);
            $this->say(__('Meta desconectado: la recepción de contactos se detuvo. Tu configuración se conserva para cuando vuelvas a conectar.'));
        }
    }

    public function render(MetaLeadFormService $service, MetaConnectionService $connections): View
    {
        $connection = MetaConnection::query()->with('connectedBy:id,name')->first();
        $pages = MetaLeadPage::query()->with('metaConnection')->orderByDesc('selected')->orderBy('name')->get();
        $isOperator = (bool) auth()->user()?->isSuperAdmin();

        return view('social::lead-forms', [
            'platformReady' => $connections->isPlatformConfigured(),
            'killSwitch' => $service->killSwitch(),
            'connection' => $connection,
            'pages' => $pages,
            'forms' => MetaLeadForm::query()->whereNotNull('meta_lead_page_id')->orderBy('name')->get()->groupBy('meta_lead_page_id'),
            'programs' => Program::query()->where('status', 'active')->orderBy('name_es')->get(['id', 'code', 'name_es']),
            'bots' => Bot::query()->where('type', '!=', 'human')->orderBy('assistant_name')->get(['id', 'assistant_name']),
            'receipts' => MetaLeadReceipt::query()->latest('id')->limit(15)->get(),
            'isOperator' => $isOperator,
            'platformIssues' => $isOperator ? $this->platformIssues() : collect(),
        ]);
    }

    /**
     * Para el OPERADOR: permisos de la aplicación del CRM que Meta deniega, en todas las empresas
     * (sin datos de ninguna: solo el permiso y cuántas Páginas lo sufren).
     *
     * @return Collection<string, int>
     */
    private function platformIssues(): Collection
    {
        $results = app(CurrentInstitution::class)->runGlobally(fn () => MetaLeadPage::query()
            ->where('selected', true)->whereNotNull('access_result')->pluck('access_result'));

        return $results
            ->flatMap(fn ($r): array => array_filter((array) ($r['issues'] ?? []), fn ($i): bool => is_array($i) && ($i['who'] ?? null) === 'platform' && ($i['area'] ?? null) === 'app'))
            ->flatMap(fn (array $i): array => $i['permissions'] !== [] ? $i['permissions'] : ['(sin nombre)'])
            ->countBy();
    }

    private function page(int $pageId): MetaLeadPage
    {
        $this->authorize('create', SocialChannel::class);

        return MetaLeadPage::query()->with('metaConnection')->findOrFail($pageId); // ámbito de la empresa
    }

    private function form(int $formId): MetaLeadForm
    {
        $this->authorize('create', SocialChannel::class);

        return MetaLeadForm::query()->whereNotNull('meta_lead_page_id')->findOrFail($formId); // ámbito de la empresa
    }

    private function attempt(callable $action, string $ok): void
    {
        try {
            $action();
            $this->say($ok);
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
    }

    private function say(string $message): void
    {
        $this->notice = $message;
        $this->error = null;
    }

    private function fail(string $message): void
    {
        $this->error = $message;
        $this->notice = null;
    }
}
