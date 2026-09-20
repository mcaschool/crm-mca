<?php

declare(strict_types=1);

namespace Modules\Mcp\Console;

use Illuminate\Console\Command;
use Modules\Mcp\Models\McpClient;
use Modules\Mcp\Services\McpClientManager;

/**
 * Gestión de credenciales del servidor MCP:
 *   mcp:client create NOMBRE [--institution=ID]   emite un token (se muestra UNA vez)
 *   mcp:client rotate NOMBRE                      invalida el token anterior y emite otro
 *   mcp:client revoke NOMBRE                      desactiva el cliente (revocación inmediata)
 *   mcp:client list                               lista clientes (sin tokens: solo hashes truncados)
 */
final class McpClientCommand extends Command
{
    protected $signature = 'mcp:client {action : create|rotate|revoke|list} {name?} {--institution=}';

    protected $description = 'Gestiona los clientes (Bearer) del servidor MCP privado';

    public function __construct(private readonly McpClientManager $clients)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $name = (string) ($this->argument('name') ?? '');

        return match ($action) {
            'create' => $this->create($name),
            'rotate' => $this->rotate($name),
            'revoke' => $this->revoke($name),
            'list' => $this->listClients(),
            default => $this->failWith("Acción desconocida: {$action} (usa create|rotate|revoke|list)"),
        };
    }

    private function create(string $name): int
    {
        if ($name === '') {
            return $this->failWith('Falta el nombre del cliente.');
        }
        if (McpClient::query()->where('name', $name)->exists()) {
            return $this->failWith("Ya existe un cliente '{$name}' (usa rotate para renovar su token).");
        }

        $institution = $this->option('institution');
        [, $token] = $this->clients->create($name, is_numeric($institution) ? (int) $institution : null);

        $this->info("Cliente MCP '{$name}' creado".(is_numeric($institution) ? " (institución {$institution})" : ' (GLOBAL)').'.');
        $this->newLine();
        $this->warn('Token (se muestra UNA sola vez, guárdalo en el cliente MCP):');
        $this->line($token);

        return self::SUCCESS;
    }

    private function rotate(string $name): int
    {
        $client = McpClient::query()->where('name', $name)->first();
        if ($client === null) {
            return $this->failWith("No existe el cliente '{$name}'.");
        }

        $token = $this->clients->rotate($client);

        $this->info("Token de '{$name}' ROTADO (el anterior quedó invalidado).");
        $this->newLine();
        $this->warn('Nuevo token (se muestra UNA sola vez):');
        $this->line($token);

        return self::SUCCESS;
    }

    private function revoke(string $name): int
    {
        $client = McpClient::query()->where('name', $name)->first();
        if ($client === null) {
            return $this->failWith("No existe el cliente '{$name}'.");
        }
        $this->clients->revoke($client);
        $this->info("Cliente '{$name}' revocado (acceso denegado de inmediato).");

        return self::SUCCESS;
    }

    private function listClients(): int
    {
        $rows = McpClient::query()->orderBy('name')->get()->map(fn (McpClient $c): array => [
            $c->name,
            $c->institution_id === null ? 'GLOBAL' : (string) $c->institution_id,
            $c->is_active ? 'activo' : 'revocado',
            substr($c->token_hash, 0, 8).'…',
            (string) ($c->last_used_at?->diffForHumans() ?? 'nunca'),
        ])->all();

        $this->table(['Nombre', 'Institución', 'Estado', 'Hash', 'Último uso'], $rows);

        return self::SUCCESS;
    }

    private function failWith(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
