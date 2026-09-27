<?php

namespace App\Actions\Maternity;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Models\CatalogItem;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\MaternityProcedure;
use App\Models\MaternityRecord;
use App\Models\User;
use App\Services\Billing\ClinicalActBiller;
use App\Services\Billing\PlannedServiceBilling;
use App\Support\MaternityActProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Un acte réalisé alimente le compte du patient (ADR-141), comme un acte Soins
 * (ADR-054) : le tarif est résolu côté serveur, jamais saisi, et une erreur
 * financière — tarif absent, contexte du passage non résolu — n'empêche
 * **jamais** l'acte d'être enregistré. La sage-femme ne voit aucun montant ;
 * seule la Réception/Caisse encaisse (ADR-012).
 */
class RecordMaternityProcedureAction
{
    public function __construct(
        private readonly ClinicalActBiller $biller,
        private readonly PlannedServiceBilling $plannedBilling,
    ) {}

    /** @param array{catalog_item_uuid: string, quantity: mixed, notes?: ?string} $data */
    public function execute(EpisodeOrientation $orientation, array $data, User $actor): MaternityProcedure
    {
        return DB::transaction(function () use ($orientation, $data, $actor): MaternityProcedure {
            $locked = EpisodeOrientation::query()->with('episode.maternityRecord')->lockForUpdate()->findOrFail($orientation->id);
            if ($locked->destination_module !== CatalogModule::Maternity || $locked->status !== EpisodeOrientationStatus::InProgress) {
                throw ValidationException::withMessages(['procedure' => 'La prise en charge Maternité n’est pas active.']);
            }

            $item = CatalogItem::query()
                ->where('uuid', $data['catalog_item_uuid'])
                ->where('type', CatalogItemType::Service->value)
                ->where('module', CatalogModule::Maternity->value)
                ->firstOrFail();

            if (in_array($item->code, MaternityActProfile::CESAREAN_CODES, true)) {
                throw ValidationException::withMessages([
                    'catalog_item_uuid' => 'Une césarienne doit être transmise au workflow Chirurgie et ne peut pas être enregistrée comme un acte Maternité.',
                ]);
            }

            // « Autres » est un acte à préciser : sans précision, il ne dirait
            // rien à la sage-femme suivante ni à la facturation (ADR-136).
            if ($item->code === MaternityActProfile::OTHER_CODE && blank($data['notes'] ?? null)) {
                throw ValidationException::withMessages([
                    'notes' => 'Précisez l’acte « Autres » : il ne se comprend pas sans sa description.',
                ]);
            }

            $record = $locked->episode->maternityRecord ?? MaternityRecord::query()->create([
                'episode_id' => $locked->episode_id,
                'episode_orientation_id' => $locked->id,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $procedure = $record->procedures()->create([
                'catalog_item_id' => $item->id,
                'catalog_item_uuid' => $item->uuid,
                'procedure_code' => $item->code,
                'procedure_name' => $item->name,
                'quantity' => $data['quantity'],
                'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
                'performed_by' => $actor->id,
                // Instantané : la règle de correction lit le rôle d'alors, pas celui d'aujourd'hui.
                'performed_by_role' => $actor->role?->code,
                'performed_at' => now(),
            ]);

            $this->bill($procedure, $locked->episode, $item, $actor);

            return $procedure->fresh(['billableItem']);
        });
    }

    /**
     * Deux origines possibles, et la différence compte pour la suite :
     *
     * ```text
     * PLANNED  l'acte figurait déjà au plan de la Réception et y était facturé :
     *          on s'y rattache, jamais une seconde facturation (ADR-109)
     * OWN      sinon, la Maternité le porte au compte elle-même
     * ```
     *
     * Un acte redemandé après un premier acte déjà rattaché est un second acte :
     * `unconsumedFor()` ne rend alors rien et il se facture.
     */
    private function bill(MaternityProcedure $procedure, Episode $episode, CatalogItem $item, User $actor): void
    {
        $planned = $this->plannedBilling->unconsumedFor($episode, $item);

        if ($planned) {
            $procedure->update(['billable_item_id' => $planned->getKey(), 'billing_origin' => 'PLANNED']);

            return;
        }

        $billable = $this->biller->bill(
            $episode,
            $item,
            'maternity_procedure:'.$procedure->uuid,
            $actor,
            $procedure,
            $procedure->quantity,
        );

        if ($billable) {
            $procedure->update(['billable_item_id' => $billable->getKey(), 'billing_origin' => 'OWN']);
        }
    }
}
