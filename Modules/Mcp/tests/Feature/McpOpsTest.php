<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Mcp\Models\McpAuditLog;
use Modules\Social\Models\SocialChannel;

/**
 * Operación y diagnóstico vía MCP: crm_execute (catálogo, confirmación de
 * peligrosas, operaciones reales con Http::fake), logs, jobs, salud, código,
 * git y AUDITORÍA de todas las llamadas.
 */
it('crm_execute sin operation lista el catálogo y las peligrosas exigen confirm', function () {
    [, $token] = mcpCtx();

    [$catalog] = mcpTool($token, 'crm_execute');
    expect($catalog['operations'])->toHaveKey('social.templates.sync');
    expect($catalog['operations']['cache.optimize_clear']['dangerous'])->toBeTrue();

    [, $isError, $raw] = mcpTool($token, 'crm_execute', ['operation' => 'cache.optimize_clear']);
    expect($isError)->toBeTrue();
    expect($raw)->toContain('confirm');

    [$done] = mcpTool($token, 'crm_execute', ['operation' => 'cache.optimize_clear', 'confirm' => true]);
    expect($done['result']['exit_code'])->toBe(0);
});

it('crm_execute ejecuta operaciones REALES: toggle de canal y sync de plantillas (Http::fake)', function () {
    [$institution, $token] = mcpCtx();
    $channel = app(CurrentInstitution::class)->runFor($institution->id, fn () => SocialChannel::factory()->create([
        'provider' => 'whatsapp',
        'external_id' => 'PHONE_MCP',
        'credentials' => ['token' => 'WA_TOKEN', 'waba_id' => 'WABA_MCP'],
    ]));
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => []], 200)]);

    [$toggled] = mcpTool($token, 'crm_execute', [
        'operation' => 'channel.toggle',
        'params' => ['channel_id' => $channel->id, 'active' => false],
        'institution_id' => $institution->id,
    ]);
    expect($toggled['result']['is_active'])->toBeFalse();

    [$synced, $isError] = mcpTool($token, 'crm_execute', [
        'operation' => 'social.templates.sync',
        'params' => ['channel_id' => $channel->id],
        'institution_id' => $institution->id,
    ]);
    expect($isError)->toBeFalse();
    expect($synced['result']['synced'])->toBe(0);
});

it('webhook.simulate inyecta un payload por el normalizador+ingesta (confirm requerido)', function () {
    [$institution, $token] = mcpCtx();
    app(CurrentInstitution::class)->runFor($institution->id, fn () => SocialChannel::factory()->create([
        'provider' => 'whatsapp',
        'external_id' => 'demo_wa_phone',
    ]));
    Http::fake();
    $payload = json_decode((string) file_get_contents(base_path('Modules/Social/tests/Fixtures/whatsapp.json')), true);

    [$result, $isError] = mcpTool($token, 'crm_execute', [
        'operation' => 'webhook.simulate',
        'params' => ['provider' => 'whatsapp', 'payload' => $payload],
        'confirm' => true,
    ]);

    expect($isError)->toBeFalse();
    expect($result['result']['results'][0]['status'])->toBe('created');
});

it('crm_logs filtra el log por búsqueda', function () {
    [, $token] = mcpCtx();
    Log::error('MCP_TEST_MARKER_9Z');

    [$data, $isError] = mcpTool($token, 'crm_logs', ['search' => 'MCP_TEST_MARKER_9Z', 'lines' => 20]);

    expect($isError)->toBeFalse();
    expect($data['matched'])->toBeGreaterThanOrEqual(1);
});

it('crm_jobs y crm_health responden con el estado del sistema', function () {
    [, $token] = mcpCtx();

    [$jobs] = mcpTool($token, 'crm_jobs');
    expect($jobs)->toHaveKey('failed_count');

    [$health] = mcpTool($token, 'crm_health');
    expect($health['database'])->toBe('ok');
    expect($health['php_extensions'])->toContain('pdo_mysql');
});

it('crm_code_read lee archivos del repo pero JAMÁS sirve .env ni sale del alcance', function () {
    [, $token] = mcpCtx();

    [$ok] = mcpTool($token, 'crm_code_read', ['path' => 'config/social.php']);
    expect($ok['content'])->toContain('graph_version');

    [, $envErr] = mcpTool($token, 'crm_code_read', ['path' => '.env']);
    expect($envErr)->toBeTrue();

    [, $traversalErr] = mcpTool($token, 'crm_code_read', ['path' => 'config/../.env']);
    expect($traversalErr)->toBeTrue();

    [, $vendorErr] = mcpTool($token, 'crm_code_read', ['path' => 'vendor/autoload.php']);
    expect($vendorErr)->toBeTrue();
});

it('la búsqueda/lectura de código está confinada al proyecto y excluye dependencias y artefactos', function () {
    [, $token] = mcpCtx();

    // Directorios de dependencias/artefactos vedados en cualquier nivel (incluye public/vendor).
    foreach (['vendor/composer/installed.json', 'public/vendor/livewire/livewire.js', 'storage/logs/laravel.log', 'bootstrap/cache/config.php', 'node_modules/x/index.js'] as $denied) {
        [, $isError] = mcpTool($token, 'crm_code_read', ['path' => $denied]);
        expect($isError)->toBeTrue();
    }

    // Una búsqueda amplia NUNCA devuelve rutas de vendor/node_modules/stubs, y respeta el tope.
    [$data, $isError] = mcpTool($token, 'crm_code_search', ['pattern' => 'function', 'limit' => 100]);
    expect($isError)->toBeFalse();
    expect(count($data['results']))->toBeLessThanOrEqual(100);
    foreach ($data['results'] as $row) {
        expect($row['file'])->not->toContain('vendor/');
        expect($row['file'])->not->toContain('node_modules/');
        expect($row['file'])->not->toContain('.git/');
    }
});

it('crm_code_search encuentra clases y crm_git_status reporta rama y HEAD', function () {
    [, $token] = mcpCtx();

    [$search] = mcpTool($token, 'crm_code_search', ['pattern' => 'class WhatsAppCoexistenceService']);
    expect($search['count'])->toBeGreaterThanOrEqual(1);

    [$git] = mcpTool($token, 'crm_git_status');
    expect($git['available'])->toBeTrue();
    expect($git['branch'])->toBe('main');
});

it('TODA llamada tools/call queda auditada con parámetros SANEADOS (sin secretos)', function () {
    [$institution, $token] = mcpCtx();

    mcpTool($token, 'crm_record_create', [
        'model' => 'Social.SocialChannel',
        'data' => [
            'provider' => 'whatsapp',
            'display_name' => 'Canal MCP',
            'external_id' => 'PHONE_AUDIT',
            'is_active' => true,
            'credentials' => ['token' => 'SECRETO_AUDITORIA_1'],
        ],
        'institution_id' => $institution->id,
    ]);

    $log = McpAuditLog::query()->where('tool', 'crm_record_create')->latest('id')->first();
    expect($log)->not->toBeNull();
    expect($log->status)->toBe('ok');
    expect($log->institution_id)->toBe($institution->id);
    expect($log->resource)->toBe('Social.SocialChannel');
    expect($log->correlation_id)->not->toBe('');
    expect(json_encode($log->params))->not->toContain('SECRETO_AUDITORIA_1'); // saneado

    // El canal SÍ quedó creado con su credencial usable (redactada al leerla).
    $channel = app(CurrentInstitution::class)->runFor($institution->id, fn () => SocialChannel::query()->where('external_id', 'PHONE_AUDIT')->first());
    expect($channel->credentials['token'])->toBe('SECRETO_AUDITORIA_1');

    [, , $raw] = mcpTool($token, 'crm_record_get', [
        'model' => 'Social.SocialChannel', 'id' => $channel->id, 'institution_id' => $institution->id,
    ]);
    expect($raw)->not->toContain('SECRETO_AUDITORIA_1');
});

it('crm_integrations muestra canales con credenciales enmascaradas y webhooks', function () {
    [$institution, $token] = mcpCtx();
    app(CurrentInstitution::class)->runFor($institution->id, fn () => SocialChannel::factory()->create([
        'provider' => 'whatsapp',
        'credentials' => ['token' => 'TOKEN_INTEGRACION_X', 'waba_id' => 'WABA_X'],
        'connection_status' => 'connected_cloud_api',
    ]));

    [$data, $isError, $raw] = mcpTool($token, 'crm_integrations', ['institution_id' => $institution->id]);

    expect($isError)->toBeFalse();
    expect($data['social_channels'][0]['connection_status'])->toBe('connected_cloud_api');
    expect($data['social_channels'][0]['credentials']['keys'])->toContain('waba_id');
    expect($raw)->not->toContain('TOKEN_INTEGRACION_X');
    expect(collect($data['webhooks'])->pluck('provider'))->toContain('whatsapp');
});
