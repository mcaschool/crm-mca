<?php

declare(strict_types=1);

namespace Modules\Ai\Enums;

/**
 * Categorías de error NORMALIZADAS de cualquier proveedor de IA. Cada proveedor
 * traduce sus códigos concretos a una de estas categorías (vía su error_map en
 * config/ai_models.php), para que las alertas y la UX del CRM NO dependan de
 * códigos específicos de Alibaba/OpenAI/etc.
 */
enum AiErrorCategory: string
{
    case AuthenticationError = 'authentication_error';
    case QuotaExhausted = 'quota_exhausted';
    case RateLimited = 'rate_limited';
    case ModelUnavailable = 'model_unavailable';
    case Timeout = 'timeout';
    case ProviderUnavailable = 'provider_unavailable';
    case InvalidRequest = 'invalid_request';
    case Unknown = 'unknown';

    /**
     * Traduce (status HTTP + código del proveedor) a una categoría normalizada. El
     * error_map del proveedor tiene PRECEDENCIA sobre el mapeo por status (p. ej. un
     * 403 con code=insufficient_quota es quota_exhausted, no authentication_error).
     *
     * @param  array<string,string>  $errorMap  código del proveedor => valor de categoría
     */
    public static function fromResponse(int $status, ?string $providerCode, array $errorMap = []): self
    {
        if ($providerCode !== null && $providerCode !== '' && isset($errorMap[$providerCode])) {
            return self::tryFrom($errorMap[$providerCode]) ?? self::Unknown;
        }

        return match (true) {
            $status === 401 => self::AuthenticationError,
            $status === 403 => self::AuthenticationError,
            $status === 404 => self::ModelUnavailable,
            $status === 408 => self::Timeout,
            $status === 429 => self::RateLimited,
            $status >= 500 => self::ProviderUnavailable,
            $status === 400 => self::InvalidRequest,
            default => self::Unknown,
        };
    }

    /** ¿Reintentar tiene sentido? (para alertas / backoff futuros). */
    public function isRetryable(): bool
    {
        return in_array($this, [self::RateLimited, self::Timeout, self::ProviderUnavailable], true);
    }

    /** Etiqueta administrativa legible (para la notificación al admin). */
    public function label(): string
    {
        return match ($this) {
            self::AuthenticationError => 'Error de autenticación',
            self::QuotaExhausted => 'Cuota agotada',
            self::RateLimited => 'Límite de solicitudes',
            self::ModelUnavailable => 'Modelo no disponible',
            self::Timeout => 'Tiempo de espera agotado',
            self::ProviderUnavailable => 'Proveedor no disponible',
            self::InvalidRequest => 'Solicitud inválida',
            self::Unknown => 'Error desconocido',
        };
    }
}
