<?php

namespace App\Actions\Medicine;

use App\Models\ImagingRequestItem;
use App\Models\ImagingResultRevision;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Medicine\ClinicalRichTextSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corriger un compte rendu d'imagerie déjà enregistré (ADR-130).
 *
 * `RecordImagingResultAction` refuse un second compte rendu : c'est la
 * première saisie, et elle reste la seule. Corriger est un autre acte, avec sa
 * propre autorité (`imaging_results.update`) : il **conserve la version qu'il
 * remplace** — texte, auteur, date — puis écrit la nouvelle. Rien n'est jamais
 * perdu (ADR-010) : l'historique se relit, et l'audit garde l'ancienne comme la
 * nouvelle valeur.
 *
 * `resulted_at/by` gardent la signature d'origine : c'est la date du compte
 * rendu. La correction se lit sur `corrected_at/by`.
 */
class CorrectImagingResultAction
{
    public function __construct(
        private readonly ClinicalRichTextSanitizer $richText,
        private readonly Auditor $auditor,
    ) {}

    /** @param array{title: string|null}|null $sheet  la feuille utilisée ; `null` : le titre enregistré ne change pas */
    public function execute(ImagingRequestItem $item, string $resultValue, ?string $resultNotes, ?string $reason, User $actor, ?array $sheet = null): ImagingRequestItem
    {
        return DB::transaction(function () use ($item, $resultValue, $resultNotes, $reason, $actor, $sheet): ImagingRequestItem {
            $locked = ImagingRequestItem::query()->with('imagingRequest')->lockForUpdate()->findOrFail($item->getKey());

            if ($locked->resulted_at === null) {
                throw ValidationException::withMessages([
                    'result_value' => 'Aucun compte rendu n’est encore enregistré pour cet examen : saisissez-le d’abord.',
                ]);
            }

            $report = $this->richText->sanitize($resultValue);
            $notes = $this->richText->sanitize((string) $resultNotes);
            $notes = $notes !== '' ? $notes : null;

            if ($this->richText->isBlank($report)) {
                throw ValidationException::withMessages(['result_value' => 'Le compte rendu ne peut pas être vide.']);
            }

            if ($report === (string) $locked->result_value && $notes === $locked->result_notes && ($sheet === null || $sheet['title'] === $locked->report_sheet_title)) {
                throw ValidationException::withMessages(['result_value' => 'Rien n’a changé : aucune correction à enregistrer.']);
            }

            $before = ['result_value' => $locked->result_value, 'result_notes' => $locked->result_notes, 'report_sheet_title' => $locked->report_sheet_title];

            // La version remplacée, avant tout : si l'écriture échoue, la
            // transaction défait aussi cette ligne.
            ImagingResultRevision::query()->create([
                'imaging_request_item_id' => $locked->getKey(),
                'revision' => $locked->revisions()->count() + 1,
                'result_value' => $locked->result_value,
                'result_notes' => $locked->result_notes,
                // La date et l'auteur de *cette* version : la signature
                // d'origine la première fois, celle de la correction précédente
                // ensuite.
                'resulted_at' => $locked->corrected_at ?? $locked->resulted_at,
                'resulted_by' => $locked->corrected_by ?? $locked->resulted_by,
                'superseded_at' => now(),
                'superseded_by' => $actor->getKey(),
                'reason' => filled($reason) ? trim($reason) : null,
            ]);

            $locked->update([
                'result_value' => $report,
                'result_notes' => $notes,
                'corrected_at' => now(),
                'corrected_by' => $actor->getKey(),
            ] + ($sheet !== null ? ['report_sheet_title' => $sheet['title']] : []));

            $this->auditor->record(
                'imaging.result.correct',
                entity: $locked,
                oldValues: $before,
                newValues: ['result_value' => $report, 'result_notes' => $notes, 'report_sheet_title' => $locked->fresh()->report_sheet_title, 'reason' => filled($reason) ? trim($reason) : null],
            );

            return $locked->fresh();
        });
    }
}
