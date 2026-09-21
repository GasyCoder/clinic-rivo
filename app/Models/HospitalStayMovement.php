<?php

namespace App\Models;

use App\Enums\HospitalCareLevel;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-161 — un emplacement du patient pendant le séjour.
 *
 * Le premier mouvement est l'admission ; une mutation ferme le mouvement en
 * cours et en ouvre un autre. Rien ne s'écrase : on sait où le patient a été,
 * à quel niveau de soins, et depuis quand. Jamais supprimé.
 */
#[Fillable([
    'hospital_stay_id', 'service', 'room_bed', 'hospital_bed_id', 'care_level',
    'started_at', 'ended_at', 'reason', 'moved_by', 'updated_by',
])]
class HospitalStayMovement extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'care_level' => HospitalCareLevel::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function hospitalStay(): BelongsTo
    {
        return $this->belongsTo(HospitalStay::class);
    }

    /** ADR-164 — le lit de cet emplacement, quand le site a configuré ses lits. */
    public function bed(): BelongsTo
    {
        return $this->belongsTo(HospitalBed::class, 'hospital_bed_id')->withTrashed();
    }

    public function movedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moved_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw new \LogicException('Un mouvement de séjour ne se supprime pas (ADR-161).');
        });
    }
}
