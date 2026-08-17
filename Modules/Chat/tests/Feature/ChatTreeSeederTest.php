<?php

declare(strict_types=1);

use Modules\Chat\Database\Seeders\ChatTreeSeeder;
use Modules\Chat\Models\ConversationNode;
use Modules\Chat\Models\ConversationOption;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;

function seedTree(): int
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->runFor($institution->id, fn () => Bot::factory()->create());
    (new ChatTreeSeeder)->run();

    return $institution->id;
}

it('siembra el arbol bilingue del JSON: nodos, opciones, targets, acciones, urls y eventos', function () {
    $institutionId = seedTree();

    app(CurrentInstitution::class)->runFor($institutionId, function () {
        // Inicio del arbol tras la captura.
        $main = ConversationNode::query()->where('key', 'NODE_MAIN')->first();
        expect($main)->not->toBeNull();
        expect($main->type)->toBe('menu');
        expect($main->options()->count())->toBe(8);

        // Saludo previo a la captura.
        expect(ConversationNode::query()->where('key', 'NODE_WELCOME')->where('type', 'message')->exists())->toBeTrue();

        // Opcion de enlace externo con URL resuelta desde el bloque urls.
        $ver = ConversationOption::query()->where('action', 'external_link')->where('event_type', 'viewed_catalog')->first();
        expect($ver)->not->toBeNull();
        expect($ver->url)->toBe('https://mcaschool.education/es/microcredenciales/');

        // Enlace a inscripciones con la URL correcta.
        $ins = ConversationOption::query()->where('action', 'external_link')
            ->where('url', 'https://mcaschool.education/es/microcredenciales/inscripciones/')->first();
        expect($ins)->not->toBeNull();

        // Opcion con evento CRM y target correcto.
        $cert = ConversationOption::query()->where('event_type', 'viewed_certification')->first();
        expect(ConversationNode::query()->find($cert->target_node_id)->key)->toBe('NODE_CERTIFICACION');

        // Acciones del emparejador y de Celia.
        expect(ConversationOption::query()->where('action', 'start_matcher')->count())->toBeGreaterThan(0);
        expect(ConversationOption::query()->where('action', 'start_celia')->count())->toBe(1);

        // event_type a nivel de nodo, guardado en config.
        $def = ConversationNode::query()->where('key', 'NODE_QUE_ES_DEF')->first();
        expect($def->config['event_type'])->toBe('viewed_microcredential_definition');

        // BILINGUE: contenido y etiquetas en ambos idiomas.
        expect($def->content_es)->not->toBeNull();
        expect($def->content_en)->not->toBeNull();
        $mainOption = $main->options()->orderBy('display_order')->first();
        expect($mainOption->label_en)->not->toBeNull();
    });
});

it('el seeder es idempotente: re-sembrar no duplica', function () {
    $institutionId = seedTree();

    [$nodes1, $options1] = app(CurrentInstitution::class)->runFor($institutionId, fn () => [
        ConversationNode::query()->count(), ConversationOption::query()->count(),
    ]);

    (new ChatTreeSeeder)->run();

    [$nodes2, $options2] = app(CurrentInstitution::class)->runFor($institutionId, fn () => [
        ConversationNode::query()->count(), ConversationOption::query()->count(),
    ]);

    expect($nodes2)->toBe($nodes1);
    expect($options2)->toBe($options1);
});
