<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-161 — un relevé de constantes pendant le séjour.
 *
 * La fiche Soins (`care_records`) est unique par passage : elle reste le
 * relevé de triage. Un patient au lit est surveillé plusieurs fois par jour,
 * chaque relevé est une ligne datée. Il se corrige (auteur de la correction
 * conservé, ancienne valeur à l'audit), ne se supprime jamais.
 */
#[Fillable([
    'episode_id', 'hospital_stay_id', 'measured_at',
    'blood_pressure_systolic', 'blood_pressure_diastolic',
    'heart_rate', 'spo2', 'temperature_celsius', 'notes',
    'measured_by', 'updated_by',
])]
class VitalSignReading extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'measured_at' => 'datetime',
            'blood_pressure_systolic' => 'integer',
            'blood_pressure_diastolic' => 'integer',
            'heart_rate' => 'integer',
            'spo2' => 'integer',
            'temperature_celsius' => 'decimal:2',
        ];
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function hospitalStay(): BelongsTo
    {
        return $this->belongsTo(HospitalStay::class);
    }

    public function measuredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'measured_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw new \LogicException('Un relevé de constantes ne se supprime pas (ADR-161).');
        });
    }
}
