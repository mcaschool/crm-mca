<?php

declare(strict_types=1);

use Modules\Catalog\Models\Program;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Event;
use Modules\Institutions\Models\Institution;

/**
 * El historial de eventos debe leerse claro (etiqueta bilingue, nunca el codigo
 * crudo) y mostrar el DETALLE especifico (programa, enlace o pregunta) para el
 * seguimiento comercial.
 */
it('muestra etiquetas legibles y bilingues, nunca el codigo crudo', function () {
    $institution = Institution::factory()->create();

    app(CurrentInstitution::class)->runFor($institution->id, function () {
        $ev = Event::factory()->create(['event_type' => 'started_celia', 'event_data' => null]);
        expect($ev->label('es'))->toBe('Inició conversación con Celia');
        expect($ev->label('en'))->toBe('Started a chat with Celia');

        $recontact = Event::factory()->create(['event_type' => 'recontacted', 'event_data' => null]);
        expect($recontact->label('es'))->toBe('Volvió a contactar');

        // Tipo desconocido: se humaniza (no se muestra el codigo crudo).
        $unknown = Event::factory()->create(['event_type' => 'some_new_thing', 'event_data' => null]);
        expect($unknown->label('es'))->toBe('Some new thing');
    });
});

it('muestra el detalle especifico: programa, enlace y pregunta', function () {
    $institution = Institution::factory()->create();

    app(CurrentInstitution::class)->runFor($institution->id, function () {
        $program = Program::factory()->create(['name_es' => 'Liderazgo Estratégico']);

        $interest = Event::factory()->create([
            'event_type' => 'program_interest',
            'event_data' => ['program_id' => $program->id, 'source' => 'celia'],
        ]);
        expect($interest->detail('es'))->toBe('Liderazgo Estratégico');

        $catalog = Event::factory()->create([
            'event_type' => 'viewed_catalog',
            'event_data' => ['url' => 'https://mcaschool.education/'],
        ]);
        expect($catalog->detail())->toBe('https://mcaschool.education/');

        $cert = Event::factory()->create([
            'event_type' => 'viewed_certification',
            'event_data' => ['label' => 'Certificación y titulación'],
        ]);
        expect($cert->detail())->toBe('Certificación y titulación');

        $question = Event::factory()->create([
            'event_type' => 'unresolved_question',
            'event_data' => ['question' => '¿Ofrecen becas?'],
        ]);
        expect($question->detail())->toBe('¿Ofrecen becas?');

        // Evento sin detalle concreto -> null (la etiqueta ya lo describe).
        $plain = Event::factory()->create(['event_type' => 'widget_opened', 'event_data' => null]);
        expect($plain->detail())->toBeNull();
    });
});
