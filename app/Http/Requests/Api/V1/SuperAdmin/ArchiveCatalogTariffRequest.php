<?php

namespace App\Http\Requests\Api\V1\SuperAdmin;

use App\Http\Requests\Administration\ArchiveCatalogTariffRequest as AdministrationRequest;

class ArchiveCatalogTariffRequest extends AdministrationRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
