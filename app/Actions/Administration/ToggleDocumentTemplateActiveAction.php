<?php

namespace App\Actions\Administration;

use App\Models\DocumentTemplate;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;

class ToggleDocumentTemplateActiveAction
{
    public function execute(DocumentTemplate $template, bool $active, CatalogActor $actor): DocumentTemplate
    {
        if ($actor->cannot('document_templates.update')) {
            throw new AuthorizationException('Vous ne pouvez pas modifier le statut de ce canevas.');
        }

        $template->fill([
            'active' => $active,
            'updated_by' => $actor->localUserId(),
            ...$actor->externalAttribution('updated'),
        ])->save();

        return $template->fresh();
    }
}
