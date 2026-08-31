<?php

namespace App\Policies;

use App\Models\HrDocument;
use App\Models\User;

class HrDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('hr_documents.view');
    }

    public function view(User $user, HrDocument $document): bool
    {
        return $user->can('hr_documents.view');
    }

    public function create(User $user): bool
    {
        return $user->can('hr_documents.create');
    }

    public function delete(User $user, HrDocument $document): bool
    {
        return $user->can('hr_documents.archive') && ! $document->trashed();
    }

    public function restore(User $user, HrDocument $document): bool
    {
        return $user->can('hr_documents.restore') && $document->trashed();
    }

    public function forceDelete(User $user, HrDocument $document): bool
    {
        return false;
    }
}
