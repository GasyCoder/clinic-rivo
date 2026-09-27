<?php

namespace App\Models;

use App\Enums\PlanningShiftKind;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_id', 'department_id', 'kind', 'title', 'starts_at', 'ends_at', 'observation'])]
class PlanningShift extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'kind' => PlanningShiftKind::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(HrReferenceValue::class, 'department_id');
    }

    protected function auditModule(): ?string
    {
        return 'administration';
    }
}
