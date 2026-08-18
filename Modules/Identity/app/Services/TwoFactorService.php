<?php

declare(strict_types=1);

namespace Modules\Identity\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Segundo factor TOTP (compatible con Google/Microsoft Authenticator, Authy).
 * Envuelve pragmarx/google2fa (motor) + bacon/bacon-qr-code (QR SVG). El secreto y
 * los codigos de recuperacion se guardan CIFRADOS por el modelo (cast 'encrypted').
 * NUNCA se registra el secreto ni el codigo en logs.
 */
class TwoFactorService
{
    private Google2FA $engine;

    public function __construct()
    {
        $this->engine = new Google2FA;
    }

    /** Nuevo secreto TOTP (base32). */
    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey();
    }

    /** Verifica un codigo de 6 digitos contra el secreto (con ventana de deriva ±1). */
    public function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if ($secret === '' || $code === '') {
            return false;
        }

        return $this->engine->verifyKey($secret, $code);
    }

    /** SVG del QR con la URI otpauth:// para escanear en la app autenticadora. */
    public function qrSvg(string $accountLabel, string $secret): string
    {
        $issuer = (string) config('app.name', 'MCA School');
        $url = $this->engine->getQRCodeUrl($issuer, $accountLabel, $secret);

        $renderer = new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($url);
    }

    /**
     * Genera codigos de recuperacion de un solo uso (formato XXXXX-XXXXX).
     *
     * @return array<int,string>
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = Str::upper(Str::random(5).'-'.Str::random(5));
        }

        return $codes;
    }

    /**
     * Consume (uso unico) un codigo de recuperacion del usuario. Devuelve true si
     * era valido; lo elimina de la lista para que no se reutilice.
     */
    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $code = Str::upper(trim($code));
        $codes = $user->two_factor_recovery_codes ?? [];

        $index = array_search($code, array_map(fn ($c) => Str::upper((string) $c), $codes), true);
        if ($index === false) {
            return false;
        }

        unset($codes[$index]);
        $user->two_factor_recovery_codes = array_values($codes);
        $user->save();

        return true;
    }
}
