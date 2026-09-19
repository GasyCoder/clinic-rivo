<?php

namespace App\Http\Requests\Pediatrics;

use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Http\Requests\StoreMedicalDischargeRequest;
use App\Models\EpisodeOrientation;

/**
 * ADR-114 — la sortie Pédiatrie reprend exactement les règles de la sortie
 * médicale. Seule l'autorisation change : elle porte sur une prise en charge
 * Pédiatrie en cours. Le diagnostic final y est exigé (CDC §33.1).
 */
class DischargePediatricsRequest extends StoreMedicalDischargeRequest
{
    public function authorize(): bool
    {
        $orientation = $this->route('episodeOrientation');

        return $orientation instanceof EpisodeOrientation
            && $orientation->destination_module === CatalogModule::Pediatrics
            && $orientation->status === EpisodeOrientationStatus::InProgress
            && ! $orientation->episode->medicalDischarge()->exists()
            && (bool) $this->user()?->can('pediatrics.manage')
            && (bool) $this->user()?->can('medical_discharge.create');
    }
}
