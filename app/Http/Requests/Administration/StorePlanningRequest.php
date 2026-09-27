<?php

namespace App\Http\Requests\Administration;

use App\Models\PlanningShift;

class StorePlanningRequest extends PlanningDataRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', PlanningShift::class) ?? false;
    }
}
