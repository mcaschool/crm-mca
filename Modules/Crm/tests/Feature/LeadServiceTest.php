<?php

declare(strict_types=1);

use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Enums\LeadStatus;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Crm\Services\LeadService;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;

function leadSetup(): array
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);
    $bot = Bot::factory()->create();
    $contact = Contact::factory()->create();

    return [$contact, $bot];
}

it('D4: registrar intencion dentro de los 30 dias ACTUALIZA el lead vigente', function () {
    [$contact, $bot] = leadSetup();
    $service = app(LeadService::class);

    $first = $service->recordIntent($contact, ['bot_id' => $bot->id, 'goal' => 'actualizar']);
    $second = $service->recordIntent($contact, ['bot_id' => $bot->id, 'goal' => 'ascenso']);

    expect(Lead::query()->count())->toBe(1);
    expect($second->id)->toBe($first->id);
    expect($second->goal)->toBe('ascenso');
});

it('D4: tras 30+ dias de inactividad crea un lead NUEVO', function () {
    [$contact, $bot] = leadSetup();
    $service = app(LeadService::class);

    $first = $service->recordIntent($contact, ['bot_id' => $bot->id]);
    // Se envejece el lead vigente 31 dias (sin tocar timestamps).
    Lead::query()->whereKey($first->id)->update(['updated_at' => now()->subDays(31)]);

    $second = $service->recordIntent($contact, ['bot_id' => $bot->id]);

    expect(Lead::query()->count())->toBe(2);
    expect($second->id)->not->toBe($first->id);
});

it('D4: un product_type distinto crea un lead NUEVO (criterio listo aunque hoy no se dispare)', function () {
    [$contact, $bot] = leadSetup();
    $service = app(LeadService::class);

    $micro = $service->recordIntent($contact, ['bot_id' => $bot->id, 'product_type' => 'microcredential']);
    $otro = $service->recordIntent($contact, ['bot_id' => $bot->id, 'product_type' => 'diplomado']);

    expect(Lead::query()->count())->toBe(2);
    expect($otro->id)->not->toBe($micro->id);
    expect($otro->product_type)->toBe('diplomado');
});

it('cambia el estado del lead y persiste', function () {
    [$contact, $bot] = leadSetup();
    $service = app(LeadService::class);
    $lead = $service->recordIntent($contact, ['bot_id' => $bot->id]);

    $service->changeStatus($lead, LeadStatus::Qualified);

    expect($lead->fresh()->status)->toBe(LeadStatus::Qualified);
});

it("'enrolled' es terminal: no admite mas transiciones", function () {
    [$contact, $bot] = leadSetup();
    $service = app(LeadService::class);
    $lead = $service->recordIntent($contact, ['bot_id' => $bot->id]);

    $service->changeStatus($lead, LeadStatus::Enrolled);
    expect($lead->fresh()->status)->toBe(LeadStatus::Enrolled);

    expect(fn () => $service->changeStatus($lead->fresh(), LeadStatus::Contacted))
        ->toThrow(InvalidArgumentException::class);
});
