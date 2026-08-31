<?php

namespace App\Actions\Administration;

use App\Models\HrDocument;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class RestoreHrDocumentAction
{
    public function execute(HrDocument $document, User $actor): HrDocument
    {
        Gate::forUser($actor)->authorize('restore', $document);
        $document->restore();

        return $document->refresh();
    }
}
