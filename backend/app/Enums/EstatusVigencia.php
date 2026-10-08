<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EstatusVigencia: string implements HasColor, HasLabel
{
    case Vigente = 'vigente';
    case Derogado = 'derogado';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Vigente => 'success',
            self::Derogado => 'gray',
        };
    }
}
