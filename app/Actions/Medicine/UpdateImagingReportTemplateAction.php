<?php

namespace App\Actions\Medicine;

use App\Models\ImagingReportTemplate;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Medicine\ClinicalRichTextSanitizer;
use App\Services\Medicine\ImagingReportTemplateCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-108 — renomme une feuille ajoutée par les médecins du site, et
 * éventuellement remplace son contenu.
 *
 * Les comptes rendus déjà écrits ne bougent pas : ils portent leur propre texte
 * et l'instantané du titre. Seules les prochaines saisies partent de la feuille
 * corrigée. L'audit garde l'ancienne et la nouvelle valeur.
 */
class UpdateImagingReportTemplateAction
{
    public function __construct(
        private readonly ClinicalRichTextSanitizer $richText,
        private readonly ImagingReportTemplateCatalog $catalog,
        private readonly Auditor $auditor,
    ) {}

    /** @param string|null $bodyHtml  `null` : le contenu de la feuille ne change pas */
    public function execute(ImagingReportTemplate $template, string $name, ?string $description, ?string $bodyHtml, User $actor): ImagingReportTemplate
    {
        if ($actor->cannot('imaging_templates.update')) {
            throw new AuthorizationException('Vous ne pouvez pas modifier de feuille de compte rendu.');
        }

        $name = trim($name);
        $body = $bodyHtml === null ? null : $this->richText->sanitize($bodyHtml);

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Donnez un nom à la feuille.']);
        }

        if ($body !== null && $this->richText->isBlank($body)) {
            throw ValidationException::withMessages(['body_html' => 'La feuille est vide : écrivez d’abord ses rubriques.']);
        }

        return DB::transaction(function () use ($template, $name, $description, $body, $actor): ImagingReportTemplate {
            $locked = ImagingReportTemplate::query()->lockForUpdate()->findOrFail($template->getKey());

            if ($this->catalog->nameIsTaken($name, $locked)) {
                throw ValidationException::withMessages(['name' => 'Une feuille porte déjà ce nom.']);
            }

            $description = filled($description) ? trim($description) : null;

            $before = ['name' => $locked->name, 'description' => $locked->description, 'body_html' => $locked->body_html];

            $locked->fill([
                'name' => $name,
                'description' => $description,
                'body_html' => $body ?? $locked->body_html,
                'updated_by' => $actor->getKey(),
            ]);

            if (! $locked->isDirty(['name', 'description', 'body_html'])) {
                throw ValidationException::withMessages(['name' => 'Rien n’a changé : aucune modification à enregistrer.']);
            }

            $locked->save();

            $this->auditor->record(
                'imaging.template.update',
                entity: $locked,
                oldValues: $before,
                newValues: ['name' => $locked->name, 'description' => $locked->description, 'body_html' => $locked->body_html],
                module: 'medicine',
                actor: $actor,
            );

            return $locked;
        });
    }
}
