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

it('siembra el arbol real del JSON: nodos, opciones, targets, acciones y eventos', function () {
    $institutionId = seedTree();

    app(CurrentInstitution::class)->runFor($institutionId, function () {
        // Nodo raiz tras la captura.
        $main = ConversationNode::query()->where('key', 'main')->first();
        expect($main)->not->toBeNull();
        expect($main->type)->toBe('menu');
        expect($main->options()->count())->toBe(8);

        // Saludo previo a la captura.
        expect(ConversationNode::query()->where('key', 'welcome')->where('type', 'message')->exists())->toBeTrue();

        // Enlace externo con URL resuelta desde el bloque urls.
        $ver = ConversationNode::query()->where('key', 'ver_programas')->first();
        expect($ver->type)->toBe('external_link');
        expect($ver->config['url'])->toBe('https://mcaschool.education/es/microcredenciales');

        // Opcion del menu con evento CRM y target correcto.
        $cert = ConversationOption::query()->where('event_type', 'viewed_certification')->first();
        expect($cert)->not->toBeNull();
        expect(ConversationNode::query()->find($cert->target_node_id)->key)->toBe('certificacion');

        // Accion del emparejador (sin target).
        expect(ConversationOption::query()->where('action', 'start_matcher')->count())->toBeGreaterThan(0);
        expect(ConversationOption::query()->where('action', 'start_celia')->count())->toBe(1);

        // Footer expandido (un nodo mensaje con 3 botones incluida "Ayudame a elegir").
        $def = ConversationNode::query()->where('key', 'que_es_def')->first();
        expect($def->options()->count())->toBe(3);

        // Bilingue: las etiquetas llevan ingles; el contenido largo es solo espanol.
        $mainOption = $main->options()->orderBy('display_order')->first();
        expect($mainOption->label_en)->not->toBeNull();
        expect($def->content_es)->not->toBeNull();
        expect($def->content_en)->toBeNull();
    });
});

it('el seeder es idempotente: re-sembrar no duplica', function () {
    $institutionId = seedTree();

    [$nodes1, $options1] = app(CurrentInstitution::class)->runFor($institutionId, fn () => [
        ConversationNode::query()->count(), ConversationOption::query()->count(),
    ]);

    // Segunda pasada (misma institucion/bot).
    (new ChatTreeSeeder)->run();

    [$nodes2, $options2] = app(CurrentInstitution::class)->runFor($institutionId, fn () => [
        ConversationNode::query()->count(), ConversationOption::query()->count(),
    ]);

    expect($nodes2)->toBe($nodes1);
    expect($options2)->toBe($options1);
});
