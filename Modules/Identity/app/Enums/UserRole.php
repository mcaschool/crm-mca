<?php

declare(strict_types=1);

namespace Modules\Identity\Enums;

/**
 * Roles del panel (decision cerrada). Tres roles fijos; sin permisos granulares
 * en el dia 1. La autorizacion se resuelve por Policies/Gates, nunca por
 * comparaciones de string dispersas.
 *
 * Ruta de escape documentada: si mas adelante hace falta permiso por accion,
 * se migra a spatie/laravel-permission sin tocar el negocio, porque toda
 * autorizacion ya pasa por Gate/Policy.
 */
enum UserRole: string
{
    case Admin = 'admin';
    case Marketing = 'marketing';
    case Admissions = 'admissions';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::Marketing => 'Marketing',
            self::Admissions => 'Admisiones',
        };
    }

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_map(fn (self $r) => $r->value, self::cases());
    }
}
