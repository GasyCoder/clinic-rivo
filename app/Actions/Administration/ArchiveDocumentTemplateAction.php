<?php

namespace App\Actions\Administration;

use App\Models\DocumentTemplate;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;

class ArchiveDocumentTemplateAction
{
    public function execute(DocumentTemplate $template, string $reason, CatalogActor $actor): void
    {
        if ($actor->cannot('document_templates.archive')) {
            throw new AuthorizationException('Vous ne pouvez pas archiver ce canevas.');
        }

        $template->delete_reason = trim($reason);
        $template->delete();
    }
}
