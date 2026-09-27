<?php

namespace App\Http\Requests\Administration;

use App\Models\LeaveRequest;
use Illuminate\Foundation\Http\FormRequest;

class LeaveDecisionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $value = str($this->input('reason'))->squish()->toString();
            $this->merge(['reason' => $value === '' ? null : $value]);
        }
    }

    public function authorize(): bool
    {
        $leave = $this->route('leave');
        $ability = $this->routeIs('administration.leave.approve') ? 'approve' : 'reject';

        return $leave instanceof LeaveRequest
            && ($this->user()?->can($ability, $leave) ?? false);
    }

    public function rules(): array
    {
        return [
            'reason' => [
                $this->routeIs('administration.leave.reject') ? 'required' : 'nullable',
                'string',
                'max:1000',
            ],
        ];
    }
}
