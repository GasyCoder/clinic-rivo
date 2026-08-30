<?php

namespace App\Http\Requests\Api\V1\SuperAdmin;

use App\Http\Requests\Administration\UpdateCatalogItemRequest as AdministrationRequest;

class UpdateCatalogItemRequest extends AdministrationRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
