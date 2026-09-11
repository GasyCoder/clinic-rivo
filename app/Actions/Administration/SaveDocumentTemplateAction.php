<?php

namespace App\Actions\Administration;

use App\Enums\DocumentDataContext;
use App\Models\DocumentTemplate;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SaveDocumentTemplateAction
{
    /** @param array<string, mixed> $data */
    public function execute(array $data, CatalogActor $actor, ?DocumentTemplate $template = null): DocumentTemplate
    {
        if ($actor->cannot($template ? 'document_templates.update' : 'document_templates.create')) {
            throw new AuthorizationException('Cette action distante n’est pas autorisée.');
        }

        $values = [
            'document_type' => mb_strtoupper(trim((string) $data['document_type'])),
            'data_context' => DocumentDataContext::from($data['data_context']),
            'name' => trim((string) $data['name']),
            'description' => filled($data['description'] ?? null) ? trim((string) $data['description']) : null,
            'content' => $data['content'],
            'content_html' => (string) $data['content_html'],
            'variables_used' => $this->extractPlaceholders((string) $data['content_html']),
            'active' => (bool) ($data['active'] ?? true),
        ];

        return DB::transaction(function () use ($template, $values, $actor): DocumentTemplate {
            $isLinked = $template?->generatedDocuments()->exists() ?? false;

            if ($template && $isLinked) {
                // Already used for at least one generated document: version
                // instead of mutating in place, so a previously generated
                // document keeps resolving the exact canevas that produced
                // it (same discipline as SaveEmploymentContractTemplateAction,
                // ADR-069). The replacement joins the same lineage so the
                // history screen can list every version together.
                $replacement = DocumentTemplate::query()->create([
                    ...$values,
                    'lineage_id' => $template->lineage_id,
                    'created_by' => $actor->localUserId(),
                    'updated_by' => $actor->localUserId(),
                    ...$actor->externalAttribution('created'),
                    ...$actor->externalAttribution('updated'),
                ]);
                $template->delete_reason = 'Version remplacée par le canevas '.$replacement->uuid.'.';
                $template->delete();

                return $replacement;
            }

            if ($template) {
                $template->fill([
                    ...$values,
                    'updated_by' => $actor->localUserId(),
                    ...$actor->externalAttribution('updated'),
                ])->save();

                return $template->refresh();
            }

            return DocumentTemplate::query()->create([
                ...$values,
                'lineage_id' => (string) Str::uuid(),
                'created_by' => $actor->localUserId(),
                'updated_by' => $actor->localUserId(),
                ...$actor->externalAttribution('created'),
                ...$actor->externalAttribution('updated'),
            ]);
        });
    }

    /** @return array<int, string> */
    private function extractPlaceholders(string $html): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/u', $html, $matches);

        return collect($matches[1])->unique()->sort()->values()->all();
    }
}
