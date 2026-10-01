<?php

namespace App\Http\Requests\Api\V1\SuperAdmin;

use App\Http\Requests\Administration\SyncCareActConsumablesRequest as AdministrationRequest;

class SyncCareActConsumablesRequest extends AdministrationRequest
{
    public function authorize(): bool
    {
        // L'action revérifie `catalog.items.update` sur l'acteur distant transmis
        // par le portail : l'API n'a pas d'utilisateur local à interroger ici.
        return true;
    }
}
