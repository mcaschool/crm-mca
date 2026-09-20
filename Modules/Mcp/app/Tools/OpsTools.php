<?php

declare(strict_types=1);

namespace Modules\Mcp\Tools;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Modules\Integrations\Models\Integration;
use Modules\Mcp\Support\McpContext;
use Modules\Mcp\Support\McpToolException;
use Modules\Mcp\Support\OperationRegistry;
use Modules\Mcp\Support\SecretRedactor;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialMessage;
use SplFileObject;
use Throwable;

/**
 * Operación y diagnóstico: ejecutar acciones reales del CRM (registro único),
 * logs, colas, scheduler, integraciones, salud y git. Las operaciones
 * peligrosas exigen confirm: true. Nada de esto expone secretos.
 */
final class OpsTools
{
    public function __construct(private readonly OperationRegistry $operations) {}

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function execute(array $args, McpContext $ctx): array
    {
        $operation = trim((string) ($args['operation'] ?? ''));
        if ($operation === '') {
            return ['operations' => $this->operations->catalog()];
        }

        $catalog = $this->operations->catalog();
        if (! isset($catalog[$operation])) {
            throw new McpToolException('Operación desconocida: '.$operation.'. Llama sin operation para ver el catálogo.');
        }
        if ($catalog[$operation]['dangerous'] && ($args['confirm'] ?? null) !== true) {
            throw new McpToolException('La operación "'.$operation.'" es de alto impacto: repite la llamada con confirm: true.');
        }

        $params = is_array($args['params'] ?? null) ? $args['params'] : [];
        $result = $ctx->run($args, fn () => $this->operations->run($operation, $params));

        return ['operation' => $operation, 'result' => SecretRedactor::redact($result)];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function logs(array $args, McpContext $ctx): array
    {
        $dir = storage_path('logs');
        $files = collect(glob($dir.'/*.log') ?: [])
            ->map(fn (string $f) => ['file' => basename($f), 'size' => filesize($f), 'modified' => date('c', (int) filemtime($f))])
            ->sortByDesc('modified')->values()->all();
        if ($files === []) {
            return ['files' => [], 'lines' => []];
        }

        $name = basename(trim((string) ($args['file'] ?? $files[0]['file'])));
        $path = $dir.DIRECTORY_SEPARATOR.$name;
        if (! str_ends_with($name, '.log') || ! is_file($path)) {
            throw new McpToolException('Archivo de log desconocido: '.$name);
        }

        $wanted = min(max((int) ($args['lines'] ?? 100), 1), (int) config('mcp.max_lines', 400));
        $search = trim((string) ($args['search'] ?? ''));
        $level = strtoupper(trim((string) ($args['level'] ?? '')));
        $date = trim((string) ($args['date'] ?? ''));

        // Barrido lineal con buffer rodante: devuelve las ÚLTIMAS N coincidencias
        // sin cargar el log completo en memoria.
        $buffer = [];
        $file = new SplFileObject($path, 'r');
        while (! $file->eof()) {
            $line = rtrim((string) $file->fgets());
            if ($line === '') {
                continue;
            }
            if ($search !== '' && stripos($line, $search) === false) {
                continue;
            }
            if ($level !== '' && stripos($line, '.'.$level.':') === false && stripos($line, $level.':') === false) {
                continue;
            }
            if ($date !== '' && ! str_contains($line, '['.$date)) {
                continue;
            }
            $buffer[] = mb_substr($line, 0, 500);
            if (count($buffer) > $wanted) {
                array_shift($buffer);
            }
        }

        return ['files' => $files, 'file' => $name, 'matched' => count($buffer), 'lines' => $buffer];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function jobs(array $args, McpContext $ctx): array
    {
        $failedId = $args['failed_id'] ?? null;
        if ($failedId !== null) {
            $row = DB::table('failed_jobs')->where('id', (int) $failedId)->orWhere('uuid', (string) $failedId)->first();
            if ($row === null) {
                throw new McpToolException('Job fallido no encontrado.');
            }
            $payload = json_decode((string) $row->payload, true);

            return [
                'id' => $row->id,
                'uuid' => $row->uuid,
                'queue' => $row->queue,
                'job' => is_array($payload) ? ($payload['displayName'] ?? null) : null,
                'failed_at' => $row->failed_at,
                'exception' => mb_substr((string) $row->exception, 0, 3000),
            ];
        }

        $pending = Schema::hasTable('jobs')
            ? DB::table('jobs')->selectRaw('queue, count(*) as jobs')->groupBy('queue')->get()->map(fn ($r) => (array) $r)->all()
            : [];
        $failed = DB::table('failed_jobs')->orderByDesc('id')->limit(20)->get()
            ->map(function ($row): array {
                $payload = json_decode((string) $row->payload, true);

                return [
                    'id' => $row->id,
                    'uuid' => $row->uuid,
                    'queue' => $row->queue,
                    'job' => is_array($payload) ? ($payload['displayName'] ?? null) : null,
                    'failed_at' => $row->failed_at,
                    'error' => mb_substr(strtok((string) $row->exception, "\n") ?: '', 0, 200),
                ];
            })->all();

        return [
            'queue_connection' => (string) config('queue.default'),
            'pending_by_queue' => $pending,
            'failed_count' => DB::table('failed_jobs')->count(),
            'failed_recent' => $failed,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function scheduler(array $args, McpContext $ctx): array
    {
        Artisan::call('schedule:list');

        return ['schedule' => trim(Artisan::output())];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function integrations(array $args, McpContext $ctx): array
    {
        return $ctx->run($args, function (): array {
            $integrations = Integration::query()->get()
                ->map(fn (Integration $i): array => SecretRedactor::redact([
                    'id' => $i->getKey(),
                    'type' => $i->getAttribute('type'),
                    'name' => $i->getAttribute('name'),
                    'status' => $i->getAttribute('status'),
                    'last_tested_at' => $i->getAttribute('last_tested_at'),
                    'config' => $i->getAttribute('config'),
                ]))->all();

            $channels = SocialChannel::query()->get()
                ->map(fn (SocialChannel $c): array => SecretRedactor::redact([
                    'id' => $c->id,
                    'provider' => $c->provider,
                    'display_name' => $c->display_name,
                    'external_id' => $c->external_id,
                    'is_active' => $c->is_active,
                    'connection_status' => $c->connection_status,
                    'connection_label' => $c->connectionLabel(),
                    'connection_meta' => $c->connection_meta,
                    'credentials' => $c->credentials,
                ]))->all();

            $webhooks = [];
            foreach (array_keys(SocialChannel::PROVIDERS) as $provider) {
                $webhooks[] = [
                    'provider' => $provider,
                    'callback' => url('/api/social/webhook/'.$provider),
                    'secret_configured' => (bool) (config('social.secrets.'.$provider) ?? config('social.app_secret')),
                ];
            }

            return [
                'integrations' => $integrations,
                'social_channels' => $channels,
                'webhooks' => $webhooks,
                'verify_token_configured' => (string) config('social.webhook_verify_token', '') !== '',
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function health(array $args, McpContext $ctx): array
    {
        $db = true;
        try {
            DB::select('select 1');
        } catch (Throwable) {
            $db = false;
        }

        $lastInbound = null;
        try {
            $lastInbound = app(\Modules\Core\Tenancy\CurrentInstitution::class)->runGlobally(
                fn () => SocialMessage::query()->where('direction', 'inbound')->max('provider_timestamp'),
            );
        } catch (Throwable) {
        }

        $logFile = storage_path('logs/laravel.log');

        return [
            'database' => $db ? 'ok' : 'ERROR',
            'storage_writable' => is_writable(storage_path()),
            'disk_free_mb' => (int) (((float) disk_free_space(base_path())) / 1048576),
            'log_size_mb' => is_file($logFile) ? round(filesize($logFile) / 1048576, 1) : 0,
            'queue_pending' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : null,
            'failed_jobs' => DB::table('failed_jobs')->count(),
            'last_social_inbound_at' => $lastInbound !== null ? (string) $lastInbound : null,
            'php_extensions' => array_values(array_intersect(['curl', 'openssl', 'fileinfo', 'pdo_mysql', 'mbstring', 'gd'], get_loaded_extensions())),
            'debug_mode' => (bool) config('app.debug'),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function gitStatus(array $args, McpContext $ctx): array
    {
        $summary = self::gitSummary();
        try {
            $status = Process::path(base_path())->run('git status --short')->output();
            $log = Process::path(base_path())->run('git log --oneline -5')->output();
            $summary['status'] = array_slice(array_filter(explode("\n", trim($status)), fn ($l) => $l !== ''), 0, 60);
            $summary['recent_commits'] = array_filter(explode("\n", trim($log)), fn ($l) => $l !== '');
        } catch (Throwable) {
            $summary['status'] = 'git no disponible en este entorno (shell restringido)';
        }

        return $summary;
    }

    /**
     * Rama y HEAD leyendo .git directamente (sin exec: funciona aunque el
     * hosting restrinja shell). Usado también por crm_overview.
     *
     * @return array<string, mixed>
     */
    public static function gitSummary(): array
    {
        $head = @file_get_contents(base_path('.git/HEAD'));
        if ($head === false) {
            return ['available' => false];
        }
        $head = trim($head);
        $branch = str_starts_with($head, 'ref: ') ? basename(substr($head, 5)) : null;
        $hash = null;
        if ($branch !== null) {
            $refFile = base_path('.git/'.substr($head, 5));
            $hash = is_file($refFile) ? trim((string) file_get_contents($refFile)) : null;
            if ($hash === null && is_file(base_path('.git/packed-refs'))) {
                foreach (file(base_path('.git/packed-refs')) ?: [] as $line) {
                    if (str_contains($line, substr($head, 5))) {
                        $hash = substr(trim($line), 0, 40);
                        break;
                    }
                }
            }
        } else {
            $hash = $head;
        }

        return ['available' => true, 'branch' => $branch, 'head' => $hash !== null ? substr($hash, 0, 12) : null];
    }
}
