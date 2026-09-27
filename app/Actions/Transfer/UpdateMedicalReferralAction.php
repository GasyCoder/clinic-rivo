<?php

namespace App\Actions\Transfer;

use App\Enums\ClinicalPriority;
use App\Enums\MedicalRequestStatus;
use App\Models\MedicalReferral;
use App\Services\Medicine\ClinicalRichTextSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-114 — compléter une demande de transfert dans le module Transferts.
 *
 * La demande part en un clic depuis la consultation ; l'établissement, le
 * motif et le résumé se complètent ici. Corrigée, jamais supprimée :
 * `Auditable` garde l'ancienne et la nouvelle valeur. Un transfert effectué
 * ou annulé ne se modifie plus.
 */
class UpdateMedicalReferralAction
{
    public function __construct(private readonly ClinicalRichTextSanitizer $richText) {}

    /** @param array<string, mixed> $data */
    public function execute(MedicalReferral $referral, array $data): void
    {
        DB::transaction(function () use ($referral, $data): void {
            $locked = MedicalReferral::query()->lockForUpdate()->findOrFail($referral->getKey());

            if ($locked->status !== MedicalRequestStatus::Requested || $locked->hasDeparted()) {
                throw ValidationException::withMessages([
                    'referral' => 'Ce transfert est terminé : sa demande ne se modifie plus.',
                ]);
            }

            $clean = static fn (mixed $value): ?string => trim((string) $value) ?: null;
            // Un éditeur vidé renvoie « <p><br></p> » : sans texte, le champ
            // reste vide plutôt que de conserver des balises seules.
            $rich = fn (mixed $value): ?string => $this->richText->plainText((string) $value) === ''
                ? null
                : $this->richText->sanitize((string) $value);

            $locked->update([
                'facility' => $clean($data['facility'] ?? null),
                'reason' => $rich($data['reason'] ?? null),
                'diagnosis' => $rich($data['diagnosis'] ?? null),
                'clinical_summary' => $rich($data['clinical_summary'] ?? null),
                'treatments_given' => $rich($data['treatments_given'] ?? null),
                'recommendations' => $rich($data['recommendations'] ?? null),
                'notes' => $rich($data['notes'] ?? null),
                'priority' => ClinicalPriority::from($data['priority']),
            ]);
        });
    }
}
