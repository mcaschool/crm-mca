<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Services;

use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Exceptions\ContractException;

final class WidgetApplications
{
    public function origin(string $origin): string
    {
        $p = parse_url($origin);
        $local = config('collaboration_widget.allow_local_http') && in_array($p['host'] ?? '', ['localhost', '127.0.0.1'], true);
        if (! $p || ! in_array($p['scheme'] ?? '', $local ? ['http', 'https'] : ['https'], true)
            || isset($p['user'],$p['pass']) || isset($p['user']) || isset($p['query']) || isset($p['fragment'])
            || (isset($p['path']) && $p['path'] !== '')) {
            throw new ContractException('invalid', 422);
        }
        $normalized = strtolower($p['scheme'].'://'.($p['host'] ?? '')).(isset($p['port']) ? ':'.$p['port'] : '');
        if ($normalized !== $origin) {
            throw new ContractException('invalid', 422);
        }

        return $origin;
    }

    public function resolve(string $id, string $origin): object
    {
        $this->origin($origin);
        $row = DB::table('collab_widget_apps')->where('id', $id)->where('enabled', true)->first();
        if (! $row || $row->auth_policy !== 'central_mfa' || ! in_array($origin, json_decode($row->origins, true), true)
            || ! DB::table('institutions')->where('id', $row->institution_id)->exists()) {
            throw new ContractException('forbidden', 403);
        }

        return $row;
    }

    /**
     * @param  list<string>  $origins
     * @param  list<string>  $capabilities
     * @param  array<string,string>  $appearance
     */
    public function register(string $id, int $institution, string $name, array $origins, string $environment, array $capabilities, array $appearance = []): void
    {
        if (! preg_match('/^[a-z0-9][a-z0-9._-]{1,63}$/D', $id) || ! $origins || ! $capabilities
            || trim($name) === '' || strlen($name) > 255 || ! in_array('chat', $capabilities, true)
            || array_diff($capabilities, ['chat', 'media', 'notifications']) || ! in_array($environment, ['local', 'staging', 'production'], true)
            || array_diff(array_keys($appearance), ['position', 'theme'])) {
            throw new ContractException('invalid', 422);
        }
        foreach ($origins as $origin) {
            $this->origin($origin);
        }
        if (isset($appearance['position']) && ! in_array($appearance['position'], ['left', 'right'], true)) {
            throw new ContractException('invalid', 422);
        }
        if (isset($appearance['theme']) && ! in_array($appearance['theme'], ['light', 'dark'], true)) {
            throw new ContractException('invalid', 422);
        }
        if (! DB::table('institutions')->where('id', $institution)->exists()) {
            throw new ContractException('not_found', 404);
        }
        DB::transaction(function () use ($id, $institution, $name, $origins, $environment, $capabilities, $appearance): void {
            $old = DB::table('collab_widget_apps')->where('id', $id)->lockForUpdate()->first();
            if ($old && (int) $old->institution_id !== $institution) {
                throw new ContractException('forbidden', 403);
            }
            DB::table('collab_widget_apps')->updateOrInsert(['id' => $id], ['institution_id' => $institution, 'name' => $name, 'origins' => json_encode(array_values(array_unique($origins)), JSON_THROW_ON_ERROR), 'environment' => $environment, 'capabilities' => json_encode($capabilities, JSON_THROW_ON_ERROR), 'appearance' => json_encode($appearance, JSON_THROW_ON_ERROR), 'enabled' => true, 'auth_policy' => 'central_mfa', 'created_at' => $old->created_at ?? now(), 'updated_at' => now()]);
        });
    }
}
