<?php

namespace App\Actions\Administration;

use App\Models\HrDocument;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class ArchiveHrDocumentAction
{
    public function execute(HrDocument $document, string $reason, User $actor): void
    {
        Gate::forUser($actor)->authorize('delete', $document);
        $document->delete_reason = $reason;
        $document->delete();
    }
}
