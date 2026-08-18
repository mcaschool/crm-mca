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
use Modules\Ai\Services\KnowledgeSyncService;
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

    /** Sube uno o varios .md y sincroniza (upsert por codigo) sin pasos extra. */
    public function uploadKnowledge(KnowledgeSyncService $sync): void
    {
        $this->authorize('sync', KnowledgeSource::class);

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
                $this->addError('docs', __('Solo se admiten archivos .md (Markdown).'));

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
        session()->flash('status', __(':saved archivo(s) subido(s). Conocimiento sincronizado: :created nuevas, :updated actualizadas.', [
            'saved' => $saved,
            'created' => $report['created'],
            'updated' => $report['updated'],
        ]));
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

        $sources = $bot === null
            ? collect()
            : KnowledgeSource::query()
                ->where('bot_id', $bot->getKey())
                ->orderByDesc('priority')
                ->orderBy('code')
                ->get();

        return view('ai::livewire.advisor.configure', [
            'bot' => $bot,
            'sources' => $sources,
            'avatarUrl' => $bot?->avatarUrl(),
        ]);
    }

    private function bot(): ?Bot
    {
        return Bot::query()->where('status', 'active')->first();
    }
}
