<?php

declare(strict_types=1);

namespace Modules\Ai\Livewire\Advisor;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Ai\Services\KnowledgeAssignmentService;
use Modules\Ai\Services\KnowledgeIngestService;
use Modules\Ai\Services\KnowledgeSyncService;
use Modules\Ai\Support\SelectedAdvisor;
use Modules\Institutions\Models\Bot;

/**
 * Ficha del Asesor Academico (hoy Celia) configurable por Admin. Consolida:
 *  - Nombre del asesor (bots.assistant_name) -> lo leen el widget y los saludos.
 *  - Foto de perfil (avatar) subida al disco publico (storage:link).
 *  - Bases de conocimiento: subida de .md que se upsertean y sincronizan solas.
 *
 * Se apoya en la infraestructura multi-bot (dormante): cada asesor es un registro
 * `bots` con su carpeta aislada. NO toca conversacion, enrutamiento ni barreras.
 *
 * Solo Administrador (KnowledgeSourcePolicy). El aislamiento por institucion lo dan
 * los scopes globales; el bot se resuelve al unico activo de la instalacion.
 */
#[Layout('layouts.app')]
class Configure extends Component
{
    use WithFileUploads;

    #[Validate('required|string|max:60')]
    public string $name = '';

    /** Avatar en transito (previsualizacion antes de guardar). */
    public mixed $avatar = null;

    /** Archivos .md de conocimiento en transito. */
    /** @var array<int, mixed> */
    public array $docs = [];

    public function mount(): void
    {
        $this->authorize('viewAny', KnowledgeSource::class);
        $bot = $this->bot();
        $this->name = $bot !== null ? (string) $bot->assistant_name : '';
    }

    /** Guarda el nombre visible del asesor. */
    public function saveName(): void
    {
        $this->authorize('sync', KnowledgeSource::class);
        $this->validateOnly('name');

        $bot = $this->bot();
        if ($bot === null) {
            return;
        }
        $bot->assistant_name = trim($this->name);
        $bot->save();

        session()->flash('status', __('Nombre del asesor actualizado.'));
    }

    /** Sube y fija la foto de perfil del asesor. */
    public function saveAvatar(): void
    {
        $this->authorize('sync', KnowledgeSource::class);
        $this->validate([
            'avatar' => ['required', 'image', 'mimes:png,jpg,jpeg,svg,webp,gif', 'max:1024'],
        ]);

        $bot = $this->bot();
        if ($bot === null || ! $this->avatar instanceof TemporaryUploadedFile) {
            return;
        }

        $folder = 'advisors/'.$bot->advisorFolder();
        $ext = strtolower($this->avatar->getClientOriginalExtension() ?: 'png');

        // Se borra el avatar anterior si tenia otra extension (para no dejar huerfanos).
        if ($bot->avatar_path !== null && $bot->avatar_path !== $folder.'/avatar.'.$ext) {
            Storage::disk('public')->delete($bot->avatar_path);
        }

        $path = $this->avatar->storeAs($folder, 'avatar.'.$ext, 'public');

        $bot->avatar_path = $path;
        $bot->save();

        $this->avatar = null;
        session()->flash('status', __('Foto de perfil actualizada.'));
    }

    /** Quita la foto: vuelve al avatar por defecto. */
    public function removeAvatar(): void
    {
        $this->authorize('sync', KnowledgeSource::class);
        $bot = $this->bot();
        if ($bot === null || $bot->avatar_path === null) {
            return;
        }
        Storage::disk('public')->delete($bot->avatar_path);
        $bot->avatar_path = null;
        $bot->save();

        session()->flash('status', __('Foto de perfil eliminada; se usa el avatar por defecto.'));
    }

    /**
     * Sube .md a la BIBLIOTECA central (pipeline validado del Centro de Conocimiento) y los
     * asigna automáticamente al asesor seleccionado vía el pivote.
     */
    public function uploadKnowledge(KnowledgeIngestService $ingest, KnowledgeAssignmentService $assign): void
    {
        $this->authorize('sync', KnowledgeSource::class);

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
                $this->addError('docs', $r['file'].': '.$r['reason']);
            }
        }

        if ($report['codes'] !== []) {
            session()->flash('status', __(':n documento(s) añadido(s) a la biblioteca y asignado(s) a :bot.', [
                'n' => count($report['codes']),
                'bot' => $bot->assistant_name,
            ]));
        }
    }

    /** Re-sincroniza la carpeta de conocimiento del asesor (sin subir nada nuevo). */
    public function sync(KnowledgeSyncService $sync): void
    {
        $this->authorize('sync', KnowledgeSource::class);
        $bot = $this->bot();
        if ($bot === null) {
            return;
        }
        $report = $sync->sync($bot->getKey(), $bot->advisorFolder());
        session()->flash('status', __(':created nuevas, :updated actualizadas, :skipped omitidas.', [
            'created' => $report['created'],
            'updated' => $report['updated'],
            'skipped' => $report['skipped'],
        ]));
    }

    public function render(): View
    {
        $bot = $this->bot();

        // Fuentes ASIGNADAS al asesor seleccionado (pivote), no por bot_id.
        $sources = $bot === null
            ? collect()
            : $bot->knowledgeSources()
                ->orderByDesc('knowledge_sources.priority')
                ->orderBy('knowledge_sources.code')
                ->get();

        return view('ai::livewire.advisor.configure', [
            'bot' => $bot,
            'sources' => $sources,
            'avatarUrl' => $bot?->avatarUrl(),
        ]);
    }

    /** Asesor elegido en el selector de agente (fallback: primer bot activo). */
    private function bot(): ?Bot
    {
        return SelectedAdvisor::current();
    }
}
