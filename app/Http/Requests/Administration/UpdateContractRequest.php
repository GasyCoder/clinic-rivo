<?php

namespace App\Http\Requests\Administration;

use App\Models\EmploymentContract;

class UpdateContractRequest extends ContractDataRequest
{
    public function authorize(): bool
    {
        $contract = $this->route('contract');

        return $contract instanceof EmploymentContract
            && ($this->user()?->can('update', $contract) ?? false);
    }

    public function rules(): array
    {
        $contract = $this->route('contract');

        return $this->contractRules($contract instanceof EmploymentContract ? $contract : null);
    }
}
