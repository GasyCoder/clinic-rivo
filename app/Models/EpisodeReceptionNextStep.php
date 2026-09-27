<?php

namespace App\Models;

use App\Enums\ReceptionNextStep;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-177 — une prochaine étape suggérée par la Réception pour un passage.
 *
 * Indicative, jamais une restriction : elle ne décide pas qui voit le passage
 * et ne vaut pas orientation. Le changement d'une suggestion est tracé une
 * seule fois, avec l'ancien et le nouvel ensemble, par
 * `SetEpisodeReceptionNextStepsAction` — pas ligne par ligne.
 */
#[Fillable(['episode_id', 'module', 'created_by'])]
class EpisodeReceptionNextStep extends Model
{
    protected function casts(): array
    {
        return [
            'module' => ReceptionNextStep::class,
        ];
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
