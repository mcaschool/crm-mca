<?php

declare(strict_types=1);

namespace Modules\Mcp\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Institutions\Models\Institution;
use Modules\Mcp\Models\McpAuditLog;
use Modules\Mcp\Models\McpClient;
use Modules\Mcp\Services\McpClientManager;
use Modules\Mcp\Support\ToolRegistry;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * "Conexiones IA" (Configuración → IA / MCP). Presenta tres presets —ChatGPT,
 * Claude y Claude Code— sobre UN solo servidor MCP. Claude Code se conecta ya
 * (Bearer, perfil técnico); ChatGPT y Claude quedan "preparados" a la espera
 * del flujo OAuth (Fase B). Los perfiles de acceso los aplica el servidor, no
 * esta pantalla. Solo Administrador (canManageIntegrations).
 */
#[Layout('layouts.app')]
class Admin extends Component
{
    /** dashboard | connections | activity */
    public string $tab = 'dashboard';

    // --- Modal informativo de presets pendientes de OAuth ---
    public ?string $showInfo = null; // chatgpt | claude

    // --- Wizard de Claude Code ---
    public bool $showWizard = false;

    public int $step = 1;

    public string $connName = '';

    public string $scope = 'global'; // global | institution

    public ?int $scopeInstitutionId = null;

    public ?string $freshToken = null;

    public ?string $freshClientName = null;

    // --- Confirmaciones ---
    public ?int $confirmRotateId = null;

    public ?int $confirmRevokeId = null;

    // --- Filtros de actividad ---
    public string $fClient = '';

    public string $fTool = '';

    public string $fStatus = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->canManageIntegrations(), 403);
        if (! auth()->user()->isSuperAdmin()) {
            $this->scope = 'institution';
            $this->scopeInstitutionId = auth()->user()->institution_id;
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['dashboard', 'connections', 'activity'], true) ? $tab : 'dashboard';
    }

    // ------------------------------------------------- presets ChatGPT / Claude

    public function showPreset(string $assistant): void
    {
        $this->showInfo = in_array($assistant, ['chatgpt', 'claude'], true) ? $assistant : null;
    }

    public function closePreset(): void
    {
        $this->showInfo = null;
    }

    // ------------------------------------------------- wizard Claude Code

    public function connectClaudeCode(): void
    {
        $this->reset('connName', 'freshToken', 'freshClientName');
        $this->step = 1;
        if (auth()->user()->isSuperAdmin()) {
            $this->scope = 'global';
            $this->scopeInstitutionId = null;
        }
        $this->showWizard = true;
    }

    public function closeWizard(): void
    {
        $this->reset('showWizard', 'step', 'connName', 'freshToken', 'freshClientName');
    }

    public function nextStep(): void
    {
        if ($this->step === 1) {
            $this->validate(['connName' => ['required', 'string', 'max:60', Rule::unique('mcp_clients', 'name')]]);
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
        abort_unless(auth()->user()->canManageIntegrations(), 403);
        $this->validate(['connName' => ['required', 'string', 'max:60', Rule::unique('mcp_clients', 'name')]]);

        $institutionId = null;
        if ($this->scope === 'institution') {
            $institutionId = auth()->user()->isSuperAdmin() ? $this->scopeInstitutionId : auth()->user()->institution_id;
            if ($institutionId === null) {
                $this->addError('scopeInstitutionId', __('Selecciona una institución.'));

                return;
            }
        }

        // Claude Code = perfil TÉCNICO con escritura DESACTIVADA por defecto.
        [$client, $token] = $clients->create(
            name: $this->connName,
            institutionId: $institutionId,
            assistantType: 'claude_code',
            profile: McpClient::PROFILE_TECHNICAL,
            allowWrite: false,
            authKind: 'bearer',
        );
        $this->freshToken = $token;
        $this->freshClientName = $client->name;
        $this->step = 4;
    }

    // ------------------------------------------------- rotar / revocar / permisos

    public function askRotate(int $id): void
    {
        $this->confirmRotateId = $id;
    }

    public function rotate(McpClientManager $clients): void
    {
        abort_unless(auth()->user()->canManageIntegrations(), 403);
        $client = $this->ownedClient($this->confirmRotateId);
        $this->confirmRotateId = null;
        if ($client === null || $client->auth_kind !== 'bearer') {
            return; // rotar solo aplica a credenciales Bearer
        }
        $this->freshToken = $clients->rotate($client);
        $this->freshClientName = $client->name;
        $this->showWizard = true;
        $this->step = 4;
    }

    public function askRevoke(int $id): void
    {
        $this->confirmRevokeId = $id;
    }

    public function revoke(McpClientManager $clients): void
    {
        abort_unless(auth()->user()->canManageIntegrations(), 403);
        $client = $this->ownedClient($this->confirmRevokeId);
        $this->confirmRevokeId = null;
        if ($client !== null) {
            $clients->revoke($client);
            session()->flash('mcpStatus', __('Conexión ":n" desconectada.', ['n' => $client->name]));
        }
    }

    /** Activa/desactiva las acciones técnicas de escritura (solo perfil técnico). */
    public function togglePermissions(int $id): void
    {
        abort_unless(auth()->user()->canManageIntegrations(), 403);
        $client = $this->ownedClient($id);
        if ($client === null || $client->effectiveProfile() !== McpClient::PROFILE_TECHNICAL) {
            return;
        }
        $client->forceFill(['allow_write' => ! $client->allow_write])->save();
        session()->flash('mcpStatus', $client->allow_write
            ? __('Acciones técnicas ACTIVADAS para ":n" (las confirmaciones de seguridad siguen vigentes).', ['n' => $client->name])
            : __('Acciones técnicas desactivadas para ":n".', ['n' => $client->name]));
    }

    // ------------------------------------------------- probar

    /** Prueba amigable del servidor (equivalente a un ping): BD + herramientas. */
    public function testServer(ToolRegistry $tools): void
    {
        $db = true;
        try {
            DB::select('select 1');
        } catch (Throwable) {
            $db = false;
        }
        $toolCount = count($tools->list());
        session()->flash('mcpStatus', $db && $toolCount > 0
            ? __('Servidor MCP operativo: :n herramientas disponibles.', ['n' => $toolCount])
            : __('No se pudo verificar el servidor: la base de datos no responde.'));
    }

    // ------------------------------------------------- descargas Claude Code

    /**
     * Instalador de Claude Code. NO incrusta el token: el script PIDE la clave
     * localmente al ejecutarse (entrada silenciosa), la usa solo en memoria para
     * registrar el servidor MCP y no la imprime ni la guarda. El endpoint (no
     * secreto) sí va en el archivo.
     */
    public function downloadInstaller(string $os): StreamedResponse
    {
        abort_unless(auth()->user()->canManageIntegrations(), 403);
        $endpoint = $this->endpoint();

        if ($os === 'windows') {
            // PowerShell: Read-Host -AsSecureString; la clave solo vive en memoria.
            $body = "# Instalador MCA CRM para Claude Code (Windows)\r\n"
                ."# Pide la clave al ejecutarse; no la muestra ni la guarda.\r\n"
                ."\$sec = Read-Host -AsSecureString 'Pega la clave de acceso de MCA CRM'\r\n"
                ."\$bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR(\$sec)\r\n"
                ."\$key = [Runtime.InteropServices.Marshal]::PtrToStringBSTR(\$bstr)\r\n"
                ."claude mcp add mca-crm --transport http \"{$endpoint}\" --header \"Authorization: Bearer \$key\"\r\n"
                ."[Runtime.InteropServices.Marshal]::ZeroFreeBSTR(\$bstr)\r\n"
                ."Remove-Variable key\r\n"
                ."Write-Host 'Conexion agregada. Abre Claude Code y usa MCA CRM.'\r\n";

            return response()->streamDownload(fn () => print ($body), 'conectar-mca-crm.ps1', ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        // macOS/Linux: entrada silenciosa con read -s; clave solo en memoria.
        $body = "#!/bin/bash\n"
            ."# Instalador MCA CRM para Claude Code (macOS/Linux)\n"
            ."# Pide la clave al ejecutarse; no la muestra ni la guarda.\n"
            ."read -s -p 'Pega la clave de acceso de MCA CRM: ' KEY; echo\n"
            ."claude mcp add mca-crm --transport http \"{$endpoint}\" --header \"Authorization: Bearer \$KEY\"\n"
            ."unset KEY\n"
            ."echo 'Conexion agregada. Abre Claude Code y usa MCA CRM.'\n";

        return response()->streamDownload(fn () => print ($body), 'conectar-mca-crm.sh', ['Content-Type' => 'application/x-sh']);
    }

    public function render(): View
    {
        $isSuper = auth()->user()->isSuperAdmin();

        // Estado por preset (¿hay una conexión activa de ese asistente?).
        $active = McpClient::query()->where('is_active', true)
            ->selectRaw('assistant_type, count(*) as n')->groupBy('assistant_type')
            ->pluck('n', 'assistant_type');
        $presets = [
            'chatgpt' => $active->get('chatgpt', 0) > 0,
            'claude' => $active->get('claude', 0) > 0,
            'claude_code' => $active->get('claude_code', 0) > 0,
        ];

        $connections = McpClient::query()->orderByDesc('is_active')->orderBy('name')->get()
            ->map(fn (McpClient $c): array => [
                'id' => $c->id,
                'assistant' => $c->assistant_type,
                'name' => $c->name,
                'institution' => $c->isGlobal() ? __('Global') : ($this->institutionName($c->institution_id) ?? ('#'.$c->institution_id)),
                'access' => $c->effectiveProfile() === McpClient::PROFILE_TECHNICAL
                    ? __('Técnico').($c->allow_write ? ' · '.__('escritura') : '')
                    : __('Inspección'),
                'active' => (bool) $c->is_active,
                'bearer' => $c->auth_kind === 'bearer',
                'technical' => $c->effectiveProfile() === McpClient::PROFILE_TECHNICAL,
                'allow_write' => (bool) $c->allow_write,
                'created' => $c->created_at,
                'last_used' => $c->last_used_at,
            ])->all();

        $activity = McpAuditLog::query()
            ->when($this->fClient !== '', fn ($q) => $q->where('mcp_client_id', (int) $this->fClient))
            ->when($this->fTool !== '', fn ($q) => $q->where('tool', 'like', '%'.$this->fTool.'%'))
            ->when($this->fStatus !== '', fn ($q) => $q->where('status', $this->fStatus))
            ->orderByDesc('id')->limit(50)->get();

        $lastLog = McpAuditLog::query()->orderByDesc('id')->first();

        return view('mcp::admin', [
            'presets' => $presets,
            'connections' => $connections,
            'clientsForFilter' => McpClient::query()->orderBy('name')->get(['id', 'name']),
            'activity' => $activity,
            'institutions' => $isSuper ? Institution::query()->orderBy('name')->get(['id', 'name']) : collect(),
            'isSuper' => $isSuper,
            'tech' => [
                'endpoint' => $this->endpoint(),
                'https' => str_starts_with($this->endpoint(), 'https://'),
                'tools' => count(app(ToolRegistry::class)->list()),
                'last_activity' => $lastLog?->created_at,
            ],
        ]);
    }

    // ------------------------------------------------- helpers

    private function endpoint(): string
    {
        return url('/api/mcp');
    }

    private function ownedClient(?int $id): ?McpClient
    {
        if ($id === null) {
            return null;
        }
        $client = McpClient::query()->find($id);
        if ($client === null) {
            return null;
        }
        if (! auth()->user()->isSuperAdmin() && $client->institution_id !== auth()->user()->institution_id) {
            return null;
        }

        return $client;
    }

    private function institutionName(?int $id): ?string
    {
        return $id === null ? null : Institution::query()->whereKey($id)->value('name');
    }
}
