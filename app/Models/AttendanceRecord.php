<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_id', 'work_date', 'started_at', 'ended_at', 'observation'])]
class AttendanceRecord extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    protected function auditModule(): ?string
    {
        return 'administration';
    }
}
