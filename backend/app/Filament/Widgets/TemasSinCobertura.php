<?php

namespace App\Filament\Widgets;

class TemasSinCobertura extends TemasMasConsultados
{
    protected static ?int $sort = 5;

    protected bool $soloSinCobertura = true;
}
