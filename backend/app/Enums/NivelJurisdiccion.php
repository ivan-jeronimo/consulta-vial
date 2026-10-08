<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum NivelJurisdiccion: string implements HasColor, HasLabel
{
    case Federal = 'federal';
    case Estatal = 'estatal';
    case Municipal = 'municipal';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Federal => 'primary',
            self::Estatal => 'info',
            self::Municipal => 'gray',
        };
    }
}
