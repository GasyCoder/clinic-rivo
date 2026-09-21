<?php

namespace App\Actions\Hospitalization;

use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Actions\Surgery\CreateSurgicalRequestAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodeStatus;
use App\Enums\HospitalStayStatus;
use App\Enums\SurgicalRequestOrigin;
use App\Enums\SurgicalRequestStatus;
use App\Models\CatalogItem;
use App\Models\HospitalStay;
use App\Models\SurgicalRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-160 — le patient hospitalisé descend au bloc, et garde son lit.
 *
 * Le chemin par la consultation existait déjà (ADR-084), mais il est le
 * mauvais outil ici : une consultation ne porte qu'UNE conduite à tenir
 * (`active_key`), si bien que choisir « Chirurgie » sur celle qui a demandé
 * l'hospitalisation **annule la demande et le séjour avec elle** — vérifié :
 * le patient perdait son lit. Le séjour est donc le point de décision, et
 * cette action n'y touche pas : elle crée l'orientation `HOSPITALIZATION →
 * SURGERY` et la demande à côté du séjour, jamais à sa place.
 */
class RequestSurgeryFromStayAction
{
    public function __construct(
        private readonly CreateEpisodeOrientationAction $createOrientation,
        private readonly CreateSurgicalRequestAction $createSurgicalRequest,
    ) {}

    /**
     * @param  array{catalog_item_uuid: string, indication?: ?string, priority: string, notes?: ?string}  $data
     */
    public function execute(HospitalStay $stay, array $data, User $actor): SurgicalRequest
    {
        return DB::transaction(function () use ($stay, $data, $actor): SurgicalRequest {
            $locked = HospitalStay::query()->lockForUpdate()->findOrFail($stay->getKey());

            if ($locked->status !== HospitalStayStatus::Active) {
                throw ValidationException::withMessages([
                    'catalog_item_uuid' => 'Ce séjour est terminé : le patient n’est plus au lit.',
                ]);
            }

            $episode = $locked->episode()->lockForUpdate()->firstOrFail();

            if ($episode->status !== EpisodeStatus::Open) {
                throw ValidationException::withMessages([
                    'catalog_item_uuid' => 'Ce passage est clos.',
                ]);
            }

            $catalogItem = CatalogItem::query()
                ->where('uuid', $data['catalog_item_uuid'])
                ->where('type', CatalogItemType::Service->value)
                ->where('module', CatalogModule::Surgery->value)
                ->whereNull('deleted_at')
                ->first();

            if (! $catalogItem) {
                throw ValidationException::withMessages([
                    'catalog_item_uuid' => 'Cette intervention n’est plus disponible.',
                ]);
            }

            // Une demande encore ouverte au bloc n'est pas redoublée : le
            // patient n'est descendu qu'une fois pour la même intervention.
            $existing = SurgicalRequest::query()
                ->where('episode_id', $episode->getKey())
                ->where('catalog_item_id', $catalogItem->getKey())
                ->whereNotIn('status', [
                    SurgicalRequestStatus::Cancelled->value,
                    SurgicalRequestStatus::Discharged->value,
                ])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $this->createOrientation->execute(
                $episode,
                CatalogModule::Hospitalization,
                CatalogModule::Surgery,
                $actor,
                'Transfert au bloc depuis l’hospitalisation.',
            );

            return $this->createSurgicalRequest->execute($episode, [
                'catalog_item_id' => $catalogItem->getKey(),
                'procedure_name' => $catalogItem->name,
                'procedure_details' => $this->clean($data['indication'] ?? null),
                // Ce que le séjour porte déjà, jamais ressaisi (§17, ADR-084) :
                // le motif d'hospitalisation et le diagnostic d'entrée sont sur
                // la demande, la chambre sur le séjour. Rien n'est inventé —
                // une valeur absente reste absente.
                'notes' => $this->notes($locked, $data),
            ], SurgicalRequestOrigin::Hospitalization);
        });
    }

    /** @param array<string, mixed> $data */
    private function notes(HospitalStay $stay, array $data): ?string
    {
        $request = $stay->hospitalizationRequest()->first();

        $lines = array_filter([
            'Patient hospitalisé — transfert au bloc depuis le séjour.',
            $stay->service ? "Service : {$stay->service}" : null,
            $stay->room_bed ? "Chambre / lit : {$stay->room_bed}" : null,
            $request?->admission_diagnosis ? "Diagnostic d’entrée : {$request->admission_diagnosis}" : null,
            'Priorité : '.$data['priority'],
            $this->clean($data['notes'] ?? null),
        ]);

        return implode("\n", $lines);
    }

    private function clean(?string $value): ?string
    {
        return trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
