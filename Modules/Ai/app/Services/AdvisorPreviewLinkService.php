<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Illuminate\Support\Str;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Bot;

/**
 * Enlace PRIVADO de prueba de un asesor (/asesores/prueba/{token}).
 *
 * - Token aleatorio de 48 caracteres (sin ids ni datos en la URL): no se puede enumerar.
 * - Se busca por su hash SHA-256 (columna única); la copia cifrada (cast encrypted, APP_KEY)
 *   solo sirve para volver a mostrarlo/copiarlo en la ficha del asesor.
 * - Regenerar invalida el anterior; revocar lo anula. Un enlace resuelve SOLO su asesor y su
 *   institución.
 */
final class AdvisorPreviewLinkService
{
    public const TOKEN_LENGTH = 48;

    public function __construct(private readonly CurrentInstitution $tenancy) {}

    /** Crea (o sustituye) el enlace del asesor. Devuelve el token en claro. */
    public function generate(Bot $bot): string
    {
        $token = Str::random(self::TOKEN_LENGTH);

        $bot->forceFill([
            'preview_token_hash' => self::hash($token),
            'preview_token' => $token,
            'preview_token_created_at' => now(),
        ])->save();

        return $token;
    }

    public function revoke(Bot $bot): void
    {
        $bot->forceFill([
            'preview_token_hash' => null,
            'preview_token' => null,
            'preview_token_created_at' => null,
        ])->save();
    }

    /** URL del enlace vigente del asesor, o null si no tiene. */
    public function url(Bot $bot): ?string
    {
        $token = $bot->preview_token;

        return is_string($token) && $token !== '' ? route('advisors.preview', ['token' => $token]) : null;
    }

    /**
     * Asesor de un token (de cualquier institución: la página de prueba aún no tiene contexto).
     * Formato inválido, revocado o desconocido → null. Quien llama fija después el contexto de
     * la institución del asesor devuelto.
     */
    public function resolve(string $token): ?Bot
    {
        if (strlen($token) !== self::TOKEN_LENGTH || ! ctype_alnum($token)) {
            return null;
        }

        $hash = self::hash($token);

        return $this->tenancy->runGlobally(fn (): ?Bot => Bot::query()->where('preview_token_hash', $hash)->first());
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
