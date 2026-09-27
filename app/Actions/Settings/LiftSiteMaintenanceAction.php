<?php

namespace App\Actions\Settings;

use App\Models\SiteMaintenance;
use App\Services\Audit\Auditor;
use App\Services\Catalog\CatalogActor;
use App\Services\Settings\SiteMaintenanceState;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-193 — lever la maintenance en cours (le site rouvre tout de suite), ou
 * annuler celle qui est programmée. Elle n'est pas effacée : sa ligne garde qui
 * l'a levée, quand, et le motif s'il en a été donné un.
 */
class LiftSiteMaintenanceAction
{
    public function __construct(
        private readonly Auditor $auditor,
        private readonly SiteMaintenanceState $state,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function execute(?string $reason, CatalogActor $actor): SiteMaintenance
    {
        if ($actor->cannot(SiteMaintenanceState::MANAGE_PERMISSION)) {
            throw new AuthorizationException('Vous ne pouvez pas lever la maintenance de ce site.');
        }

        SiteMaintenanceState::ensureInstalled();

        $maintenance = DB::transaction(function () use ($reason, $actor): SiteMaintenance {
            $now = now();
            $current = SiteMaintenance::query()->open($now)->latest('starts_at')->latest('id')->lockForUpdate()->first();

            if ($current === null) {
                throw ValidationException::withMessages([
                    'maintenance' => 'Aucune maintenance n’est en cours ni programmée sur ce site.',
                ]);
            }

            $wasUpcoming = $current->isUpcoming($now);

            $current->fill([
                'lifted_at' => $now,
                'lifted_by' => $actor->localUserId(),
                'lift_reason' => filled($reason) ? trim((string) $reason) : null,
            ] + $actor->externalAttribution('lifted'))->save();

            $this->auditor->record(
                $wasUpcoming ? 'app_maintenance.cancel' : 'app_maintenance.lift',
                entity: $current,
                newValues: ['lifted_at' => $now->toIso8601String()],
                oldValues: [
                    'starts_at' => $current->starts_at->toIso8601String(),
                    'ends_at' => $current->ends_at?->toIso8601String(),
                ],
                reason: $current->lift_reason,
                module: 'settings',
            );

            return $current;
        });

        $this->state->forget();

        return $maintenance;
    }
}
