<?php

declare(strict_types=1);

namespace Modules\Notifications\Livewire\EmailTemplates;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Notifications\Models\EmailTemplate;
use Modules\Notifications\Support\AttachmentValidator;
use Modules\Notifications\Support\EmailBodyTransport;
use Modules\Notifications\Support\EmailHtmlSanitizer;
use Modules\Notifications\Support\TagResolver;
use Modules\Notifications\Support\TemplateBodyImages;

/**
 * Gestión del REPOSITORIO de plantillas de correo. Una misma pantalla sirve dos
 * ámbitos según la ruta:
 *   - scope 'shared'  (/ajustes/plantillas): plantillas COMPARTIDAS del equipo. Solo
 *     Administrador (canManageSettings). Aparece como pestaña dentro de Ajustes.
 *   - scope 'mine'    (/mis-plantillas): plantillas PROPIAS (privadas) del usuario.
 *     Cualquiera que pueda enviar correo gestiona LAS SUYAS; nadie ve las de otro.
 *
 * El editor es el mismo enriquecido del compositor (formato, tipografía, tamaño,
 * imágenes y etiquetas dinámicas). El cuerpo se SANITIZA al guardar; las imágenes se
 * persisten para viajar por CID al usar la plantilla. Todo acotado por institución.
 */
#[Layout('layouts.app')]
class Manage extends Component
{
    use WithFileUploads;

    /** Ámbito de la pantalla: 'shared' (compartidas) | 'mine' (propias). */
    public string $scope = 'mine';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $subject = '';

    /** HTML del editor (contenteditable). Se SANITIZA en el servidor al guardar. */
    public string $body = '';

    public string $tstatus = 'active';

    /** Subida puntual de una imagen inline (se procesa y se limpia enseguida). */
    public mixed $inlineUpload = null;

    /** @var array<int, TemporaryUploadedFile> imágenes inline nuevas en composición */
    public array $inlineImages = [];

    /** @var array<int, string> cid de cada imagen inline nueva (mismo índice) */
    public array $inlineCids = [];

    public function mount(string $scope = 'mine'): void
    {
        $this->scope = $scope === 'shared' ? 'shared' : 'mine';

        // Puerta de entrada: gestionar COMPARTIDAS es solo de Administrador; gestionar
        // las PROPIAS, de cualquiera que pueda enviar correo.
        if ($this->scope === 'shared') {
            abort_unless(auth()->user()?->canManageSettings() ?? false, 403);
        } else {
            $this->authorize('viewAny', EmailTemplate::class);
        }
    }

    /** @return Collection<int, EmailTemplate> */
    private function templates(): Collection
    {
        $query = EmailTemplate::query()->orderBy('name');

        return $this->scope === 'shared'
            ? $query->shared()->get()
            : $query->ownedBy((int) auth()->id())->get();
    }

    /** Nueva plantilla en el ámbito actual. */
    public function newTemplate(): void
    {
        $this->authorize($this->scope === 'shared' ? 'createShared' : 'createOwn', EmailTemplate::class);
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id, TemplateBodyImages $images): void
    {
        $template = EmailTemplate::query()->findOrFail($id);
        $this->authorize('update', $template);

        $this->editingId = $template->getKey();
        $this->name = (string) $template->name;
        $this->subject = (string) $template->subject;
        $this->body = (string) $template->body;
        $this->tstatus = (string) $template->status;
        $this->inlineImages = [];
        $this->inlineCids = [];
        $this->inlineUpload = null;
        $this->resetErrorBag();
        $this->showForm = true;

        // Reinyecta el cuerpo (con las imágenes ya guardadas visibles) en el editor.
        $this->dispatch('template-editor-load', html: $images->displayBody($template));
    }

    /**
     * Al subir una imagen inline: se VALIDA en el servidor (imagen real + tamaño) y,
     * si pasa, se inserta en el editor con su marca data-cid.
     */
    public function updatedInlineUpload(AttachmentValidator $validator): void
    {
        $file = $this->inlineUpload;
        if (! $file instanceof TemporaryUploadedFile) {
            return;
        }

        $error = $validator->imageError($file);
        if ($error !== null) {
            $this->addError('inlineUpload', $error);
            $this->inlineUpload = null;

            return;
        }

        $cid = 'img'.Str::random(20);
        $this->inlineImages[] = $file;
        $this->inlineCids[] = $cid;

        $this->dispatch('insert-inline-image', url: $file->temporaryUrl(), cid: $cid);

        $this->inlineUpload = null;
        $this->resetErrorBag('inlineUpload');
    }

    public function save(EmailHtmlSanitizer $sanitizer, TemplateBodyImages $images): void
    {
        $creating = $this->editingId === null;
        $template = $creating ? null : EmailTemplate::query()->findOrFail($this->editingId);

        // El editor envía el cuerpo en base64 (evita que el WAF del hosting bloquee el
        // POST con HTML crudo). Se decodifica aquí; luego pasa por el sanitizador igual.
        $this->body = app(EmailBodyTransport::class)->decode($this->body);

        // Permiso: crear (según ámbito) o editar la plantilla concreta.
        if ($creating) {
            $this->authorize($this->scope === 'shared' ? 'createShared' : 'createOwn', EmailTemplate::class);
        } else {
            $this->authorize('update', $template);
        }

        $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:100000'],
            'tstatus' => ['required', 'in:active,inactive'],
        ], [], [
            'name' => 'nombre',
            'subject' => 'asunto',
            'body' => 'cuerpo',
            'tstatus' => 'estado',
        ]);

        // El cuerpo debe tener contenido real (texto o alguna imagen), no solo etiquetas vacías.
        $clean = $sanitizer->sanitize($this->body);
        if (trim(strip_tags($clean, '<img>')) === '' && ! str_contains($clean, '<img')) {
            $this->addError('body', 'Escribe el contenido de la plantilla.');

            return;
        }

        if ($template === null) {
            $template = new EmailTemplate;
        }
        $template->fill([
            // Compartida => sin dueño; propia => del usuario actual.
            'user_id' => $this->scope === 'shared' ? null : (int) auth()->id(),
            'name' => $this->name,
            'subject' => $this->subject,
            'body' => $clean,
            'status' => $this->tstatus,
        ]);
        $template->save();

        // Persiste/reconcilia las imágenes inline (nuevas subidas + las que siguen).
        $newImages = [];
        foreach ($this->inlineImages as $i => $file) {
            $cid = $this->inlineCids[$i] ?? null;
            if ($cid !== null) {
                $newImages[$cid] = [
                    'path' => (string) $file->getRealPath(),
                    'mime' => (string) $file->getMimeType(),
                    'size' => (int) $file->getSize(),
                ];
            }
        }
        $images->persist($template, $clean, $newImages);

        $this->showForm = false;
        $this->resetForm();
        session()->flash('status', 'Plantilla guardada.');
    }

    public function delete(int $id): void
    {
        $template = EmailTemplate::query()->findOrFail($id);
        $this->authorize('delete', $template);
        $template->delete();  // las imágenes caen por FK cascade; el archivo queda inerte en disco privado
        session()->flash('status', 'Plantilla eliminada.');
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'subject', 'body', 'inlineImages', 'inlineCids', 'inlineUpload']);
        $this->tstatus = 'active';
        $this->resetErrorBag();
        $this->dispatch('template-editor-load', html: '');
    }

    public function render(): View
    {
        return view('notifications::livewire.email-templates.manage', [
            'templates' => $this->templates(),
            'tagCatalog' => app(TagResolver::class)->catalog(),
            'canCodeMode' => auth()->user()?->canUseEmailCodeMode() ?? false,
        ]);
    }
}
