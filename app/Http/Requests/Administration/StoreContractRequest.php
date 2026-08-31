<?php

namespace App\Http\Requests\Administration;

use App\Models\EmploymentContract;

class StoreContractRequest extends ContractDataRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', EmploymentContract::class) ?? false;
    }

    public function rules(): array
    {
        return $this->contractRules();
    }
}
