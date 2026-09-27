<?php

namespace App\Actions\Medicine;

use App\Models\ImagingReportTemplate;
use App\Models\ImagingRequestItem;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Medicine\ClinicalRichTextSanitizer;
use App\Services\Medicine\ImagingReportTemplateCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-108 — enregistre le contenu d'un compte rendu comme nouvelle feuille.
 *
 * Le texte devient le modèle **tel quel** : c'est au médecin d'avoir effacé ce
 * qui est propre au patient avant d'enregistrer — l'écran le lui dit. Le
 * serveur ne peut pas le deviner, mais il refuse une feuille vide et assainit
 * le corps comme tout texte clinique.
 */
class CreateImagingReportTemplateAction
{
    public function __construct(
        private readonly ClinicalRichTextSanitizer $richText,
        private readonly ImagingReportTemplateCatalog $catalog,
        private readonly Auditor $auditor,
        private readonly SetImagingExamDefaultTemplateAction $setDefault,
    ) {}

    public function execute(string $name, ?string $description, string $bodyHtml, User $actor, ?ImagingRequestItem $defaultFor = null): ImagingReportTemplate
    {
        if ($actor->cannot('imaging_templates.create')) {
            throw new AuthorizationException('Vous ne pouvez pas créer de feuille de compte rendu.');
        }

        $name = trim($name);
        $body = $this->richText->sanitize($bodyHtml);

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Donnez un nom à la feuille.']);
        }

        if ($this->richText->isBlank($body)) {
            throw ValidationException::withMessages(['body_html' => 'La feuille est vide : écrivez d’abord ses rubriques.']);
        }

        return DB::transaction(function () use ($name, $description, $body, $actor, $defaultFor): ImagingReportTemplate {
            if ($this->catalog->nameIsTaken($name)) {
                throw ValidationException::withMessages(['name' => 'Une feuille porte déjà ce nom.']);
            }

            $template = ImagingReportTemplate::query()->create([
                'name' => $name,
                'description' => filled($description) ? trim($description) : null,
                'body_html' => $body,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->auditor->record(
                'imaging.template.create',
                entity: $template,
                newValues: ['name' => $template->name, 'description' => $template->description],
                module: 'medicine',
                actor: $actor,
            );

            // Proposée d'office pour l'examen d'où le médecin l'a écrite : dans
            // la même transaction, pour ne jamais laisser une feuille créée
            // sans le réglage demandé.
            if ($defaultFor !== null) {
                $this->setDefault->execute($defaultFor, ImagingReportTemplateCatalog::CUSTOM_KEY_PREFIX.$template->uuid, $actor);
            }

            return $template;
        });
    }
}
