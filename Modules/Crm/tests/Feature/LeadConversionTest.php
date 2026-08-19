<?php

declare(strict_types=1);

use Modules\Catalog\Models\Program;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Event;
use Modules\Crm\Models\Lead;
use Modules\Crm\Services\LeadConversionService;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;

/**
 * Regla de conversion contacto -> lead. Un contacto se vuelve lead cuando ocurre
 * cualquiera de los disparadores (corporativo, programa, precio/inscripcion); si no
 * hay disparador, se queda como contacto.
 */
function conversionCtx(): array
{
    $institution = Institution::factory()->create();

    return app(CurrentInstitution::class)->runFor($institution->id, function () use ($institution): array {
        return [$institution, Bot::factory()->create(), Contact::factory()->create()];
    });
}

it('el interes corporativo convierte el contacto en lead con la categoria Corporativo', function () {
    [$institution, $bot, $contact] = conversionCtx();

    app(CurrentInstitution::class)->runFor($institution->id, function () use ($bot, $contact) {
        $lead = app(LeadConversionService::class)->convert($contact, $bot->id, 'corporate_interest');

        expect($lead)->not->toBeNull();
        expect($lead->source)->toBe('corporate');
        expect($lead->interest_level->value)->toBe('high');
        // Categoria propia "Corporativo" (marca de primer nivel, columna area).
        expect($lead->area)->toBe((string) config('crm.lead.corporate_area'));
        expect($lead->isCorporate())->toBeTrue();
        expect(Lead::query()->where('contact_id', $contact->id)->count())->toBe(1);
        expect(Event::query()->where('event_type', 'lead_created')->count())->toBe(1);
    });
});

it('el interes en un programa convierte y guarda el programa (sin categoria corporativa)', function () {
    [$institution, $bot, $contact] = conversionCtx();

    app(CurrentInstitution::class)->runFor($institution->id, function () use ($bot, $contact) {
        $program = Program::factory()->create();

        $lead = app(LeadConversionService::class)->convert($contact, $bot->id, 'program_interest', ['program_id' => $program->id]);

        expect($lead->source)->toBe('program');
        expect($lead->program_id)->toBe($program->id);
        expect($lead->isCorporate())->toBeFalse();
    });
});

it('la solicitud de precio/inscripcion convierte (source=pricing, no corporativo)', function () {
    [$institution, $bot, $contact] = conversionCtx();

    app(CurrentInstitution::class)->runFor($institution->id, function () use ($bot, $contact) {
        $lead = app(LeadConversionService::class)->convert($contact, $bot->id, 'viewed_price');
        expect($lead->source)->toBe('pricing');
        expect($lead->isCorporate())->toBeFalse();
    });
});

it('un evento que NO es disparador deja el contacto como contacto (sin lead)', function () {
    [$institution, $bot, $contact] = conversionCtx();

    app(CurrentInstitution::class)->runFor($institution->id, function () use ($bot, $contact) {
        $lead = app(LeadConversionService::class)->convert($contact, $bot->id, 'viewed_certification');

        expect($lead)->toBeNull();
        expect(Lead::query()->where('contact_id', $contact->id)->count())->toBe(0);
    });
});

it('es idempotente: dos disparadores no duplican el lead ni el evento lead_created', function () {
    [$institution, $bot, $contact] = conversionCtx();

    app(CurrentInstitution::class)->runFor($institution->id, function () use ($bot, $contact) {
        $svc = app(LeadConversionService::class);
        $a = $svc->convert($contact, $bot->id, 'corporate_interest');
        $b = $svc->convert($contact, $bot->id, 'corporate_interest');

        expect($b->id)->toBe($a->id);
        expect(Lead::query()->where('contact_id', $contact->id)->count())->toBe(1);
        expect(Event::query()->where('event_type', 'lead_created')->count())->toBe(1);
    });
});

it('sin contacto o sin bot no hace nada', function () {
    [$institution, $bot, $contact] = conversionCtx();

    app(CurrentInstitution::class)->runFor($institution->id, function () use ($bot, $contact) {
        expect(app(LeadConversionService::class)->convert(null, $bot->id, 'corporate_interest'))->toBeNull();
        expect(app(LeadConversionService::class)->convert($contact, null, 'corporate_interest'))->toBeNull();
    });
});
