<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EstatusProcesamiento: string implements HasColor, HasLabel
{
    case Pendiente = 'pendiente';
    case Procesando = 'procesando';
    case Completado = 'completado';
    case Error = 'error';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pendiente => 'gray',
            self::Procesando => 'warning',
            self::Completado => 'success',
            self::Error => 'danger',
        };
    }

    public function enCurso(): bool
    {
        return $this === self::Pendiente || $this === self::Procesando;
    }
}
