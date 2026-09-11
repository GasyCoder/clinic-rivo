<?php

namespace App\Actions\Administration;

use App\Models\DocumentTemplate;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Revert to this version" never resurrects the old (trashed) row in
 * place — that would let a later edit of the SAME row retroactively change
 * what a still-existing GeneratedDocument's snapshot points back to. It
 * instead creates a brand-new version copying that historical content,
 * exactly like SaveEmploymentContractTemplateAction's own versioning
 * discipline, and archives whatever was current — which itself remains a
 * fully readable entry in the same lineage's history afterward.
 */
class RevertDocumentTemplateVersionAction
{
    public function execute(DocumentTemplate $historicalVersion, string $reason, CatalogActor $actor): DocumentTemplate
    {
        if ($actor->cannot('document_templates.update')) {
            throw new AuthorizationException('Cette action distante n’est pas autorisée.');
        }

        if (! $historicalVersion->trashed()) {
            throw ValidationException::withMessages([
                'version' => 'Cette version est déjà la version active.',
            ]);
        }

        return DB::transaction(function () use ($historicalVersion, $reason, $actor): DocumentTemplate {
            $current = DocumentTemplate::query()
                ->where('lineage_id', $historicalVersion->lineage_id)
                ->lockForUpdate()
                ->first();

            $replacement = DocumentTemplate::query()->create([
                'lineage_id' => $historicalVersion->lineage_id,
                'document_type' => $historicalVersion->document_type,
                'data_context' => $historicalVersion->data_context,
                'name' => $historicalVersion->name,
                'description' => $historicalVersion->description,
                'content' => $historicalVersion->content,
                'content_html' => $historicalVersion->content_html,
                'variables_used' => $historicalVersion->variables_used,
                'active' => $historicalVersion->active,
                'created_by' => $actor->localUserId(),
                'updated_by' => $actor->localUserId(),
                ...$actor->externalAttribution('created'),
                ...$actor->externalAttribution('updated'),
            ]);

            if ($current) {
                $current->delete_reason = trim($reason)
                    ?: 'Retour à la version du '.$historicalVersion->created_at->translatedFormat('d/m/Y H:i').'.';
                $current->delete();
            }

            return $replacement;
        });
    }
}
