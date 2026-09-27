<?php

namespace App\Actions\Administration;

use App\Models\DocumentTemplate;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;

class RestoreDocumentTemplateAction
{
    public function execute(DocumentTemplate $template, CatalogActor $actor): DocumentTemplate
    {
        if ($actor->cannot('document_templates.restore')) {
            throw new AuthorizationException('Vous ne pouvez pas restaurer ce canevas.');
        }

        $template->fill([
            'updated_by' => $actor->localUserId(),
            ...$actor->externalAttribution('updated'),
        ]);
        $template->restore();

        return $template->fresh();
    }
}
