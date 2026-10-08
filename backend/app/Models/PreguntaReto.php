<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class PreguntaReto extends Model
{
    public const ESTADOS = ['borrador' => 'Borrador', 'aprobada' => 'Aprobada', 'descartada' => 'Descartada'];

    protected $table = 'preguntas_reto';

    protected $fillable = ['documento_id', 'fuentes', 'categoria', 'pregunta', 'opciones', 'correcta', 'explicacion', 'cita', 'estado', 'modelo'];

    protected $attributes = ['estado' => 'borrador'];

    protected function casts(): array
    {
        return ['fuentes' => 'array', 'opciones' => 'array', 'correcta' => 'integer'];
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoReglamento::class, 'documento_id');
    }

    public function scopeAprobada(Builder $query): void
    {
        $query->where('estado', 'aprobada');
    }

    public function respuestaCorrecta(): ?string
    {
        return $this->opciones[$this->correcta] ?? null;
    }

    /** Texto original del que salió la pregunta, para revisarla en el panel. */
    public function textoFuente(): Collection
    {
        return ReglamentoArticulo::whereIn('id', $this->fuentes ?? [])->orderBy('orden')->pluck('contenido');
    }
}
