<?php

declare(strict_types=1);

namespace Modules\Identity\Livewire\Profile;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Audit\Services\AuditService;
use Modules\Identity\Services\TwoFactorService;
use Modules\Identity\Services\UserAvatarService;

/**
 * "Mi perfil": el propio usuario ve sus datos en SOLO LECTURA (nombre, correo,
 * numero de identidad enmascarado, departamento). Puede gestionar SU FOTO de
 * perfil (es su imagen, no un dato sensible) y cambiar SU contrasena (validando la
 * actual + fortaleza). NO puede editar su correo/identidad ni gestionar a otros.
 */
#[Layout('layouts.app')]
class MiPerfil extends Component
{
    use WithFileUploads;

    public mixed $avatar = null;

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    /** Código TOTP que el usuario teclea para CONFIRMAR la activación del 2FA. */
    public string $confirmCode = '';

    /**
     * Códigos de recuperación mostrados UNA vez (tras activar o regenerar).
     *
     * @var array<int,string>
     */
    public array $recoveryCodesShown = [];

    /** El usuario sube/cambia SU propia foto de perfil. */
    public function saveAvatar(UserAvatarService $avatars): void
    {
        $this->validate(['avatar' => ['required', 'image', 'mimes:png,jpg,jpeg,svg,webp,gif', 'max:1024']]);
        if (! $this->avatar instanceof TemporaryUploadedFile) {
            return;
        }

        $avatars->store(auth()->user(), $this->avatar);
        $this->avatar = null;
        session()->flash('status', 'Foto de perfil actualizada.');
    }

    public function removeAvatar(UserAvatarService $avatars): void
    {
        $avatars->remove(auth()->user());
        session()->flash('status', 'Foto de perfil eliminada.');
    }

    public function updatePassword(AuditService $audit): void
    {
        $this->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [], ['current_password' => 'contraseña actual', 'password' => 'nueva contraseña']);

        $user = auth()->user();
        $user->password = Hash::make($this->password);
        $user->save();

        // Auditoria: solo el HECHO del cambio (jamas la contraseña).
        $audit->log('account.password_changed', $user);

        $this->reset(['current_password', 'password', 'password_confirmation']);
        session()->flash('status', 'Contraseña actualizada.');
    }

    /** Paso 1: genera secreto + códigos de recuperación y muestra el QR (sin confirmar). */
    public function enableTwoFactor(TwoFactorService $tfa): void
    {
        $user = auth()->user();
        if ($user->hasTwoFactorEnabled()) {
            return;
        }

        $user->two_factor_secret = $tfa->generateSecret();
        $user->two_factor_recovery_codes = $tfa->generateRecoveryCodes();
        $user->two_factor_confirmed_at = null;
        $user->save();

        $this->confirmCode = '';
        $this->recoveryCodesShown = [];
    }

    /** Paso 2: confirma con un código de la app; activa el 2FA y muestra los códigos. */
    public function confirmTwoFactor(TwoFactorService $tfa, AuditService $audit): void
    {
        $user = auth()->user();
        if ($user->two_factor_secret === null || $user->hasTwoFactorEnabled()) {
            return;
        }

        $this->validate(['confirmCode' => ['required', 'string']], [], ['confirmCode' => 'código']);

        if (! $tfa->verify((string) $user->two_factor_secret, $this->confirmCode)) {
            $this->addError('confirmCode', __('auth.two_factor_failed'));

            return;
        }

        $user->two_factor_confirmed_at = now();
        $user->save();
        $audit->log('2fa.enabled', $user);

        $this->recoveryCodesShown = $user->two_factor_recovery_codes ?? [];
        $this->confirmCode = '';
        session()->flash('status', 'Verificación en dos pasos activada.');
    }

    /** Regenera los códigos de recuperación (los muestra una vez). */
    public function regenerateRecoveryCodes(TwoFactorService $tfa): void
    {
        $user = auth()->user();
        if (! $user->hasTwoFactorEnabled()) {
            return;
        }

        $user->two_factor_recovery_codes = $tfa->generateRecoveryCodes();
        $user->save();

        $this->recoveryCodesShown = $user->two_factor_recovery_codes ?? [];
        session()->flash('status', 'Nuevos códigos de recuperación generados.');
    }

    /** Desactiva el 2FA (la política obligatoria forzará a reactivarlo en la próxima página). */
    public function disableTwoFactor(AuditService $audit): void
    {
        $user = auth()->user();
        $user->two_factor_secret = null;
        $user->two_factor_recovery_codes = null;
        $user->two_factor_confirmed_at = null;
        $user->save();
        $audit->log('2fa.disabled', $user);

        $this->recoveryCodesShown = [];
        $this->confirmCode = '';
        session()->flash('status', 'Verificación en dos pasos desactivada.');
    }

    public function dismissRecoveryCodes(): void
    {
        $this->recoveryCodesShown = [];
    }

    public function render(TwoFactorService $tfa): View
    {
        $user = auth()->user();

        // QR solo durante la configuración (secreto generado pero aún sin confirmar).
        $qr = null;
        if (! $user->hasTwoFactorEnabled() && $user->two_factor_secret !== null) {
            $qr = $tfa->qrSvg((string) $user->email, (string) $user->two_factor_secret);
        }

        return view('identity::livewire.profile.mi-perfil', [
            'user' => $user,
            'twoFactorQr' => $qr,
        ]);
    }
}
