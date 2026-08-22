<?php

namespace App\Actions\Reception;

use App\DTOs\Reception\ArrivalRegistrationResult;
use App\Enums\ArrivalPaymentChoice;
use App\Enums\EpisodePriority;
use App\Models\User;

/**
 * Backward-compatible application facade.
 *
 * ADR-030's web workflow now performs these phases on two screens, but API
 * or command callers may still request both. The same routing and billing
 * actions are used, so this facade cannot bypass the new clinical rules.
 */
class CompletePatientArrivalAction
{
    public function __construct(
        private readonly RegisterArrivalAction $registerArrival,
        private readonly CompleteEpisodeServicesAction $completeServices,
    ) {}

    /**
     * @param  array<string, mixed>|null  $newPatientData
     * @param  array<string, mixed>|null  $existingPatientData
     * @param  array<int, array{catalog_item_uuid: string, quantity: int|string}>  $catalogLines
     */
    public function execute(
        User $actor,
        ?string $existingPatientUuid,
        ?array $newPatientData,
        ?array $existingPatientData = null,
        bool $confirmDuplicate = false,
        EpisodePriority $priority = EpisodePriority::Normal,
        array $catalogLines = [],
        ArrivalPaymentChoice $paymentChoice = ArrivalPaymentChoice::Later,
        ?int $paymentMethodId = null,
        ?string $paymentReference = null,
    ): ArrivalRegistrationResult {
        $episode = $this->registerArrival->execute(
            existingPatientUuid: $existingPatientUuid,
            newPatientData: $newPatientData,
            existingPatientData: $existingPatientData,
            confirmDuplicate: $confirmDuplicate,
            priority: $priority,
            actor: $actor,
        );

        if ($catalogLines === []) {
            return new ArrivalRegistrationResult($episode);
        }

        return $this->completeServices->execute(
            episode: $episode,
            actor: $actor,
            catalogLines: $catalogLines,
            designationDeferred: false,
            paymentChoice: $paymentChoice,
            paymentMethodId: $paymentMethodId,
            paymentReference: $paymentReference,
        );
    }
}
