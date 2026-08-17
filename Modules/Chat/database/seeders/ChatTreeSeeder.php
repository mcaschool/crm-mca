<?php

declare(strict_types=1);

namespace Modules\Chat\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Chat\Models\ConversationNode;
use Modules\Chat\Models\ConversationOption;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;
use RuntimeException;

/**
 * Siembra el arbol de navegacion guiada REAL desde database/data/navigation_tree.json
 * (transcripcion del documento del cliente). Idempotente por (bot, key): re-sembrar
 * actualiza el contenido y reemplaza las opciones, sin duplicar. Bilingue: el
 * contenido largo va en espanol (en=null -> fallback); las etiquetas son bilingues.
 */
class ChatTreeSeeder extends Seeder
{
    public function run(): void
    {
        $context = app(CurrentInstitution::class);

        /** @var Institution|null $institution */
        $institution = $context->runGlobally(fn () => Institution::query()->orderBy('id')->first());
        if ($institution === null) {
            return;
        }

        $data = $this->loadTree();

        $context->runFor($institution->id, function () use ($data): void {
            $bot = Bot::query()->first();
            if ($bot === null) {
                return;
            }

            $urls = $data['urls'] ?? [];
            $footers = $data['footers'] ?? [];

            // Pasada 1: nodos (upsert por key).
            $nodesByKey = [];
            foreach ($data['nodes'] as $n) {
                $node = ConversationNode::query()->firstOrNew(['bot_id' => $bot->getKey(), 'key' => $n['key']]);
                $node->type = $n['type'];
                $node->content_es = $n['content']['es'] ?? null;
                $node->content_en = $n['content']['en'] ?? null;
                $node->config = isset($n['url']) ? ['url' => $urls[$n['url']] ?? null] : null;
                $node->display_order = (int) ($n['order'] ?? 0);
                $node->status = 'active';
                $node->save();
                $nodesByKey[$n['key']] = $node;
            }

            // Pasada 2: opciones (re-seed limpio por nodo -> idempotente).
            foreach ($data['nodes'] as $n) {
                $node = $nodesByKey[$n['key']];
                $node->options()->delete();

                $options = $n['options'] ?? [];
                if (is_string($options)) {
                    $options = $footers[$options] ?? [];
                }

                $order = 1;
                foreach ($options as $o) {
                    $targetKey = $o['target'] ?? null;
                    ConversationOption::query()->create([
                        'node_id' => $node->getKey(),
                        'label_es' => $o['label']['es'],
                        'label_en' => $o['label']['en'] ?? null,
                        'target_node_id' => $targetKey !== null ? optional($nodesByKey[$targetKey] ?? null)->getKey() : null,
                        'action' => $o['action'] ?? null,
                        'event_type' => $o['event_type'] ?? null,
                        'display_order' => $order++,
                    ]);
                }
            }
        });
    }

    /**
     * @return array{urls?: array<string,string>, nodes: array<int,array<string,mixed>>, footers?: array<string,array<int,array<string,mixed>>>}
     */
    private function loadTree(): array
    {
        $path = dirname(__DIR__).'/data/navigation_tree.json';
        if (! is_file($path)) {
            throw new RuntimeException("No se encontro el arbol: {$path}");
        }

        /** @var array{urls?: array<string,string>, nodes: array<int,array<string,mixed>>, footers?: array<string,array<int,array<string,mixed>>>} $data */
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }
}
