<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'patient_id', 'employee_id', 'active_patient_key', 'active_employee_key',
    'linked_by', 'linked_at', 'ended_by', 'ended_at', 'end_reason',
])]
class PatientStaffLink extends Model
{
    use Auditable, HasUuid;

    protected static function booted(): void
    {
        static::saving(function (self $link): void {
            if ($link->ended_at === null) {
                $link->active_patient_key = "patient:{$link->patient_id}";
                $link->active_employee_key = "employee:{$link->employee_id}";
                $link->linked_at ??= now();

                return;
            }

            $link->active_patient_key = null;
            $link->active_employee_key = null;
        });
    }

    protected function casts(): array
    {
        return [
            'linked_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function linker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by');
    }

    public function ender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    public function isActive(): bool
    {
        return $this->ended_at === null;
    }

    protected function auditModule(): ?string
    {
        return 'administration';
    }
}
