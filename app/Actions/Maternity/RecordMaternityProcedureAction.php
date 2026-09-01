<?php

namespace App\Actions\Maternity;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Models\CatalogItem;
use App\Models\EpisodeOrientation;
use App\Models\MaternityProcedure;
use App\Models\MaternityRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordMaternityProcedureAction
{
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

            if (in_array($item->code, ['MAT-CESAREAN-SIMPLE', 'MAT-CESAREAN-TWIN'], true)) {
                throw ValidationException::withMessages([
                    'catalog_item_uuid' => 'Une césarienne doit être transmise au workflow Chirurgie et ne peut pas être enregistrée comme un acte Maternité.',
                ]);
            }

            $record = $locked->episode->maternityRecord ?? MaternityRecord::query()->create([
                'episode_id' => $locked->episode_id,
                'episode_orientation_id' => $locked->id,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            return $record->procedures()->create([
                'catalog_item_id' => $item->id,
                'catalog_item_uuid' => $item->uuid,
                'procedure_code' => $item->code,
                'procedure_name' => $item->name,
                'quantity' => $data['quantity'],
                'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
                'performed_by' => $actor->id,
                'performed_at' => now(),
            ]);
        });
    }
}
