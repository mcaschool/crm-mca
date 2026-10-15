<?php

declare(strict_types=1);

namespace Modules\Social\Services;

/**
 * Resultado de consultar el perfil de un contacto en Meta. Los campos que Meta no dio son null
 * (nunca cadenas vacías). $error resume el fallo de Meta sin datos sensibles (estado HTTP, code y
 * error_subcode); $permanent indica que Meta no lo permitirá aunque se reintente pronto (permiso
 * de la app, cuenta sin perfil…), frente a un fallo transitorio (red, límite, 5xx).
 */
final class ContactProfile
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $avatar = null,
        public readonly ?string $error = null,
        public readonly bool $permanent = false,
    ) {}

    public function isEmpty(): bool
    {
        return $this->name === null && $this->avatar === null && $this->firstName === null && $this->lastName === null;
    }
}
