<?php

declare(strict_types=1);

use Modules\Catalog\Models\Program;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Enums\EventType;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Event;
use Modules\Crm\Models\ProgramInterest;
use Modules\Crm\Services\EventService;
use Modules\Crm\Services\ProgramInterestService;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;

function eventsSetup(): array
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);

    return [Contact::factory()->create(), Program::factory()->create(), Bot::factory()->create()];
}

it('EventService registra un evento asociado a contacto y conversacion', function () {
    [$contact] = eventsSetup();

    $event = app(EventService::class)->record(EventType::UsedMatcher, [
        'contact_id' => $contact->id,
        'data' => ['area' => 'Tecnologia'],
    ]);

    expect($event->event_type)->toBe('used_matcher');
    expect($event->contact_id)->toBe($contact->id);
    expect($event->event_data)->toBe(['area' => 'Tecnologia']);
    expect(Event::query()->count())->toBe(1);
});

it('ProgramInterestService registra el interes y deja un evento de rastro', function () {
    [$contact, $program, $bot] = eventsSetup();

    $interest = app(ProgramInterestService::class)->record($contact, $program, $bot->id, 'matcher');

    expect($interest->contact_id)->toBe($contact->id);
    expect($interest->program_id)->toBe($program->id);
    expect(ProgramInterest::query()->count())->toBe(1);

    // Dejo un evento program_interest asociado al contacto.
    $event = Event::query()->where('event_type', 'program_interest')->first();
    expect($event)->not->toBeNull();
    expect($event->contact_id)->toBe($contact->id);
    expect($event->event_data['program_id'])->toBe($program->id);
});
