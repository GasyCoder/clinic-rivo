<?php

namespace App\Http\Requests\Api\V1\SuperAdmin;

use App\Http\Requests\Administration\StoreCatalogItemRequest as AdministrationRequest;

class StoreCatalogItemRequest extends AdministrationRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
