<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TipoDocumento: string implements HasColor, HasLabel
{
    case Reglamento = 'reglamento';
    case Ley = 'ley';
    case Manual = 'manual';
    case Otro = 'otro';

    public function getLabel(): string
    {
        return match ($this) {
            self::Reglamento => 'Reglamento',
            self::Ley => 'Ley',
            self::Manual => 'Manual o guía',
            self::Otro => 'Otro',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Reglamento, self::Ley => 'primary',
            self::Manual => 'warning',
            self::Otro => 'gray',
        };
    }

    /** Las normas obligan; los manuales y guías son material de apoyo. */
    public function esNormativo(): bool
    {
        return $this === self::Reglamento || $this === self::Ley;
    }

    /** Las normas se dividen por artículos; el resto por secciones y párrafos. */
    public function seDividePorArticulos(): bool
    {
        return $this->esNormativo();
    }
}
