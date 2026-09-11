<?php

namespace App\Http\Requests\Api\V1\SuperAdmin;

use App\Http\Requests\Administration\DocumentTemplateDataRequest as AdministrationRequest;

class DocumentTemplateDataRequest extends AdministrationRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
