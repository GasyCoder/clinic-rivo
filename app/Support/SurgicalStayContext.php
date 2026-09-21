<?php

namespace App\Support;

use App\Enums\HospitalStayStatus;
use App\Models\HospitalStay;
use App\Models\SurgicalRequest;
use App\Models\User;

/**
 * ADR-160 — un patient hospitalisé descend au bloc et remonte à son lit.
 *
 * Chirurgie et Anesthésie travaillent sur le même dossier (ADR-048) : elles
 * lisent donc le séjour au même endroit, jamais chacune le sien. Le fait
 * (service, chambre, depuis quand) est servi à qui voit le dossier ; le lien
 * vers le séjour, seulement avec `hospitalization.view` — un lien qui mène à
 * un refus vaut moins qu'une absence de lien (ADR-146).
 */
class SurgicalStayContext
{
    /** @return array{service: ?string, room_bed: ?string, admitted_at: mixed, url: ?string}|null */
    public static function for(SurgicalRequest $surgicalRequest, User $viewer): ?array
    {
        $stay = HospitalStay::query()
            ->where('episode_id', $surgicalRequest->episode_id)
            ->where('status', HospitalStayStatus::Active->value)
            ->first();

        if (! $stay) {
            return null;
        }

        return [
            'service' => $stay->service,
            'room_bed' => $stay->room_bed,
            'admitted_at' => $stay->admitted_at,
            'url' => $viewer->can('hospitalization.view') ? "/hospitalisation/{$stay->uuid}" : null,
        ];
    }
}
