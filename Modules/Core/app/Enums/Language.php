<?php

declare(strict_types=1);

namespace Modules\Core\Enums;

/**
 * Idiomas soportados por el sistema (decision cerrada: dos fijos, es predeterminado).
 */
enum Language: string
{
    case Spanish = 'es';
    case English = 'en';

    public function label(): string
    {
        return match ($this) {
            self::Spanish => 'Espanol',
            self::English => 'English',
        };
    }

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_map(fn (self $l) => $l->value, self::cases());
    }

    public static function default(): self
    {
        return self::Spanish;
    }
}
