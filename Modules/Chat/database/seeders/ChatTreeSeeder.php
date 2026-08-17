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
 * Siembra el arbol de navegacion guiada REAL y BILINGUE (es/en) desde
 * database/data/navigation_tree.json (fuente unica: navigation_tree_celia.json del
 * cliente). Idempotente por (bot, key): re-sembrar actualiza y reemplaza opciones,
 * sin duplicar.
 *
 * Esquema del JSON: nodes[] con key/type/display_order/content{es,en} (+ event_type
 * opcional a nivel de nodo, que se registra al verlo -> se guarda en config). options[]
 * con label{es,en} y UNO de target|action; action en {start_matcher,start_celia,
 * external_link}; url (solo si action=external_link) resuelto desde 'urls'; event_type
 * opcional. La captura la maneja el widget; el arbol guiado arranca en NODE_MAIN.
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

            // Pasada 1: nodos (upsert por key).
            $nodesByKey = [];
            foreach ($data['nodes'] as $n) {
                $node = ConversationNode::query()->firstOrNew(['bot_id' => $bot->getKey(), 'key' => $n['key']]);
                $node->type = $n['type'];
                $node->content_es = $n['content']['es'] ?? null;
                $node->content_en = $n['content']['en'] ?? null;
                // event_type a nivel de nodo (se registra al navegar a el).
                $node->config = isset($n['event_type']) ? ['event_type' => $n['event_type']] : null;
                $node->display_order = (int) ($n['display_order'] ?? 0);
                $node->status = 'active';
                $node->save();
                $nodesByKey[$n['key']] = $node;
            }

            // Pasada 2: opciones (re-seed limpio por nodo -> idempotente).
            foreach ($data['nodes'] as $n) {
                $node = $nodesByKey[$n['key']];
                $node->options()->delete();

                foreach ($n['options'] ?? [] as $i => $o) {
                    $targetKey = $o['target'] ?? null;
                    $url = null;
                    if (($o['action'] ?? null) === 'external_link' && isset($o['url'])) {
                        $url = $urls[$o['url']] ?? $o['url'];
                    }

                    ConversationOption::query()->create([
                        'node_id' => $node->getKey(),
                        'label_es' => $o['label']['es'] ?? '',
                        'label_en' => $o['label']['en'] ?? null,
                        'target_node_id' => $targetKey !== null ? optional($nodesByKey[$targetKey] ?? null)->getKey() : null,
                        'action' => $o['action'] ?? null,
                        'event_type' => $o['event_type'] ?? null,
                        'url' => $url,
                        'display_order' => (int) ($o['display_order'] ?? ($i + 1)),
                    ]);
                }
            }
        });
    }

    /**
     * @return array{urls?: array<string,string>, nodes: array<int,array<string,mixed>>}
     */
    private function loadTree(): array
    {
        $path = dirname(__DIR__).'/data/navigation_tree.json';
        if (! is_file($path)) {
            throw new RuntimeException("No se encontro el arbol: {$path}");
        }

        /** @var array{urls?: array<string,string>, nodes: array<int,array<string,mixed>>} $data */
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }
}
