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
use Modules\Social\Livewire\Concerns\ConnectsMeta;
use Modules\Social\Models\MetaConnection;
use Modules\Social\Models\MetaLeadForm;
use Modules\Social\Models\MetaLeadPage;
use Modules\Social\Models\MetaLeadReceipt;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Services\MetaConnectionService;
use Modules\Social\Services\MetaLeadFormService;
use Modules\Social\Services\MetaLeadPageService;
use Modules\Social\Support\MetaLeadAccessGuidance;

/**
 * «Formularios publicitarios»: ASISTENTE de la empresa activa (Facebook e Instagram → CRM).
 *
 *   1 Conectar Meta → 2 Elegir Página y formularios → 3 Asignar destino → 4 Comprobar acceso
 *   → 5 Activar recepción
 *
 * Todo desde el panel, también lo habitual después: reconectar, pausar, buscar contactos ahora,
 * diagnóstico y transferir una Página a esta empresa. Solo Administrador. Los registros se buscan
 * con el ámbito de la empresa: nunca se ven ni modifican Páginas, formularios o contactos de otra.
 */
#[Layout('layouts.app')]
class LeadForms extends Component
{
    use ConnectsMeta;

    public ?string $notice = null;

    public ?string $error = null;

    public function mount(): void
    {
        $this->authorize('viewAny', SocialChannel::class);
    }

    // ───────────────────────────── 1. Conectar Meta

    /** El botón «Conectar Meta / Reconectar Meta» del asistente (Facebook Login for Business). */
    public function connect(string $state, string $code = '', string $accessToken = ''): void
    {
        $result = $this->completeMetaConnection($state, $code, $accessToken);
        is_string($result)
            ? $this->fail($result)
            : $this->say(trans_choice('Conexión guardada: :n Página disponible.|Conexión guardada: :n Páginas disponibles.', count($result->pages), ['n' => count($result->pages)]));
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

    // ───────────────────────────── 2. Elegir Página y formularios

    public function selectPage(int $pageId, bool $on, MetaLeadPageService $pages): void
    {
        $page = $this->page($pageId);
        $this->attempt(fn () => $pages->select($page, $on), $on ? __('Página elegida para formularios.') : __('La Página ya no se usa para formularios.'));
    }

    public function transfer(int $pageId, MetaLeadPageService $pages): void
    {
        $page = $this->page($pageId);
        $this->attempt(fn () => $pages->transfer($page), __('La Página ya envía sus contactos a tu empresa. Comprueba el acceso para activar la recepción.'));
    }

    public function sync(int $pageId, MetaLeadFormService $service): void
    {
        $result = $service->syncForms($this->page($pageId));
        $result['ok'] ? $this->say($result['message']) : $this->fail($result['message']);
    }

    /** Usar (o no) un formulario: sus contactos se reciben cuando tenga destino y la Página reciba. */
    public function chooseForm(int $formId, bool $on): void
    {
        $form = $this->form($formId);
        $form->is_active = $on;
        if ($on && $form->receiving_since === null) {
            $form->receiving_since = now(); // los contactos se reciben desde que se elige
        }
        $form->save();
    }

    // ───────────────────────────── 3. Asignar destino

    /** Destino del formulario: un programa de la empresa, «general» o '' (sin asignar). */
    public function setDestination(int $formId, string $value): void
    {
        $form = $this->form($formId);
        if ($value === 'general') {
            $form->forceFill(['destination' => 'general', 'program_id' => null]);
        } elseif ($value !== '' && Program::query()->whereKey((int) $value)->exists()) { // solo programas de ESTA empresa
            $form->forceFill(['destination' => 'program', 'program_id' => (int) $value]);
        } else {
            $form->forceFill(['destination' => null, 'program_id' => null]);
        }
        $form->save();
    }

    public function setAdvisor(int $formId, string $botId): void
    {
        $form = $this->form($formId);
        $form->bot_id = $botId !== '' && Bot::query()->whereKey((int) $botId)->where('type', '!=', 'human')->exists() ? (int) $botId : null;
        $form->save();
    }

    // ───────────────────────────── 4. Comprobar acceso

    public function checkAccess(int $pageId, MetaLeadPageService $pages): void
    {
        $page = $this->page($pageId);
        $this->attempt(fn () => $pages->verify($page), __('Comprobación terminada.'));
    }

    /** Crea un contacto de PRUEBA de Meta en un formulario de la Página, lo lee y lo borra. */
    public function testLead(int $pageId, MetaLeadPageService $pages): void
    {
        $page = $this->page($pageId);
        $this->attempt(fn () => $pages->verify($page, true), __('Prueba con contacto de prueba terminada.'));
    }

    // ───────────────────────────── 5. Activar recepción (y operación diaria)

    public function setReceiving(int $pageId, bool $on, MetaLeadPageService $pages): void
    {
        $page = $this->page($pageId);
        $this->attempt(fn () => $pages->setReceiving($page, $on), $on ? __('Recepción de contactos activada.') : __('Recepción de contactos en pausa.'));
    }

    public function fetchNow(int $pageId, MetaLeadFormService $service): void
    {
        $page = $this->page($pageId);
        if (! $page->receiving()) {
            $this->fail(__('Activa la recepción de esta Página para buscar contactos.'));

            return;
        }
        $n = $service->fetchNow($page);
        $page->refresh();
        $page->access_status === 'failed'
            ? $this->fail((string) $page->last_error)
            : $this->say(trans_choice('Búsqueda terminada: :n contacto nuevo.|Búsqueda terminada: :n contactos nuevos.', $n, ['n' => $n]));
    }

    public function render(MetaLeadFormService $service, MetaLeadPageService $pageService): View
    {
        $connection = MetaConnection::query()->with('connectedBy:id,name')->first();
        $pages = MetaLeadPage::query()->with('metaConnection')->orderByDesc('selected')->orderBy('name')->get();
        $forms = MetaLeadForm::query()->whereNotNull('meta_lead_page_id')->orderBy('name')->get();
        $isOperator = (bool) auth()->user()?->isSuperAdmin();

        return view('social::lead-forms', [
            'platformReady' => $this->platformReady(),
            'killSwitch' => $service->killSwitch(),
            'connection' => $connection,
            'pages' => $pages,
            'takenElsewhere' => $pages->filter(fn (MetaLeadPage $p): bool => ! $p->selected && $pageService->takenElsewhere($p))->pluck('id')->all(),
            'forms' => $forms->groupBy('meta_lead_page_id'),
            'steps' => $this->steps($connection, $pages, $forms),
            'programs' => Program::query()->where('status', 'active')->orderBy('name_es')->get(['id', 'code', 'name_es']),
            'bots' => Bot::query()->where('type', '!=', 'human')->orderBy('assistant_name')->get(['id', 'assistant_name']),
            'receipts' => MetaLeadReceipt::query()->latest('id')->limit(15)->get(),
            'isOperator' => $isOperator,
            'platformIssues' => $isOperator ? $this->platformIssues() : collect(),
            'neededPermissions' => MetaLeadAccessGuidance::REQUIRED_PERMISSIONS,
        ]);
    }

    /**
     * Avance del asistente según el estado REAL de la empresa (no se guarda aparte).
     *
     * @param  Collection<int, MetaLeadPage>  $pages
     * @param  Collection<int, MetaLeadForm>  $forms
     * @return array{done: array<int, bool>, current: int}
     */
    private function steps(?MetaConnection $connection, Collection $pages, Collection $forms): array
    {
        $selected = $pages->where('selected', true)->pluck('id')->all();
        $chosen = $forms->whereIn('meta_lead_page_id', $selected)->where('is_active', true);

        $done = [
            1 => $connection !== null && $connection->usable(),
            2 => $selected !== [] && $chosen->isNotEmpty(),
            3 => $chosen->isNotEmpty() && $chosen->every(fn (MetaLeadForm $f): bool => $f->hasDestination()),
            4 => $pages->contains(fn (MetaLeadPage $p): bool => $p->selected && $p->verified()),
            5 => $pages->contains(fn (MetaLeadPage $p): bool => $p->receiving()),
        ];
        $current = collect($done)->search(false);

        return ['done' => $done, 'current' => $current === false ? 6 : (int) $current];
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
