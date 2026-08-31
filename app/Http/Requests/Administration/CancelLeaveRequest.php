<?php

namespace App\Http\Requests\Administration;

use App\Models\LeaveRequest;
use Illuminate\Foundation\Http\FormRequest;

class CancelLeaveRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => str($this->input('reason'))->squish()->toString()]);
        }
    }

    public function authorize(): bool
    {
        $leave = $this->route('leave');

        return $leave instanceof LeaveRequest
            && ($this->user()?->can('cancel', $leave) ?? false);
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:1000']];
    }
}
