<?php

namespace App\Actions\Administration;

use App\Models\DocumentTemplate;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

class DuplicateDocumentTemplateAction
{
    public function execute(DocumentTemplate $template, CatalogActor $actor): DocumentTemplate
    {
        if ($actor->cannot('document_templates.duplicate')) {
            throw new AuthorizationException('Vous ne pouvez pas dupliquer ce canevas.');
        }

        return DocumentTemplate::query()->create([
            'document_type' => $template->document_type,
            'data_context' => $template->data_context,
            'name' => $template->name.' (copie)',
            'description' => $template->description,
            'content' => $template->content,
            'content_html' => $template->content_html,
            'variables_used' => $template->variables_used,
            // A new, independent canevas going forward — not another
            // version of the source's own history.
            'lineage_id' => (string) Str::uuid(),
            // Inactive by default: a duplicate must be reviewed/renamed by
            // the Super Admin before it can be picked up by RH.
            'active' => false,
            'created_by' => $actor->localUserId(),
            'updated_by' => $actor->localUserId(),
            ...$actor->externalAttribution('created'),
            ...$actor->externalAttribution('updated'),
        ]);
    }
}
