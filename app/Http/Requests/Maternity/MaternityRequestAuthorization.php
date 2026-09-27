<?php

namespace App\Http\Requests\Maternity;

use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Models\EpisodeOrientation;

/**
 * ADR-204 — une demande d'examen depuis la Maternité (ADR-205 : ou une
 * ordonnance) : une prise en charge Maternité en cours, et le droit de le faire. Le droit du service
 * demandeur ne suffit pas — `laboratory_orders.create` / `imaging_orders.create`
 * restent ceux de toute demande, et l'action revérifie l'état sous verrou.
 */
trait MaternityRequestAuthorization
{
    protected function maternityAllows(string ...$permissions): bool
    {
        $orientation = $this->route('episodeOrientation');
        $user = $this->user();

        return $orientation instanceof EpisodeOrientation
            && $orientation->destination_module === CatalogModule::Maternity
            && $orientation->status === EpisodeOrientationStatus::InProgress
            && $user !== null
            && collect($permissions)->every(fn (string $permission): bool => $user->can($permission));
    }
}
