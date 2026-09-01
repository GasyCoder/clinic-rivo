<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'episode_id', 'episode_orientation_id', 'obstetric_context',
    'pregnancy_data', 'prenatal_data', 'labor_data', 'delivery_data', 'newborn_data',
    'maternal_care_notes', 'baby_care_notes', 'observations', 'transmission_notes',
    'created_by', 'updated_by', 'completed_by', 'completed_at',
])]
class MaternityRecord extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'pregnancy_data' => 'array',
            'prenatal_data' => 'array',
            'labor_data' => 'array',
            'delivery_data' => 'array',
            'newborn_data' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function orientation(): BelongsTo
    {
        return $this->belongsTo(EpisodeOrientation::class, 'episode_orientation_id');
    }

    public function procedures(): HasMany
    {
        return $this->hasMany(MaternityProcedure::class)->latest('performed_at');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    protected function auditModule(): ?string
    {
        return 'maternity';
    }
}
