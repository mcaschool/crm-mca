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
 * ai_process_configs: asignacion proveedor+modelo por proceso, acotada a bot.
 * Es SOLO configuracion (la llamada real al modelo es del Bloque 6).
 */
function aiAdminContext(): void
{
    $institution = Institution::factory()->create();
    $admin = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin']);
    app(CurrentInstitution::class)->set($institution->id);
    test()->actingAs($admin);
}

it('asigna proveedor+modelo a un proceso y persiste acotado al bot', function () {
    aiAdminContext();

    $ai = Integration::factory()->create(['type' => 'ai_provider', 'provider' => 'openai', 'name' => 'OpenAI']);
    $botA = Bot::factory()->create(['name' => 'Bot A']);
    $botB = Bot::factory()->create(['name' => 'Bot B']);

    Livewire::test(Manage::class)
        ->set('botId', $botA->id)
        ->set('rows.conversation.integration_id', $ai->id)
        ->set('rows.conversation.model', 'gpt-5-mini')
        ->call('save');

    $config = AiProcessConfig::query()->where('bot_id', $botA->id)->where('process', 'conversation')->first();
    expect($config)->not->toBeNull();
    expect($config->integration_id)->toBe($ai->id);
    expect($config->model)->toBe('gpt-5-mini');
    expect($config->institution_id)->toBe($botA->institution_id);

    // Respeta el bot: B no queda configurado.
    expect(AiProcessConfig::query()->where('bot_id', $botB->id)->exists())->toBeFalse();
});

it('no crea configuracion para procesos dejados vacios', function () {
    aiAdminContext();

    $ai = Integration::factory()->create(['type' => 'ai_provider', 'provider' => 'openai']);
    $bot = Bot::factory()->create();

    Livewire::test(Manage::class)
        ->set('botId', $bot->id)
        ->set('rows.conversation.integration_id', $ai->id)
        ->set('rows.conversation.model', 'gpt-5-mini')
        // summary/classification/email_draft se dejan vacios
        ->call('save');

    expect(AiProcessConfig::query()->where('bot_id', $bot->id)->count())->toBe(1);
});
