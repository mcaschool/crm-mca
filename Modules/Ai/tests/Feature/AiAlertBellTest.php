<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Modules\Ai\Livewire\AiAlertBell;
use Modules\Ai\Notifications\AiServiceAlertNotification;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;

function bellAdmin(string $role = 'admin'): User
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);

    return User::factory()->create(['institution_id' => $institution->id, 'role' => $role]);
}

it('la campana muestra las alertas de IA al admin y permite marcarlas leídas', function () {
    $admin = bellAdmin('admin');
    $admin->notify(new AiServiceAlertNotification([
        'type' => 'ai_service_down',
        'title' => 'URGENTE: Servicio de IA no disponible',
        'process' => 'conversation', 'provider' => 'qwen', 'model' => 'qwen3.7-plus', 'category_label' => 'Cuota agotada',
    ]));

    Livewire::actingAs($admin)->test(AiAlertBell::class)
        ->assertSee('URGENTE: Servicio de IA no disponible')
        ->call('markAllRead');

    expect($admin->unreadNotifications()->count())->toBe(0);
});

it('la campana NO muestra alertas a un usuario no administrador', function () {
    $mkt = bellAdmin('marketing');
    $mkt->notify(new AiServiceAlertNotification([
        'type' => 'ai_service_down', 'title' => 'URGENTE: Servicio de IA no disponible',
    ]));

    Livewire::actingAs($mkt)->test(AiAlertBell::class)
        ->assertDontSee('URGENTE: Servicio de IA no disponible');
});
