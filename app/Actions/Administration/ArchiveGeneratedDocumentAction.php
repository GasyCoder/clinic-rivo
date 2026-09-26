<?php

namespace App\Actions\Administration;

use App\Models\GeneratedDocument;
use App\Models\User;
use App\Support\Authorization\RemoteActorAttribution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * ADR-199 — « supprimer » un document généré l'archive, avec un motif : il reste
 * consultable dans son dossier (onglet Archivés) et restaurable. Jamais effacé.
 */
class ArchiveGeneratedDocumentAction
{
    public function execute(GeneratedDocument $document, string $reason, User $actor): void
    {
        Gate::forUser($actor)->authorize('delete', $document);

        if ($document->trashed()) {
            throw ValidationException::withMessages(['reason' => 'Ce document est déjà archivé.']);
        }

        DB::transaction(function () use ($document, $reason, $actor): void {
            // Le Super Admin du portail n'a pas de compte local : son nom est gardé à côté.
            $document->forceFill(RemoteActorAttribution::fields('deleted', $actor))->saveQuietly();
            $document->delete_reason = $reason;
            $document->delete();
        });
    }
}
