<?php

declare(strict_types=1);

namespace Modules\Crm\Enums;

/**
 * Nivel de interes de un lead (decision cerrada D3).
 */
enum InterestLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Bajo',
            self::Medium => 'Medio',
            self::High => 'Alto',
        };
    }

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_map(fn (self $l) => $l->value, self::cases());
    }
}
