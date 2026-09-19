<?php

namespace App\Actions\Medicine;

use App\Models\ImagingExamReportTemplate;
use App\Models\ImagingRequestItem;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Medicine\ImagingReportTemplateCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-108 — règle la feuille proposée d'office pour un examen d'imagerie.
 *
 * `null` veut dire « aucune feuille d'office » : un choix explicite, qui
 * l'emporte sur la liste par défaut du code. Le réglage est propre au site et
 * ne touche pas le catalogue (ADR-024) : il porte sur l'examen désigné par une
 * de ses lignes de demande, jamais sur un libellé.
 */
class SetImagingExamDefaultTemplateAction
{
    public function __construct(
        private readonly ImagingReportTemplateCatalog $catalog,
        private readonly Auditor $auditor,
    ) {}

    public function execute(ImagingRequestItem $item, ?string $templateKey, User $actor): void
    {
        if ($actor->cannot('imaging_templates.create')) {
            throw new AuthorizationException('Vous ne pouvez pas régler les feuilles de compte rendu.');
        }

        if ($templateKey !== null && ! $this->catalog->keyExists($templateKey)) {
            throw ValidationException::withMessages(['template_key' => 'Cette feuille n’existe plus.']);
        }

        DB::transaction(function () use ($item, $templateKey, $actor): void {
            $setting = ImagingExamReportTemplate::query()
                ->where('catalog_item_id', $item->catalog_item_id)
                ->lockForUpdate()
                ->first();

            $before = ['template_key' => $setting?->template_key];

            ImagingExamReportTemplate::query()->updateOrCreate(
                ['catalog_item_id' => $item->catalog_item_id],
                ['template_key' => $templateKey, 'updated_by' => $actor->getKey()]
                    + ($setting === null ? ['created_by' => $actor->getKey()] : []),
            );

            $this->auditor->record(
                'imaging.exam_template.set',
                entity: $item->catalogItem ?? $item,
                oldValues: $before,
                newValues: ['template_key' => $templateKey, 'exam' => $item->catalog_item_name_snapshot],
                module: 'medicine',
                actor: $actor,
            );
        });
    }
}
