<?php

namespace App\Actions\Medicine;

use App\Models\ImagingRequestItem;
use App\Models\User;
use App\Services\Medicine\ClinicalRichTextSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Le compte rendu est du texte mis en forme, jamais du HTML arbitraire.
 *
 * La `FormRequest` l'assainit déjà ; on le refait ici parce qu'une Action est
 * atteignable autrement que par elle — la même raison qui fait vérifier deux
 * fois les constatations d'un appareil « Anormal » (ADR-077).
 */
class RecordImagingResultAction
{
    public function __construct(private readonly ClinicalRichTextSanitizer $richText) {}

    /** @param array{title: string|null}|null $sheet  la feuille utilisée (instantané du titre), `null` : aucune information */
    public function execute(ImagingRequestItem $item, string $resultValue, ?string $resultNotes, User $actor, ?array $sheet = null): ImagingRequestItem
    {
        return DB::transaction(function () use ($item, $resultValue, $resultNotes, $actor, $sheet): ImagingRequestItem {
            $locked = ImagingRequestItem::query()->lockForUpdate()->findOrFail($item->getKey());

            if ($locked->resulted_at !== null) {
                throw ValidationException::withMessages([
                    'result_value' => 'Un compte rendu a déjà été enregistré pour cet examen.',
                ]);
            }

            $report = $this->richText->sanitize($resultValue);
            $notes = $this->richText->sanitize((string) $resultNotes);

            if ($this->richText->isBlank($report)) {
                throw ValidationException::withMessages([
                    'result_value' => 'Saisissez le compte rendu.',
                ]);
            }

            $locked->update([
                'result_value' => $report,
                'result_notes' => $notes !== '' ? $notes : null,
                'report_sheet_title' => $sheet['title'] ?? null,
                'resulted_at' => now(),
                'resulted_by' => $actor->getKey(),
            ]);

            return $locked->fresh();
        });
    }
}
