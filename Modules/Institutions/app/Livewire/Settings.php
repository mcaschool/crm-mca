<?php

declare(strict_types=1);

namespace Modules\Institutions\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;

/**
 * Ajustes generales del panel. Hoy: MARCA (logo institucional que se muestra arriba a
 * la izquierda). Solo Administrador. El logo se guarda en el disco `public`
 * (servido por el symlink public/storage) y el layout lo usa si existe, con fallback
 * al icono + texto por defecto.
 */
#[Layout('layouts.app')]
class Settings extends Component
{
    use WithFileUploads;

    /** Imagen del logo en transito (previsualizacion antes de guardar). */
    public mixed $logo = null;

    /** Alto del logo en el sidebar (px). Rango sano en Institution::LOGO_SIZE_*. */
    public int $logoSize = Institution::LOGO_SIZE_DEFAULT;

    public function mount(): void
    {
        abort_unless((bool) auth()->user()?->canManageSettings(), 403);

        $this->logoSize = $this->institution()->logoSize();
    }

    /** Validacion en tiempo real al elegir el archivo (feedback inmediato). */
    public function updatedLogo(): void
    {
        $this->validate([
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:1024'],
        ], [], ['logo' => 'logo']);
    }

    public function saveLogo(): void
    {
        abort_unless((bool) auth()->user()?->canManageSettings(), 403);

        $this->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:1024'],
        ], [], ['logo' => 'logo']);

        if (! $this->logo instanceof TemporaryUploadedFile) {
            return;
        }

        $institution = $this->institution();
        $folder = $institution->logoFolder();
        $ext = strtolower($this->logo->getClientOriginalExtension() ?: 'png');

        // Borra el logo anterior si cambia la extension (evita huerfanos).
        if ($institution->logo_path !== null && $institution->logo_path !== $folder.'/logo.'.$ext) {
            Storage::disk('public')->delete($institution->logo_path);
        }

        $institution->logo_path = $this->logo->storeAs($folder, 'logo.'.$ext, 'public');
        $institution->save();

        $this->logo = null;
        session()->flash('status', 'Logo institucional actualizado.');
    }

    /** Guarda el tamaño (alto en px) del logo, acotado al rango sano. */
    public function saveSize(): void
    {
        abort_unless((bool) auth()->user()?->canManageSettings(), 403);

        $this->validate([
            'logoSize' => ['required', 'integer', 'min:'.Institution::LOGO_SIZE_MIN, 'max:'.Institution::LOGO_SIZE_MAX],
        ], [], ['logoSize' => 'tamaño']);

        $institution = $this->institution();
        $institution->logo_size = $this->logoSize;
        $institution->save();

        session()->flash('status', 'Tamaño del logo actualizado.');
    }

    public function removeLogo(): void
    {
        abort_unless((bool) auth()->user()?->canManageSettings(), 403);

        $institution = $this->institution();
        if ($institution->logo_path !== null) {
            Storage::disk('public')->delete($institution->logo_path);
            $institution->logo_path = null;
            $institution->save();
        }

        session()->flash('status', 'Logo eliminado; se usa la marca por defecto.');
    }

    private function institution(): Institution
    {
        return Institution::query()->findOrFail(app(CurrentInstitution::class)->idOrFail());
    }

    public function render(): View
    {
        $institution = $this->institution();

        return view('institutions::livewire.settings', [
            'institution' => $institution,
            'logoUrl' => $institution->logoUrl(),
        ]);
    }
}
