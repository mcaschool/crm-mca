<?php

declare(strict_types=1);

namespace Modules\Chat\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Chat\Models\ConversationNode;
use Modules\Chat\Models\ConversationOption;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;

/**
 * Arbol de navegacion guiada BASE (placeholder, administrable). El contenido real
 * lo cargara el usuario despues; los textos son facilmente reemplazables desde el
 * panel. Idempotente por (bot, key). Incluye los 3 botones clave:
 * "Ver los programas", "Ayudame a elegir" (emparejador) y "Hablar con Celia".
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

        $context->runFor($institution->id, function (): void {
            $bot = Bot::query()->first();
            if ($bot === null) {
                return;
            }

            $welcome = $this->node($bot->getKey(), 'welcome', 'menu', [
                'es' => '¡Hola! Soy Celia, tu asesora de microcredenciales. ¿Como puedo ayudarte?',
                'en' => 'Hi! I am Celia, your microcredential advisor. How can I help you?',
            ]);

            $verProgramas = $this->node($bot->getKey(), 'ver_programas', 'external_link', [
                'es' => 'Explora todos nuestros programas en la web:',
                'en' => 'Explore all our programs on the website:',
            ], ['url' => 'https://mcaschool.education/es/microcredenciales']);

            // Opciones del menu de bienvenida.
            $this->option($welcome, 'Ver los programas', 'View programs', $verProgramas->getKey(), null, 'viewed_program', 1);
            $this->option($welcome, 'Ayudame a elegir', 'Help me choose', null, 'start_matcher', null, 2);
            $this->option($welcome, 'Hablar con Celia', 'Talk to Celia', null, 'start_celia', 'started_celia', 3);

            // Volver desde la pantalla de programas.
            $this->option($verProgramas, 'Volver al inicio', 'Back to start', $welcome->getKey(), null, null, 1);
        });
    }

    /**
     * @param  array{es: string, en: string}  $content
     * @param  array<string,mixed>|null  $config
     */
    private function node(int $botId, string $key, string $type, array $content, ?array $config = null): ConversationNode
    {
        $node = ConversationNode::query()->firstOrNew(['bot_id' => $botId, 'key' => $key]);
        $node->type = $type;
        $node->content_es = $content['es'];
        $node->content_en = $content['en'];
        $node->config = $config;
        $node->status = 'active';
        $node->save();

        return $node;
    }

    private function option(
        ConversationNode $node,
        string $labelEs,
        string $labelEn,
        ?int $targetNodeId,
        ?string $action,
        ?string $eventType,
        int $order,
    ): void {
        $option = ConversationOption::query()->firstOrNew(['node_id' => $node->getKey(), 'label_es' => $labelEs]);
        $option->label_en = $labelEn;
        $option->target_node_id = $targetNodeId;
        $option->action = $action;
        $option->event_type = $eventType;
        $option->display_order = $order;
        $option->save();
    }
}
