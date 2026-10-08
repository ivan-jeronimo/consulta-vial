<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ganador extends Model
{
    protected $table = 'ganadores';

    protected $fillable = ['dinamica_id', 'nombre', 'premio', 'foto', 'fecha', 'consentimiento'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'consentimiento' => 'boolean'];
    }

    public function dinamica(): BelongsTo
    {
        return $this->belongsTo(Dinamica::class);
    }

    /** Solo quienes aceptaron que su nombre aparezca en el sitio. */
    public function scopePublicable(Builder $query): void
    {
        $query->where('consentimiento', true);
    }
}
