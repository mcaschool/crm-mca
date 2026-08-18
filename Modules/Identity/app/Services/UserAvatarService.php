<?php

declare(strict_types=1);

namespace Modules\Identity\Services;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Foto de perfil de un usuario (asesor humano). Mismo mecanismo que el avatar de
 * los Asesores Inteligentes: se guarda en el disco publico users/{id}/avatar.ext,
 * servido por storage:link. La validacion (tipo/tamano) la hace el componente.
 */
class UserAvatarService
{
    /** Guarda/reemplaza la foto de perfil y devuelve la ruta relativa. */
    public function store(User $user, TemporaryUploadedFile $file): string
    {
        $folder = $user->avatarFolder();
        $ext = strtolower($file->getClientOriginalExtension() ?: 'png');

        // Borra la anterior si tenia otra extension (para no dejar huerfanos).
        if ($user->avatar_path !== null && $user->avatar_path !== $folder.'/avatar.'.$ext) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $path = $file->storeAs($folder, 'avatar.'.$ext, 'public');

        $user->avatar_path = $path;
        $user->save();

        return $path;
    }

    /** Quita la foto: vuelve al avatar por defecto. */
    public function remove(User $user): void
    {
        if ($user->avatar_path === null) {
            return;
        }
        Storage::disk('public')->delete($user->avatar_path);
        $user->avatar_path = null;
        $user->save();
    }
}
