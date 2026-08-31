<?php

namespace App\Http\Requests\Administration;

use App\Models\PlanningShift;

class UpdatePlanningRequest extends PlanningDataRequest
{
    public function authorize(): bool
    {
        $shift = $this->route('planning');

        return $shift instanceof PlanningShift
            && ($this->user()?->can('update', $shift) ?? false);
    }
}
