<?php

namespace App\Actions\Administration;

use App\Models\GeneratedDocument;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * ADR-199 — restaurer un document archivé. Une version remplacée par une plus
 * récente encore en vigueur ne revient pas : il faudrait d'abord archiver la
 * nouvelle, sinon deux versions du même document seraient actives.
 */
class RestoreGeneratedDocumentAction
{
    public function execute(GeneratedDocument $document, User $actor): GeneratedDocument
    {
        Gate::forUser($actor)->authorize('restore', $document);

        $newer = GeneratedDocument::query()->where('replaces_document_id', $document->getKey())->first();
        if ($newer) {
            throw ValidationException::withMessages([
                'document' => 'Une version plus récente de ce document est en vigueur : archivez-la d’abord pour revenir à celle-ci.',
            ]);
        }

        $document->forceFill(['external_deleted_by_uuid' => null, 'external_deleted_by_name' => null])->saveQuietly();
        $document->restore();

        return $document;
    }
}
