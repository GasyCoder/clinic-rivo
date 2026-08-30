<?php

namespace App\Http\Requests\Api\V1\SuperAdmin;

use App\Http\Requests\Administration\SetCatalogTariffRequest as AdministrationRequest;

class SetCatalogTariffRequest extends AdministrationRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
