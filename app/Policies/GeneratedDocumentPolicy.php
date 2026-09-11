<?php

namespace App\Policies;

use App\Models\GeneratedDocument;
use App\Models\User;

class GeneratedDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('generated_documents.view');
    }

    public function view(User $user, GeneratedDocument $document): bool
    {
        return $user->can('generated_documents.view');
    }

    public function create(User $user): bool
    {
        return $user->can('generated_documents.create');
    }

    public function print(User $user, GeneratedDocument $document): bool
    {
        return $user->can('generated_documents.print');
    }
}
