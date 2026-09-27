<?php

namespace App\Actions\Reception;

use App\Actions\Surgery\CreateSurgicalRequestAction;
use App\Enums\CatalogModule;
use App\Enums\ReceptionRoutingMode;
use App\Enums\SurgicalRequestOrigin;
use App\Enums\SurgicalRequestStatus;
use App\Models\Episode;
use App\Models\EpisodeServiceRequest;
use App\Models\SurgicalRequest;
use Illuminate\Support\Collection;

/**
 * ADR-159 — la demande de bloc née d'un besoin annoncé à l'arrivée.
 *
 * Deux chemins seulement mènent au bloc : la Réception, quand l'acte est la
 * raison de la venue, et le médecin, par sa conduite à tenir (ADR-084). Cette
 * action porte le premier, sur le modèle exact de la demande d'analyses
 * (ADR-068) : l'orientation `RECEPTION → SURGERY` est déjà créée, la demande
 * l'accompagne.
 *
 * Elle ne décide rien de clinique : ni chirurgien, ni date, ni bilan — la
 * demande arrive `PENDING`, et c'est le bloc qui la programme.
 */
class CreateReceptionSurgicalRequestAction
{
    public function __construct(private readonly CreateSurgicalRequestAction $createSurgicalRequest) {}

    /**
     * @param  Collection<int, EpisodeServiceRequest>  $serviceRequests
     * @return list<SurgicalRequest>
     */
    public function execute(Episode $episode, Collection $serviceRequests): array
    {
        // L'orientation `RECEPTION → SURGERY` est déjà créée par le routage :
        // elle situe la demande dans le parcours, la demande n'a pas à la
        // recopier — `SurgicalRequest` appartient à l'épisode.

        $selected = $serviceRequests->filter(
            fn (EpisodeServiceRequest $request) => $request->module === CatalogModule::Surgery
                && $request->routing_mode === ReceptionRoutingMode::SurgeryDirect,
        );

        $created = [];

        foreach ($selected as $request) {
            // Idempotent comme le reste du routage : une confirmation rejouée
            // ne doit jamais ouvrir deux fois le même dossier au bloc.
            $existing = SurgicalRequest::query()
                ->where('episode_id', $episode->getKey())
                ->where('catalog_item_id', $request->catalog_item_id)
                ->where('status', '!=', SurgicalRequestStatus::Cancelled->value)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $created[] = $existing;

                continue;
            }

            $created[] = $this->createSurgicalRequest->execute($episode, [
                'catalog_item_id' => $request->catalog_item_id,
                // L'instantané du libellé pris à l'arrivée : une correction
                // ultérieure du catalogue ne réécrit pas la demande (ADR-024).
                'procedure_name' => $request->designation,
                'notes' => 'Acte sélectionné à la Réception à l’arrivée du patient.',
            ], SurgicalRequestOrigin::Reception);
        }

        return $created;
    }
}
