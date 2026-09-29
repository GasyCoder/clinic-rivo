<?php

namespace App\Actions\Laboratory;

use App\Enums\LabItemStatus;
use App\Models\LabRequestItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-214 — confier une analyse à un laboratoire extérieur (CDC phase 3 :
 * analyses internes / externes), puis revenir sur cet envoi tant que rien n'est
 * saisi.
 *
 * Le résultat reçu se transcrit ensuite à la paillasse comme un autre, marqué
 * « réalisée par <laboratoire> », et le biologiste le valide comme les autres :
 * il vérifie la transcription (arbitrage du propriétaire). Rien de financier
 * n'est décidé ici : ce que la clinique doit au laboratoire extérieur n'est pas
 * défini par le CDC.
 */
class SendOutLabItemAction
{
    /** @param  array{laboratory: string, reference?: ?string, notes?: ?string}  $data */
    public function execute(LabRequestItem $item, array $data, User $actor): LabRequestItem
    {
        $this->authorize($actor);

        $laboratory = trim((string) ($data['laboratory'] ?? ''));
        if (mb_strlen($laboratory) < 2) {
            throw ValidationException::withMessages(['laboratory' => 'Indiquez le laboratoire qui réalise l’analyse.']);
        }

        return DB::transaction(function () use ($item, $data, $laboratory, $actor): LabRequestItem {
            $locked = LabItemGuard::lockEditable($item);
            LabItemGuard::ensureTakenUp($locked->labRequest, $actor);

            if ($locked->sent_out_at !== null) {
                throw ValidationException::withMessages(['laboratory' => "Cette analyse est déjà confiée à {$locked->external_lab_name}."]);
            }

            $locked->update([
                'external_lab_name' => mb_substr($laboratory, 0, 150),
                'external_reference' => filled($data['reference'] ?? null) ? mb_substr(trim((string) $data['reference']), 0, 80) : null,
                'sent_out_notes' => filled($data['notes'] ?? null) ? mb_substr(trim((string) $data['notes']), 0, 1000) : null,
                'sent_out_at' => now(),
                'sent_out_by' => $actor->getKey(),
            ]);

            return $locked->fresh();
        });
    }

    /** Revenir à une analyse faite ici : seulement tant qu'aucun résultat n'est saisi. */
    public function cancel(LabRequestItem $item, User $actor): LabRequestItem
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($item): LabRequestItem {
            $locked = LabItemGuard::lockEditable($item);

            if ($locked->sent_out_at === null) {
                throw ValidationException::withMessages(['laboratory' => 'Cette analyse n’est pas confiée à un laboratoire extérieur.']);
            }
            if ($locked->currentStatus() !== LabItemStatus::Pending || $locked->results()->exists()) {
                throw ValidationException::withMessages(['laboratory' => 'Un résultat est déjà saisi : l’envoi ne se retire plus. Terminez l’analyse avec le résultat reçu.']);
            }

            $locked->update([
                'external_lab_name' => null,
                'external_reference' => null,
                'sent_out_notes' => null,
                'sent_out_at' => null,
                'sent_out_by' => null,
            ]);

            return $locked->fresh();
        });
    }

    private function authorize(User $actor): void
    {
        if ($actor->cannot('laboratory_orders.send_out')) {
            throw new AuthorizationException('Confier une analyse à un laboratoire extérieur demande le droit « laboratory_orders.send_out ».');
        }
    }
}
