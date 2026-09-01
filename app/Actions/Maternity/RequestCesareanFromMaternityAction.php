<?php

namespace App\Actions\Maternity;

use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Actions\Surgery\CreateSurgicalRequestAction;
use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Models\CatalogItem;
use App\Models\EpisodeOrientation;
use App\Models\SurgicalRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequestCesareanFromMaternityAction
{
    public function __construct(
        private readonly CreateEpisodeOrientationAction $createOrientation,
        private readonly CreateSurgicalRequestAction $createSurgicalRequest,
    ) {}

    public function execute(EpisodeOrientation $orientation, string $type, string $indication, User $actor): SurgicalRequest
    {
        return DB::transaction(function () use ($orientation, $type, $indication, $actor): SurgicalRequest {
            $locked = EpisodeOrientation::query()->with('episode')->lockForUpdate()->findOrFail($orientation->id);
            if ($locked->destination_module !== CatalogModule::Maternity || $locked->status !== EpisodeOrientationStatus::InProgress) {
                throw ValidationException::withMessages(['cesarean' => 'La prise en charge Maternité n’est pas active.']);
            }

            $existing = $locked->episode->surgicalRequests()
                ->where('procedure_name', 'like', 'Opération césarienne%')
                ->whereNull('completed_at')
                ->first();
            if ($existing) {
                throw ValidationException::withMessages(['cesarean' => 'Une demande de césarienne est déjà active pour ce passage.']);
            }

            $maternityCode = $type === 'TWIN' ? 'MAT-CESAREAN-TWIN' : 'MAT-CESAREAN-SIMPLE';
            $maternityReference = CatalogItem::query()->where('code', $maternityCode)->where('module', CatalogModule::Maternity->value)->firstOrFail();
            $surgeryReference = CatalogItem::query()->where('code', 'SURG-CESARIENNE')->where('module', CatalogModule::Surgery->value)->first();

            $this->createOrientation->execute(
                $locked->episode,
                CatalogModule::Maternity,
                CatalogModule::Surgery,
                $actor,
                'Décision de césarienne depuis la Maternité : '.trim($indication),
            );

            return $this->createSurgicalRequest->execute($locked->episode, [
                'catalog_item_id' => $surgeryReference?->id,
                'procedure_name' => $type === 'TWIN' ? 'Opération césarienne gémellaire' : 'Opération césarienne simple',
                'procedure_details' => trim($indication),
                'notes' => "Demande créée depuis Maternité ({$maternityReference->code}).",
            ]);
        });
    }
}
