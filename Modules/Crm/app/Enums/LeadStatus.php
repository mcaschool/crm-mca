<?php

declare(strict_types=1);

namespace Modules\Crm\Enums;

/**
 * Estados del lead (decision cerrada D3). 'enrolled' es TERMINAL en el CRM: la
 * entrega al sistema de gestion academica es de un modulo futuro; aqui solo se
 * marca.
 */
enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case Enrolled = 'enrolled';
    case Discarded = 'discarded';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Nuevo',
            self::Contacted => 'Contactado',
            self::Qualified => 'En seguimiento',
            self::Enrolled => 'Matriculado',
            self::Discarded => 'Descartado',
        };
    }

    /** Estado final: no admite mas transiciones. */
    public function isTerminal(): bool
    {
        return $this === self::Enrolled;
    }

    /** Clase del badge (colores exactos del prototipo). */
    public function badgeClass(): string
    {
        return match ($this) {
            self::New => 'st-nuevo',
            self::Contacted => 'st-cont',
            self::Qualified => 'st-seg',
            self::Enrolled => 'st-matr',
            self::Discarded => 'st-desc',
        };
    }

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
