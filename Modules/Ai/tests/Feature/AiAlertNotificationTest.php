<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Ai\Models\AiUsageEvent;
use Modules\Ai\Services\AiChatClient;
use Modules\Ai\Services\AiExecutionContext;
use Modules\Ai\Services\AiProviderException;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use Modules\Integrations\Models\Integration;

/**
 * @return array{0: Institution, 1: User, 2: Integration, 3: AiExecutionContext}
 */
function alertCtx(): array
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);
    $admin = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin']);
    $integration = Integration::factory()
        ->withSecrets(['api_key' => 'sk-real-key', 'base_url' => 'https://ws-x.maas.aliyuncs.com/compatible-mode/v1'])
        ->create(['type' => 'ai_provider', 'provider' => 'qwen', 'status' => 'active']);
    $ctx = new AiExecutionContext($institution->id, 'conversation', $integration->id, 'qwen', 'qwen3.7-plus', botId: 5);

    return [$institution, $admin, $integration, $ctx];
}

function fakeQuotaError(): void
{
    Http::fake(['*' => Http::response(['error' => ['code' => 'insufficient_quota', 'message' => 'Free quota exhausted.', 'id' => 'req-1']], 403)]);
}

function callAi(Integration $integration, AiExecutionContext $ctx): void
{
    try {
        app(AiChatClient::class)->chat($integration, 'qwen3.7-plus', [['role' => 'user', 'content' => 'PROMPT_SECRETO_XYZ']], [], $ctx);
    } catch (AiProviderException) {
        // esperado en los casos de fallo
    }
}

it('primer fallo notifica al admin; el segundo igual NO duplica; sin secretos; Log::critical una vez', function () {
    fakeQuotaError();
    [, $admin, $integration, $ctx] = alertCtx();
    Log::spy();

    callAi($integration, $ctx);
    callAi($integration, $ctx);

    $admin->refresh();
    expect($admin->notifications()->count())->toBe(1);

    $data = $admin->notifications()->first()->data;
    expect($data['type'])->toBe('ai_service_down')
        ->and($data['title'])->toBe('URGENTE: Servicio de IA no disponible')
        ->and($data['category'])->toBe('quota_exhausted')
        ->and($data['category_label'])->toBe('Cuota agotada')
        ->and($data['provider'])->toBe('qwen')
        ->and($data['model'])->toBe('qwen3.7-plus')
        ->and($data['technical']['http_status'])->toBe(403)
        ->and(json_encode($data))->not->toContain('sk-real-key')
        ->and(json_encode($data))->not->toContain('PROMPT_SECRETO_XYZ');

    Log::shouldHaveReceived('critical')->once();
});

it('un error de categoría DISTINTA genera una nueva notificación', function () {
    [, $admin, $integration, $ctx] = alertCtx();

    // Secuencia: 1ª llamada quota (403), 2ª modelo no disponible (404) → categorías distintas.
    Http::fake(['*' => Http::sequence()
        ->push(['error' => ['code' => 'insufficient_quota']], 403)
        ->push(['error' => ['code' => 'model_not_found']], 404)]);

    callAi($integration, $ctx);
    callAi($integration, $ctx);

    $admin->refresh();
    expect($admin->notifications()->count())->toBe(2);
});

it('el sistema tolera que no haya administradores (no rompe la llamada)', function () {
    fakeQuotaError();
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);
    $integration = Integration::factory()
        ->withSecrets(['api_key' => 'sk-real-key', 'base_url' => 'https://ws-x.maas.aliyuncs.com/compatible-mode/v1'])
        ->create(['type' => 'ai_provider', 'provider' => 'qwen', 'status' => 'active']);
    $ctx = new AiExecutionContext($institution->id, 'conversation', $integration->id, 'qwen', 'qwen3.7-plus');

    // Sin admins en la institución: no debe lanzar.
    callAi($integration, $ctx);

    expect(AiUsageEvent::withoutGlobalScopes()->where('status', 'error')->count())->toBe(1);
});

it('NO notifica a administradores de OTRA institución', function () {
    fakeQuotaError();
    [, $adminA, $integration, $ctx] = alertCtx();

    $institutionB = Institution::factory()->create();
    $adminB = User::factory()->create(['institution_id' => $institutionB->id, 'role' => 'admin']);

    callAi($integration, $ctx);

    expect($adminA->notifications()->count())->toBe(1)
        ->and($adminB->notifications()->count())->toBe(0);
});

it('genera notificación de "servicio restablecido" tras recuperarse', function () {
    [, $admin, $integration, $ctx] = alertCtx();
    Http::fake(['*' => Http::sequence()
        ->push(['error' => ['code' => 'insufficient_quota']], 403)
        ->push(['choices' => [['message' => ['content' => 'ok']]], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]], 200)]);

    callAi($integration, $ctx); // fallo → down
    app(AiChatClient::class)->chat($integration, 'qwen3.7-plus', [['role' => 'user', 'content' => 'ok']], [], $ctx); // éxito → restored

    $admin->refresh();
    expect($admin->notifications()->count())->toBe(2);

    $restored = $admin->notifications()->get()->first(fn ($n) => ($n->data['type'] ?? '') === 'ai_service_restored');
    expect($restored)->not->toBeNull()
        ->and($restored->data['title'])->toBe('Servicio de IA restablecido')
        ->and($restored->data)->toHaveKey('incident_duration_minutes');
});

it('ai_usage_events registra CADA ejecución aunque la alerta se deduplique', function () {
    fakeQuotaError();
    [, $admin, $integration, $ctx] = alertCtx();

    foreach (range(1, 3) as $ignored) {
        callAi($integration, $ctx);
    }

    expect(AiUsageEvent::withoutGlobalScopes()->where('status', 'error')->count())->toBe(3)
        ->and($admin->notifications()->count())->toBe(1);
});
