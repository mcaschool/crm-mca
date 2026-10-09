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
use Modules\Ai\Models\AdvisorCorrection;
use Modules\Ai\Models\AdvisorFeedback;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Ai\Services\AdvisorDeletionService;
use Modules\Ai\Services\AdvisorPreviewLinkService;
use Modules\Ai\Services\KnowledgeAssignmentService;
use Modules\Ai\Services\KnowledgeIngestService;
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

    /** Búsqueda en el conocimiento (classic | precise) y respuestas de IA por conversación (vacío = general). */
    public string $knowledgeRetrieval = Bot::RETRIEVAL_CLASSIC;

    public string $messageLimit = '';

    /** Espera mínima «está escribiendo…» (segundos, 0–8). */
    public string $typingDelay = '3';

    /** «Identidad e instrucciones»: se combinan con las reglas institucionales (nunca las sustituyen). */
    public string $roleDescription = '';

    public string $instructions = '';

    public string $tone = '';

    public string $restrictions = '';

    public string $notFoundMessage = '';

    public string $handoffRules = '';

    /** «Presentación del widget» (ES/EN). Vacío = el texto por defecto del widget. */
    public string $welcomeEs = '';

    public string $welcomeEn = '';

    public string $buttonEs = '';

    public string $buttonEn = '';

    /** Saludo inicial de la conversación (ES/EN). Vacío = el saludo por defecto. */
    public string $greetingEs = '';

    public string $greetingEn = '';

    /** «Correcciones aprendidas»: la que se está editando (null = ninguna). */
    public ?int $editingCorrectionId = null;

    public string $correctionQuestion = '';

    public string $correctionAnswer = '';

    public string $correctionTopic = '';

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
            $this->roleDescription = (string) $bot->role_description;
            $this->instructions = (string) $bot->instructions;
            $this->tone = (string) $bot->tone;
            $this->restrictions = (string) $bot->restrictions;
            $this->notFoundMessage = (string) $bot->not_found_message;
            $this->handoffRules = (string) $bot->handoff_rules;
            $this->knowledgeRetrieval = $bot->knowledge_retrieval ?: Bot::RETRIEVAL_CLASSIC;
            $this->messageLimit = $bot->ai_message_limit !== null ? (string) $bot->ai_message_limit : '';
            $this->typingDelay = (string) $bot->typingDelay();
            $this->welcomeEs = (string) $bot->widget_welcome_es;
            $this->welcomeEn = (string) $bot->widget_welcome_en;
            $this->buttonEs = (string) $bot->widget_button_es;
            $this->buttonEn = (string) $bot->widget_button_en;
            $this->greetingEs = (string) $bot->greeting_es;
            $this->greetingEn = (string) $bot->greeting_en;

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
            'knowledgeRetrieval' => ['required', 'in:'.Bot::RETRIEVAL_CLASSIC.','.Bot::RETRIEVAL_PRECISE],
            'messageLimit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'typingDelay' => ['required', 'integer', 'min:0', 'max:'.Bot::TYPING_DELAY_MAX],
            // Identidad e instrucciones (texto libre, con límites razonables).
            'roleDescription' => ['nullable', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:6000'],
            'tone' => ['nullable', 'string', 'max:255'],
            'restrictions' => ['nullable', 'string', 'max:2000'],
            'notFoundMessage' => ['nullable', 'string', 'max:500'],
            'handoffRules' => ['nullable', 'string', 'max:2000'],
            // Presentación del widget: texto plano (Unicode y emojis sí; HTML/scripts no).
            'welcomeEs' => ['nullable', 'string', 'max:200', 'not_regex:/<[^>]*>/'],
            'welcomeEn' => ['nullable', 'string', 'max:200', 'not_regex:/<[^>]*>/'],
            'buttonEs' => ['nullable', 'string', 'max:40', 'not_regex:/<[^>]*>/'],
            'buttonEn' => ['nullable', 'string', 'max:40', 'not_regex:/<[^>]*>/'],
            'greetingEs' => ['nullable', 'string', 'max:500', 'not_regex:/<[^>]*>/'],
            'greetingEn' => ['nullable', 'string', 'max:500', 'not_regex:/<[^>]*>/'],
        ], [
            'welcomeEs.not_regex' => __('El texto no admite HTML ni etiquetas.'),
            'welcomeEn.not_regex' => __('El texto no admite HTML ni etiquetas.'),
            'buttonEs.not_regex' => __('El texto no admite HTML ni etiquetas.'),
            'buttonEn.not_regex' => __('El texto no admite HTML ni etiquetas.'),
            'greetingEs.not_regex' => __('El texto no admite HTML ni etiquetas.'),
            'greetingEn.not_regex' => __('El texto no admite HTML ni etiquetas.'),
            'welcomeEs.max' => __('Máximo :max caracteres.'),
            'welcomeEn.max' => __('Máximo :max caracteres.'),
            'buttonEs.max' => __('Máximo :max caracteres.'),
            'buttonEn.max' => __('Máximo :max caracteres.'),
            'messageLimit.integer' => __('Escribe un número entero.'),
            'messageLimit.min' => __('Mínimo :min.'),
            'messageLimit.max' => __('Máximo :max.'),
            'typingDelay.required' => __('Escribe un número de segundos (0 = sin espera).'),
            'typingDelay.integer' => __('Escribe un número entero.'),
            'typingDelay.min' => __('Mínimo :min.'),
            'typingDelay.max' => __('Máximo :max.'),
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
        // Identidad e instrucciones del asesor (un asesor NUEVO nunca hereda el prompt de Celia:
        // uses_legacy_prompt queda en false; los existentes lo conservan mientras esto esté vacío).
        $bot->role_description = $this->plainText($this->roleDescription);
        $bot->instructions = $this->multilineText($this->instructions);
        $bot->tone = $this->plainText($this->tone);
        $bot->restrictions = $this->multilineText($this->restrictions);
        $bot->not_found_message = $this->plainText($this->notFoundMessage);
        $bot->handoff_rules = $this->multilineText($this->handoffRules);
        $bot->knowledge_retrieval = $this->knowledgeRetrieval;
        $bot->ai_message_limit = trim($this->messageLimit) !== '' ? (int) $this->messageLimit : null;
        $bot->typing_delay = (int) $this->typingDelay;
        // Por asesor (y por tanto por institución); vacío = el texto por defecto del widget.
        $bot->widget_welcome_es = $this->plainText($this->welcomeEs);
        $bot->widget_welcome_en = $this->plainText($this->welcomeEn);
        $bot->widget_button_es = $this->plainText($this->buttonEs);
        $bot->widget_button_en = $this->plainText($this->buttonEn);
        $bot->greeting_es = $this->multilineText($this->greetingEs);
        $bot->greeting_en = $this->multilineText($this->greetingEn);
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

        session()->flash('status', $creating ? __('Asesor creado. Completa su foto y conocimiento.') : __('Asesor actualizado.'));

        if ($creating) {
            return redirect()->route('advisors.edit', $bot->getKey());
        }

        return null;
    }

    /** «Probar asesor»: crea el enlace privado (o lo sustituye: el anterior deja de funcionar). */
    public function generatePreviewLink(AdvisorPreviewLinkService $links): void
    {
        abort_unless((bool) auth()->user()?->canManageIntegrations(), 403);
        $bot = $this->bot();
        if ($bot === null) {
            return;
        }

        $hadLink = $bot->preview_token_hash !== null;
        $links->generate($bot);
        session()->flash('status', $hadLink ? __('Enlace de prueba regenerado: el anterior ya no funciona.') : __('Enlace de prueba creado.'));
    }

    public function revokePreviewLink(AdvisorPreviewLinkService $links): void
    {
        abort_unless((bool) auth()->user()?->canManageIntegrations(), 403);
        $bot = $this->bot();
        if ($bot === null) {
            return;
        }

        $links->revoke($bot);
        session()->flash('status', __('Enlace de prueba revocado.'));
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
        session()->flash('status', __('Foto de perfil actualizada.'));
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
        session()->flash('status', __('Foto eliminada; se usa el avatar por defecto.'));
    }

    /**
     * Sube .md a la BIBLIOTECA central (mismo pipeline validado que el Centro de Conocimiento)
     * y los asigna automáticamente a ESTE asesor vía el pivote. Los rechazados se informan.
     */
    public function uploadKnowledge(KnowledgeIngestService $ingest, KnowledgeAssignmentService $assign): void
    {
        abort_unless((bool) auth()->user()?->canManageIntegrations(), 403);
        $bot = $this->bot();
        if ($bot === null || $this->docs === []) {
            return;
        }

        $report = $ingest->ingest($this->docs);
        $this->docs = [];

        foreach ($report['codes'] as $code) {
            $source = KnowledgeSource::query()->where('code', $code)->first();
            if ($source !== null) {
                $assign->assignSource($bot, $source);
            }
        }

        foreach ($report['results'] as $r) {
            if ($r['result'] === 'Rechazado') {
                $this->addError('docs', "{$r['file']}: {$r['reason']}");
            }
        }

        if ($report['codes'] !== []) {
            session()->flash('status', __(':n documento(s) añadido(s) a la biblioteca y asignado(s) a :bot.', ['n' => count($report['codes']), 'bot' => $bot->assistant_name]));
        }
    }

    /**
     * Quita el documento SOLO de este asesor (detach del pivote). No borra la fuente ni su
     * archivo: puede estar compartida con otros agentes. El borrado vive en la Biblioteca.
     */
    public function removeKnowledge(int $sourceId, KnowledgeAssignmentService $assign): void
    {
        abort_unless((bool) auth()->user()?->canManageIntegrations(), 403);
        $bot = $this->bot();
        if ($bot === null) {
            return;
        }

        $source = $bot->knowledgeSources()->where('knowledge_sources.id', $sourceId)->first();
        if ($source === null) {
            return;
        }

        $assign->detach($bot, $source);

        session()->flash('status', __('Documento quitado de este asesor (sigue disponible en la biblioteca).'));
    }

    public function sync(KnowledgeSyncService $sync): void
    {
        abort_unless((bool) auth()->user()?->canManageIntegrations(), 403);
        $bot = $this->bot();
        if ($bot === null) {
            return;
        }
        $report = $sync->sync($bot->getKey(), $bot->advisorFolder());
        session()->flash('status', __('Sincronizado: :created nuevas, :updated actualizadas.', ['created' => $report['created'], 'updated' => $report['updated']]));
    }

    /** «Correcciones aprendidas»: abre la edición de una respuesta aprobada de ESTE asesor. */
    public function editCorrection(int $id): void
    {
        $correction = $this->correction($id);
        $this->editingCorrectionId = (int) $correction->getKey();
        $this->correctionQuestion = (string) $correction->question;
        $this->correctionAnswer = (string) $correction->answer;
        $this->correctionTopic = (string) $correction->topic_line;
        $this->resetErrorBag();
    }

    public function cancelCorrection(): void
    {
        $this->reset(['editingCorrectionId', 'correctionQuestion', 'correctionAnswer', 'correctionTopic']);
    }

    public function saveCorrection(): void
    {
        if ($this->editingCorrectionId === null) {
            return;
        }
        $correction = $this->correction($this->editingCorrectionId);
        $this->validate([
            'correctionQuestion' => ['required', 'string', 'max:1000'],
            'correctionAnswer' => ['required', 'string', 'max:1000'],
            'correctionTopic' => ['nullable', 'in:'.implode(',', array_keys((array) config('crm.knowledge.lines', [])))],
        ], [
            'correctionQuestion.required' => __('Escribe la pregunta.'),
            'correctionAnswer.required' => __('Escribe la respuesta aprobada.'),
            'correctionQuestion.max' => __('Máximo :max caracteres.'),
            'correctionAnswer.max' => __('Máximo :max caracteres.'),
        ]);
        $correction->forceFill([
            'question' => trim($this->correctionQuestion),
            'answer' => trim($this->correctionAnswer),
            'topic_line' => $this->correctionTopic !== '' ? $this->correctionTopic : null,
        ])->save();
        $this->cancelCorrection();
        session()->flash('status', __('Corrección actualizada.'));
    }

    public function toggleCorrection(int $id): void
    {
        $correction = $this->correction($id);
        $correction->forceFill(['active' => ! $correction->active])->save();
        session()->flash('status', $correction->active ? __('Corrección activada.') : __('Corrección desactivada: el asesor deja de usarla.'));
    }

    public function deleteCorrection(int $id): void
    {
        $this->correction($id)->delete();
        if ($this->editingCorrectionId === $id) {
            $this->cancelCorrection();
        }
        session()->flash('status', __('Corrección eliminada.'));
    }

    /** Corrección de ESTE asesor (y de la institución activa, por el scope global); si no, 404. */
    private function correction(int $id): AdvisorCorrection
    {
        abort_unless((bool) auth()->user()?->canManageIntegrations(), 403);
        abort_if($this->botId === null, 404);

        return AdvisorCorrection::query()->where('bot_id', $this->botId)->findOrFail($id);
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
            $this->addError('deleteConfirmName', __('Escribe el nombre exacto del asesor para confirmar.'));

            return null;
        }

        $name = (string) $bot->assistant_name;
        $guard->delete($bot);

        session()->flash('status', __('Asesor «:name» eliminado permanentemente.', ['name' => $name]));

        return redirect()->route('advisors.index');
    }

    public function render(): View
    {
        $bot = $this->bot();

        // Fuentes ASIGNADAS a este asesor (pivote), activas o pausadas.
        $sources = $bot === null
            ? collect()
            : $bot->knowledgeSources()->orderBy('knowledge_sources.code')->get();

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
            'usesGlobalPrompt' => $bot !== null && $bot->usesGlobalPrompt(),
            'widgetDefaults' => ['es' => (array) config('crm.widget.default_texts.es'), 'en' => (array) config('crm.widget.default_texts.en')],
            'previewUrl' => $bot !== null ? app(AdvisorPreviewLinkService::class)->url($bot) : null,
            'feedback' => $bot !== null ? $this->feedbackSummary($bot) : null,
            'corrections' => $bot === null ? collect() : AdvisorCorrection::query()->with('user:id,name')->where('bot_id', $bot->getKey())->orderByDesc('id')->get(),
            'lineLabels' => (array) config('crm.knowledge.lines', []),
        ]);
    }

    /**
     * Resumen de las valoraciones del modo de prueba (evidencia para el equipo; no cambia nada).
     *
     * @return array{correct: int, needs_improvement: int, recent: \Illuminate\Support\Collection<int, AdvisorFeedback>}
     */
    private function feedbackSummary(Bot $bot): array
    {
        $counts = AdvisorFeedback::query()->where('bot_id', $bot->getKey())
            ->selectRaw('rating, count(*) as c')->groupBy('rating')->pluck('c', 'rating');

        return [
            'correct' => (int) ($counts[AdvisorFeedback::CORRECT] ?? 0),
            'needs_improvement' => (int) ($counts[AdvisorFeedback::NEEDS_IMPROVEMENT] ?? 0),
            'recent' => AdvisorFeedback::query()->with('message:id,content')
                ->where('bot_id', $bot->getKey())->where('rating', AdvisorFeedback::NEEDS_IMPROVEMENT)
                ->orderByDesc('updated_at')->limit(5)->get(),
        ];
    }

    /** Texto de varias líneas: conserva los saltos de línea, recorta y vacío → null. */
    private function multilineText(string $value): ?string
    {
        $value = trim((string) preg_replace("/[ \t]+/u", ' ', str_replace("\r\n", "\n", $value)));

        return $value === '' ? null : $value;
    }

    /** Texto plano recortado; vacío → null (se usa el texto por defecto del widget). */
    private function plainText(string $value): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $value === '' ? null : $value;
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
        $src = $base.'/widget/chat-widget.js?v='.config('crm.widget_asset_version', '1');

        return '<script src="'.$src.'"'."\n"
            .'        data-bot-key="'.$bot->public_key.'"'."\n"
            .'        data-api-base="'.$base.'"'."\n"
            .'        data-offset-bottom="'.$offset.'"></script>';
    }

    /**
     * Variante en JavaScript PURO (sin etiquetas <script>) para campos tipo "Custom
     * Scripts (Footer)" de WordPress/temas que no admiten <script>. Inserta el widget
     * con document.createElement; el script se localiza por data-bot-key (no depende de
     * document.currentScript). Mismo dominio, public_key y separacion inferior.
     *
     * Ambos snippets usan el nombre NEUTRO chat-widget.js para cualquier asesor (el antiguo
     * /widget/celia.js sigue sirviéndose por compatibilidad, ver WidgetScriptController).
     */
    private function embedSnippetJs(Bot $bot): string
    {
        $base = (string) config('crm.widget_embed_url');
        $offset = (int) config('crm.widget_offset_bottom', 90);
        $src = $base.'/widget/chat-widget.js?v='.config('crm.widget_asset_version', '1');

        return "(function () {\n"
            ."  var s = document.createElement('script');\n"
            ."  s.src = '".$src."';\n"
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
