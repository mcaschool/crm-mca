<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;
use Modules\Integrations\Livewire\AiProcesses\Manage;
use Modules\Integrations\Models\AiProcessConfig;
use Modules\Integrations\Models\Integration;

/**
 * Panel de procesos IA capability-aware: los overrides se guardan SOLO si el modelo
 * los soporta, y max_tokens se capa al máximo del modelo.
 *
 * @return array{0: User, 1: Bot, 2: Integration}
 */
function panelCtx(): array
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);
    $user = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin']);
    $bot = Bot::factory()->create(['institution_id' => $institution->id]);
    $integration = Integration::factory()
        ->withSecrets(['api_key' => 'sk-real-key'])
        ->create(['type' => 'ai_provider', 'provider' => 'qwen', 'status' => 'active']);

    return [$user, $bot, $integration];
}

it('guarda overrides soportados y capa max_tokens al máximo del modelo (qwen3.7-plus)', function () {
    [$user, $bot, $integration] = panelCtx();

    Livewire::actingAs($user)->test(Manage::class)
        ->set('botId', $bot->id)
        ->set('rows.conversation.integration_id', $integration->id)
        ->set('rows.conversation.model', 'qwen3.7-plus')
        ->set('rows.conversation.thinking', true)
        ->set('rows.conversation.temperature', '0.5')
        ->set('rows.conversation.max_tokens', '999999')
        ->call('save')
        ->assertHasNoErrors();

    $config = AiProcessConfig::query()->where('bot_id', $bot->id)->where('process', 'conversation')->firstOrFail();

    expect($config->model)->toBe('qwen3.7-plus')
        ->and($config->params['thinking'])->toBeTrue()
        ->and($config->params['temperature'])->toBe(0.5)
        ->and($config->params['max_tokens'])->toBe(8192); // capado al max_output del modelo
});

it('NO guarda thinking para un modelo que no lo soporta (desconocido/genérico)', function () {
    [$user, $bot, $integration] = panelCtx();

    Livewire::actingAs($user)->test(Manage::class)
        ->set('botId', $bot->id)
        ->set('rows.conversation.integration_id', $integration->id)
        ->set('rows.conversation.model', 'qwen-nuevo-2027')
        ->set('rows.conversation.thinking', true)
        ->call('save')
        ->assertHasNoErrors();

    $config = AiProcessConfig::query()->where('bot_id', $bot->id)->where('process', 'conversation')->firstOrFail();

    expect($config->model)->toBe('qwen-nuevo-2027')
        ->and($config->params['thinking'] ?? null)->toBeNull();
});
