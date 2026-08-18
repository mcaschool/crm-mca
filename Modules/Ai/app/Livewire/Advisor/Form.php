<?php

declare(strict_types=1);

namespace Modules\Ai\Livewire\Advisor;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Ai\Services\AdvisorDeletionService;
use Modules\Ai\Services\KnowledgeSyncService;
use Modules\Institutions\Models\Bot;
use Modules\Integrations\Models\AiProcessConfig;
use Modules\Integrations\Models\Integration;

/**
 * Crear / editar un Asesor Inteligente (bot). Todos los campos editables: nombre,
 * tipo (IA/Humano), avatar, idioma, estado, y —solo IA— proceso de IA (referencia
 * a una integracion existente, sin duplicar credenciales) y base de conocimiento
 * (.md con upsert + re-sync). Solo Administrador. No toca la conversacion de Celia.
 */
#[Layout('layouts.app')]
class Form extends Component
{
    use WithFileUploads;

    public ?int $botId = null;

    public string $name = '';

    public string $type = 'ia';

    public string $language = 'es';

    public string $status = 'active';

    public ?int $integrationId = null;

    public string $model = '';

    public mixed $avatar = null;

    /** @var array<int, mixed> */
    public array $docs = [];

    /** Modal de eliminacion (exige teclear el nombre exacto). */
    public bool $confirmingDelete = false;

    public string $deleteConfirmName = '';

    public function mount(?Bot $bot = null): void
    {
        abort_unless((bool) auth()->user()?->canManageIntegrations(), 403);

        if ($bot !== null && $bot->exists) {
            $this->botId = $bot->getKey();
            $this->name = (string) $bot->assistant_name;
            $this->type = $bot->type ?: 'ia';
            $this->language = $bot->default_language ?: 'es';
            $this->status = $bot->status ?: 'active';

            $cfg = AiProcessConfig::query()->where('bot_id', $this->botId)->where('process', 'conversation')->first();
            $this->integrationId = $cfg?->integration_id;
            $this->model = $cfg !== null ? (string) $cfg->model : '';
        }
    }

    /** Guarda identidad + proceso. En creacion redirige a edicion (para avatar/KB). */
    public function save(): mixed
    {
        abort_unless((bool) auth()->user()?->canManageIntegrations(), 403);

        $this->validate([
            'name' => ['required', 'string', 'max:60'],
            'type' => ['required', 'in:ia,human'],
            'language' => ['required', 'in:es,en'],
            'status' => ['required', 'in:active,inactive'],
            'integrationId' => ['nullable', 'integer'],
            'model' => ['nullable', 'string', 'max:100'],
        ]);

        $creating = $this->botId === null;
        $bot = $creating ? new Bot : Bot::query()->findOrFail($this->botId);

        if ($creating) {
            $bot->name = trim($this->name);
            $bot->slug = $this->uniqueSlug(trim($this->name));
            $bot->public_key = Str::random(32);
        }
        $bot->assistant_name = trim($this->name);
        $bot->type = $this->type;
        $bot->default_language = $this->language;
        $bot->status = $this->status;
        $bot->save();

        $this->botId = $bot->getKey();

        // Proceso de IA (solo IA): referencia a una integracion existente. No se
        // gestionan credenciales aqui (viven en el almacen cifrado de integraciones).
        if ($this->type === 'ia' && $this->integrationId && trim($this->model) !== '') {
            AiProcessConfig::query()->updateOrCreate(
                ['bot_id' => $bot->getKey(), 'process' => 'conversation'],
                ['integration_id' => $this->integrationId, 'model' => trim($this->model), 'status' => 'active'],
            );
        }

        session()->flash('status', $creating ? 'Asesor creado. Completa su foto y conocimiento.' : 'Asesor actualizado.');

        if ($creating) {
            return redirect()->route('advisors.edit', $bot->getKey());
        }

        return null;
    }

    public function saveAvatar(): void
    {
        abort_unless((bool) auth()->user()?->canManageIntegrations(), 403);
        $this->validate(['avatar' => ['required', 'image', 'mimes:png,jpg,jpeg,svg,webp,gif', 'max:1024']]);

        $bot = $this->bot();
        if ($bot === null || ! $this->avatar instanceof TemporaryUploadedFile) {
            return;
        }

        $folder = 'advisors/'.$bot->advisorFolder();
        $ext = strtolower($this->avatar->getClientOriginalExtension() ?: 'png');

        if ($bot->avatar_path !== null && $bot->avatar_path !== $folder.'/avatar.'.$ext) {
            Storage::disk('public')->delete($bot->avatar_path);
        }

        $bot->avatar_path = $this->avatar->storeAs($folder, 'avatar.'.$ext, 'public');
        $bot->save();

        $this->avatar = null;
        session()->flash('status', 'Foto de perfil actualizada.');
    }

    public function removeAvatar(): void
    {
        abort_unless((bool) auth()->user()?->canManageIntegrations(), 403);
        $bot = $this->bot();
        if ($bot === null || $bot->avatar_path === null) {
            return;
        }
        Storage::disk('public')->delete($bot->avatar_path);
        $bot->avatar_path = null;
        $bot->save();
        session()->flash('status', 'Foto eliminada; se usa el avatar por defecto.');
    }

    public function uploadKnowledge(KnowledgeSyncService $sync): void
    {
        abort_unless((bool) auth()->user()?->canManageIntegrations(), 403);
        $bot = $this->bot();
        if ($bot === null || $this->docs === []) {
            return;
        }

        $folder = $bot->advisorFolder();
        $saved = 0;
        foreach ($this->docs as $doc) {
            if (! $doc instanceof TemporaryUploadedFile) {
                continue;
            }
            $original = $doc->getClientOriginalName();
            if (strtolower((string) pathinfo($original, PATHINFO_EXTENSION)) !== 'md') {
                $this->addError('docs', 'Solo se admiten archivos .md (Markdown).');

                continue;
            }
            $doc->storeAs($folder, $original, 'knowledge');
            $saved++;
        }

        $this->docs = [];
        if ($saved === 0) {
            return;
        }

        $report = $sync->sync($bot->getKey(), $folder);
        session()->flash('status', "{$saved} archivo(s) subido(s). Conocimiento sincronizado ({$report['created']} nuevas, {$report['updated']} actualizadas).");
    }

    public function removeKnowledge(int $sourceId, KnowledgeSyncService $sync): void
    {
        abort_unless((bool) auth()->user()?->canManageIntegrations(), 403);
        $bot = $this->bot();
        if ($bot === null) {
            return;
        }

        $source = KnowledgeSource::query()->where('bot_id', $bot->getKey())->find($sourceId);
        if ($source === null) {
            return;
        }

        // Borra el archivo de origen (si se conoce) para que no reaparezca al re-sincronizar.
        if ($source->source_file !== null) {
            Storage::disk('knowledge')->delete($bot->advisorFolder().'/'.$source->source_file);
        }
        $source->delete();

        session()->flash('status', 'Documento de conocimiento eliminado.');
    }

    public function sync(KnowledgeSyncService $sync): void
    {
        abort_unless((bool) auth()->user()?->canManageIntegrations(), 403);
        $bot = $this->bot();
        if ($bot === null) {
            return;
        }
        $report = $sync->sync($bot->getKey(), $bot->advisorFolder());
        session()->flash('status', "Sincronizado: {$report['created']} nuevas, {$report['updated']} actualizadas.");
    }

    /** Abre el modal de confirmacion (solo si es eliminable). */
    public function confirmDelete(AdvisorDeletionService $guard): void
    {
        abort_unless((bool) auth()->user()?->canManageIntegrations(), 403);
        $bot = $this->bot();
        if ($bot === null || ! $guard->canDelete($bot)) {
            return;
        }
        $this->deleteConfirmName = '';
        $this->confirmingDelete = true;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDelete = false;
        $this->deleteConfirmName = '';
    }

    /** Elimina permanentemente el asesor tras confirmar tecleando su nombre. */
    public function deleteAdvisor(AdvisorDeletionService $guard): mixed
    {
        abort_unless((bool) auth()->user()?->canManageIntegrations(), 403);
        $bot = $this->bot();
        if ($bot === null) {
            return null;
        }

        // Guarda de seguridad (re-validada aqui aunque el boton este oculto/bloqueado).
        if (! $guard->canDelete($bot)) {
            $this->confirmingDelete = false;
            session()->flash('status_error', (string) $guard->blockReason($bot));

            return null;
        }

        // Confirmacion explicita: el nombre tecleado debe coincidir exactamente.
        if (trim($this->deleteConfirmName) !== (string) $bot->assistant_name) {
            $this->addError('deleteConfirmName', 'Escribe el nombre exacto del asesor para confirmar.');

            return null;
        }

        $name = (string) $bot->assistant_name;
        $guard->delete($bot);

        session()->flash('status', "Asesor \"{$name}\" eliminado permanentemente.");

        return redirect()->route('advisors.index');
    }

    public function render(): View
    {
        $bot = $this->bot();

        $sources = $bot === null
            ? collect()
            : KnowledgeSource::query()->where('bot_id', $bot->getKey())->orderBy('code')->get();

        $deleteBlockReason = $bot !== null ? app(AdvisorDeletionService::class)->blockReason($bot) : null;

        return view('ai::livewire.advisor.form', [
            'bot' => $bot,
            'editing' => $this->botId !== null,
            'avatarUrl' => $bot?->avatarUrl(),
            'sources' => $sources,
            'integrations' => Integration::query()->where('type', 'ai_provider')->orderBy('name')->get(),
            'deleteBlockReason' => $deleteBlockReason,
            'deleteNameMatches' => $bot !== null && trim($this->deleteConfirmName) === (string) $bot->assistant_name,
            // Snippets de incrustacion: dominio de produccion (config) + public_key REAL
            // del bot (dinamica) + separacion inferior configurable. Dos variantes:
            // (1) etiqueta <script>, (2) JavaScript puro para campos "footer scripts"
            // de WordPress/temas (que NO admiten etiquetas <script>).
            'embedSnippet' => $bot !== null ? $this->embedSnippet($bot) : null,
            'embedSnippetJs' => $bot !== null ? $this->embedSnippetJs($bot) : null,
        ]);
    }

    private function bot(): ?Bot
    {
        return $this->botId === null ? null : Bot::query()->find($this->botId);
    }

    /**
     * Genera el <script> de incrustacion del widget para pegar en la web publica.
     * Dominio desde config (produccion por defecto), public_key REAL del bot. No es
     * un secreto: la public_key es el token publico que el widget lleva embebido.
     */
    private function embedSnippet(Bot $bot): string
    {
        $base = (string) config('crm.widget_embed_url');
        $offset = (int) config('crm.widget_offset_bottom', 90);

        return '<script src="'.$base.'/widget/celia.js"'."\n"
            .'        data-bot-key="'.$bot->public_key.'"'."\n"
            .'        data-api-base="'.$base.'"'."\n"
            .'        data-offset-bottom="'.$offset.'"></script>';
    }

    /**
     * Variante en JavaScript PURO (sin etiquetas <script>) para campos tipo "Custom
     * Scripts (Footer)" de WordPress/temas que no admiten <script>. Inserta el widget
     * con document.createElement; celia.js se localiza por data-bot-key (no depende de
     * document.currentScript). Mismo dominio, public_key y separacion inferior.
     */
    private function embedSnippetJs(Bot $bot): string
    {
        $base = (string) config('crm.widget_embed_url');
        $offset = (int) config('crm.widget_offset_bottom', 90);

        return "(function () {\n"
            ."  var s = document.createElement('script');\n"
            ."  s.src = '".$base."/widget/celia.js';\n"
            ."  s.setAttribute('data-bot-key', '".$bot->public_key."');\n"
            ."  s.setAttribute('data-api-base', '".$base."');\n"
            ."  s.setAttribute('data-offset-bottom', '".$offset."');\n"
            ."  s.async = true;\n"
            ."  document.body.appendChild(s);\n"
            .'})();';
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'asesor';
        $slug = $base;
        $i = 2;
        while (Bot::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }
}
