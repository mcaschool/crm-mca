<?php

declare(strict_types=1);

namespace Modules\Mcp\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Institutions\Models\Institution;
use Modules\Integrations\Models\Integration;
use Modules\Mcp\Models\McpAuditLog;
use Modules\Mcp\Models\McpClient;
use Modules\Mcp\Services\McpClientManager;
use Modules\Mcp\Support\ToolRegistry;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Panel de administración de la conexión ChatGPT / MCP (Configuración →
 * Integraciones). UI amigable para gestionar clientes MCP, probar el servidor
 * y revisar la actividad SIN SSH ni Artisan. Reutiliza McpClientManager (misma
 * lógica segura que `mcp:client`), McpClient, McpAuditLog y ToolRegistry.
 * Solo Administrador (canManageIntegrations). Nunca muestra hashes ni tokens
 * salvo el token recién emitido, una sola vez, en memoria.
 */
#[Layout('layouts.app')]
class Admin extends Component
{
    /** Vista activa: dashboard | clients | activity. */
    public string $tab = 'dashboard';

    // --- Wizard de creación ---
    public bool $showWizard = false;

    public int $step = 1;

    public string $connName = '';

    public string $scope = 'global'; // global | institution

    public ?int $scopeInstitutionId = null;

    /** Token recién emitido — SOLO en memoria, se muestra una vez y se limpia. */
    public ?string $freshToken = null;

    public ?string $freshClientName = null;

    // --- Confirmaciones de acciones ---
    public ?int $confirmRotateId = null;

    public ?int $confirmRevokeId = null;

    // --- Resultado del test ---
    /** @var array<string, mixed>|null */
    public ?array $testResult = null;

    // --- Filtros de actividad ---
    public string $fClient = '';

    public string $fTool = '';

    public string $fStatus = '';

    public function mount(): void
    {
        // Mismo gate que Integraciones (Admin / super-admin).
        abort_unless(auth()->user()?->canManageIntegrations() ?? false, 403);

        // Un admin de institución (no super) crea siempre para SU institución.
        if (! auth()->user()->isSuperAdmin()) {
            $this->scope = 'institution';
            $this->scopeInstitutionId = auth()->user()->institution_id;
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['dashboard', 'clients', 'activity'], true) ? $tab : 'dashboard';
    }

    // ---------------------------------------------------------- wizard

    public function openWizard(): void
    {
        $this->reset('connName', 'freshToken', 'freshClientName');
        $this->step = 1;
        if (auth()->user()?->isSuperAdmin() ?? false) {
            $this->scope = 'global';
            $this->scopeInstitutionId = null;
        }
        $this->showWizard = true;
    }

    public function closeWizard(): void
    {
        // Al cerrar se descarta el token de memoria (ya no se puede volver a ver).
        $this->reset('showWizard', 'step', 'connName', 'freshToken', 'freshClientName');
    }

    public function nextStep(): void
    {
        if ($this->step === 1) {
            $this->validate([
                'connName' => ['required', 'string', 'max:60', Rule::unique('mcp_clients', 'name')],
            ], attributes: ['connName' => __('nombre de la conexión')]);
        }
        if ($this->step === 2 && $this->scope === 'institution' && $this->scopeInstitutionId === null) {
            $this->addError('scopeInstitutionId', __('Selecciona una institución.'));

            return;
        }
        $this->step = min($this->step + 1, 3);
    }

    public function prevStep(): void
    {
        $this->step = max($this->step - 1, 1);
    }

    public function createConnection(McpClientManager $clients): void
    {
        abort_unless(auth()->user()?->canManageIntegrations() ?? false, 403);
        $this->validate([
            'connName' => ['required', 'string', 'max:60', Rule::unique('mcp_clients', 'name')],
        ], attributes: ['connName' => __('nombre de la conexión')]);

        // Un admin no-super jamás crea fuera de su institución (defensa en backend).
        $institutionId = null;
        if ($this->scope === 'institution') {
            $institutionId = auth()->user()->isSuperAdmin()
                ? $this->scopeInstitutionId
                : auth()->user()->institution_id;
            if ($institutionId === null) {
                $this->addError('scopeInstitutionId', __('Selecciona una institución.'));

                return;
            }
        }

        [$client, $token] = $clients->create($this->connName, $institutionId);
        $this->freshToken = $token;
        $this->freshClientName = $client->name;
        $this->step = 4; // pantalla "token una sola vez"
    }

    // ---------------------------------------------------------- rotar / revocar

    public function askRotate(int $id): void
    {
        $this->confirmRotateId = $id;
    }

    public function rotate(McpClientManager $clients): void
    {
        abort_unless(auth()->user()?->canManageIntegrations() ?? false, 403);
        $client = $this->ownedClient($this->confirmRotateId);
        $this->confirmRotateId = null;
        if ($client === null) {
            return;
        }
        $this->freshToken = $clients->rotate($client);
        $this->freshClientName = $client->name;
        $this->showWizard = true;
        $this->step = 4; // reutiliza la pantalla de "token una sola vez"
    }

    public function askRevoke(int $id): void
    {
        $this->confirmRevokeId = $id;
    }

    public function revoke(McpClientManager $clients): void
    {
        abort_unless(auth()->user()?->canManageIntegrations() ?? false, 403);
        $client = $this->ownedClient($this->confirmRevokeId);
        $this->confirmRevokeId = null;
        if ($client !== null) {
            $clients->revoke($client);
            session()->flash('mcpStatus', __('Conexión ":n" revocada. El acceso quedó desactivado.', ['n' => $client->name]));
        }
    }

    // ---------------------------------------------------------- probar servidor

    public function testServer(ToolRegistry $tools): void
    {
        $checks = [];
        try {
            DB::select('select 1');
            $checks['db'] = true;
        } catch (Throwable) {
            $checks['db'] = false;
        }
        $checks['tools'] = count($tools->list());
        $checks['active_clients'] = McpClient::query()->where('is_active', true)->count();
        $checks['ok'] = $checks['db'] && $checks['tools'] > 0 && $checks['active_clients'] > 0;
        $checks['reason'] = match (true) {
            ! $checks['db'] => __('La base de datos no responde.'),
            $checks['tools'] === 0 => __('El servidor no expone herramientas.'),
            $checks['active_clients'] === 0 => __('No hay ninguna conexión activa: crea una para poder conectar ChatGPT.'),
            default => '',
        };
        $this->testResult = $checks;
    }

    // ---------------------------------------------------------- descargar config

    public function downloadConfig(?int $id = null): StreamedResponse
    {
        abort_unless(auth()->user()?->canManageIntegrations() ?? false, 403);

        // Con token solo si es el recién emitido (en memoria); si no, placeholder.
        $token = $this->freshToken ?? '<MCP_TOKEN>';
        $name = $this->freshClientName ?? 'mca-crm';
        $endpoint = $this->endpoint();

        $toml = "# Configuración MCP para MCA CRM — servidor \"{$name}\"\n"
            ."# Pega esto en la config de tu cliente MCP (ChatGPT/Codex).\n"
            .($token === '<MCP_TOKEN>' ? "# Sustituye <MCP_TOKEN> por la clave que copiaste al crear la conexión.\n" : '')
            ."\n[mcp_servers.mca-crm]\n"
            ."url = \"{$endpoint}\"\n"
            ."# El token viaja en el header Authorization: Bearer\n"
            ."[mcp_servers.mca-crm.headers]\n"
            ."Authorization = \"Bearer {$token}\"\n";

        return response()->streamDownload(
            fn () => print ($toml),
            'mca-crm-mcp.toml',
            ['Content-Type' => 'text/plain; charset=utf-8'],
        );
    }

    public function render(): View
    {
        $isSuper = auth()->user()?->isSuperAdmin() ?? false;

        $clients = McpClient::query()
            ->orderByDesc('is_active')->orderBy('name')
            ->get()
            ->map(fn (McpClient $c): array => [
                'id' => $c->id,
                'name' => $c->name,
                'scope' => $c->isGlobal() ? __('Global') : ($this->institutionName($c->institution_id) ?? ('#'.$c->institution_id)),
                'active' => (bool) $c->is_active,
                'created' => $c->created_at,
                'last_used' => $c->last_used_at,
            ])->all();

        // Actividad (McpAuditLog) con filtros; params ya vienen saneados.
        $activity = McpAuditLog::query()
            ->when($this->fClient !== '', fn ($q) => $q->where('mcp_client_id', (int) $this->fClient))
            ->when($this->fTool !== '', fn ($q) => $q->where('tool', 'like', '%'.$this->fTool.'%'))
            ->when($this->fStatus !== '', fn ($q) => $q->where('status', $this->fStatus))
            ->orderByDesc('id')->limit(50)->get();

        $lastLog = McpAuditLog::query()->orderByDesc('id')->first();
        $lastError = McpAuditLog::query()->where('status', 'error')->orderByDesc('id')->first();

        $diagnostics = [
            'endpoint' => $this->endpoint(),
            'https' => str_starts_with($this->endpoint(), 'https://'),
            'db' => $this->dbOk(),
            'tools' => count(app(ToolRegistry::class)->list()),
            'active_clients' => collect($clients)->where('active', true)->count(),
            'total_clients' => count($clients),
            'last_activity' => $lastLog?->created_at,
            'last_error' => $lastError?->error,
            'last_error_at' => $lastError?->created_at,
        ];

        return view('mcp::admin', [
            'clientsRows' => $clients,
            'clientsForFilter' => McpClient::query()->orderBy('name')->get(['id', 'name']),
            'activity' => $activity,
            'diagnostics' => $diagnostics,
            'institutions' => $isSuper ? Institution::query()->orderBy('name')->get(['id', 'name']) : collect(),
            'isSuper' => $isSuper,
            'integration' => Integration::query()->where('type', 'mcp')->first(),
        ]);
    }

    // ---------------------------------------------------------- helpers

    private function endpoint(): string
    {
        return url('/api/mcp');
    }

    private function dbOk(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** Cliente que el usuario actual puede administrar (super = todos; admin = su institución). */
    private function ownedClient(?int $id): ?McpClient
    {
        if ($id === null) {
            return null;
        }
        $client = McpClient::query()->find($id);
        if ($client === null) {
            return null;
        }
        if (! (auth()->user()?->isSuperAdmin() ?? false)
            && $client->institution_id !== auth()->user()?->institution_id) {
            return null; // un admin de institución no toca clientes de otra ni globales
        }

        return $client;
    }

    private function institutionName(?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        return Institution::query()->whereKey($id)->value('name');
    }
}
