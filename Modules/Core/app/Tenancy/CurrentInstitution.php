<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy;

use Modules\Core\Exceptions\MissingInstitutionContextException;

/**
 * Contexto explicito de la institucion activa (Capa 1 del andamiaje multi-institucion).
 *
 * NUNCA se adivina la institucion: se ESTABLECE en la frontera de la peticion
 * (middleware de panel / de bot), o en la frontera de un job / comando de consola.
 * El resto del sistema solo lee de aqui.
 *
 * Registrado como singleton en el contenedor (ver CoreServiceProvider), de modo
 * que un unico valor viaja por toda la peticion.
 */
final class CurrentInstitution
{
    private ?int $institutionId = null;

    /** Marca si el contexto fue fijado deliberadamente (aunque sea a null en CLI). */
    private bool $resolved = false;

    /**
     * Modo global EXPLICITO: permite operar sin filtro de institucion
     * (mantenimiento CLI cross-tenant, seeders). Nunca se activa por accidente;
     * hay que pedirlo a proposito. Sin id y sin este modo, el scope FALLA RUIDOSO.
     */
    private bool $global = false;

    public function set(?int $institutionId): void
    {
        $this->institutionId = $institutionId;
        $this->resolved = true;
    }

    public function forget(): void
    {
        $this->institutionId = null;
        $this->resolved = false;
        $this->global = false;
    }

    public function isGlobal(): bool
    {
        return $this->global;
    }

    /**
     * Ejecuta un callback en modo global (sin filtro de institucion), restaurando
     * el estado anterior. Unico camino autorizado para consultas cross-tenant.
     *
     * @template TGlobal
     *
     * @param  callable():TGlobal  $callback
     * @return TGlobal
     */
    public function runGlobally(callable $callback): mixed
    {
        $previousId = $this->institutionId;
        $previousResolved = $this->resolved;
        $previousGlobal = $this->global;

        $this->institutionId = null;
        $this->resolved = true;
        $this->global = true;

        try {
            return $callback();
        } finally {
            $this->institutionId = $previousId;
            $this->resolved = $previousResolved;
            $this->global = $previousGlobal;
        }
    }

    public function id(): ?int
    {
        return $this->institutionId;
    }

    public function idOrFail(string $context = 'operacion'): int
    {
        if ($this->institutionId === null) {
            throw MissingInstitutionContextException::forCreate($context);
        }

        return $this->institutionId;
    }

    public function has(): bool
    {
        return $this->institutionId !== null;
    }

    /**
     * ¿Se fijo el contexto deliberadamente? Distingue "sin institucion aun"
     * (arranque, CLI sin --institution) de "institucion = X".
     */
    public function isResolved(): bool
    {
        return $this->resolved;
    }

    /**
     * Ejecuta un callback con una institucion activa, restaurando el contexto
     * anterior al terminar. Util en jobs, comandos y seeders que iteran
     * instituciones.
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public function runFor(int $institutionId, callable $callback): mixed
    {
        $previousId = $this->institutionId;
        $previousResolved = $this->resolved;
        $previousGlobal = $this->global;

        $this->institutionId = $institutionId;
        $this->resolved = true;
        $this->global = false;

        try {
            return $callback();
        } finally {
            $this->institutionId = $previousId;
            $this->resolved = $previousResolved;
            $this->global = $previousGlobal;
        }
    }
}
