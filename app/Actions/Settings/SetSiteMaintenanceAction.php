<?php

namespace App\Actions\Settings;

use App\Models\SiteMaintenance;
use App\Services\Audit\Auditor;
use App\Services\Catalog\CatalogActor;
use App\Services\Settings\SiteMaintenanceState;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-193 — mettre ce site en maintenance tout de suite, ou la programmer, ou
 * modifier celle qui est en cours ou à venir.
 *
 *   maintenant    le début est l'heure du serveur ; une maintenance déjà en cours
 *                 garde son heure de début (on ne corrige que le message ou la fin)
 *   programmée    le début est choisi, à venir ; programmer une maintenance déjà
 *                 en cours la repousse : le site rouvre jusqu'au nouveau début
 *
 * Il n'y en a jamais deux ouvertes à la fois : celle qui existe est verrouillée
 * et modifiée. Aucune n'est jamais effacée.
 */
class SetSiteMaintenanceAction
{
    public function __construct(
        private readonly Auditor $auditor,
        private readonly SiteMaintenanceState $state,
    ) {}

    /**
     * @param  array{mode: string, title: string, message?: ?string, starts_at?: ?string, ends_at?: ?string}  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function execute(array $data, CatalogActor $actor): SiteMaintenance
    {
        if ($actor->cannot(SiteMaintenanceState::MANAGE_PERMISSION)) {
            throw new AuthorizationException('Vous ne pouvez pas mettre ce site en maintenance.');
        }

        if (! SiteMaintenanceState::applies()) {
            throw ValidationException::withMessages([
                'site_code' => 'La maintenance ne concerne que les sites cliniques : le portail n’en a pas.',
            ]);
        }

        SiteMaintenanceState::ensureInstalled();

        $maintenance = DB::transaction(function () use ($data, $actor): SiteMaintenance {
            $now = now();
            $current = SiteMaintenance::query()->open($now)->latest('starts_at')->latest('id')->lockForUpdate()->first();

            $startsAt = $data['mode'] === 'now'
                ? ($current?->isActive($now) ? $current->starts_at : $now)
                : Carbon::parse((string) $data['starts_at']);
            $endsAt = filled($data['ends_at'] ?? null) ? Carbon::parse((string) $data['ends_at']) : null;

            if ($endsAt !== null && $endsAt->lte($startsAt)) {
                throw ValidationException::withMessages(['ends_at' => 'La fin prévue suit le début de la maintenance.']);
            }

            $values = [
                'title' => trim((string) $data['title']),
                'message' => filled($data['message'] ?? null) ? trim((string) $data['message']) : null,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ];

            if ($current === null) {
                $maintenance = SiteMaintenance::create([
                    ...$values,
                    'created_by' => $actor->localUserId(),
                ] + $actor->externalAttribution('created'));

                $this->auditor->record(
                    $data['mode'] === 'now' ? 'app_maintenance.start' : 'app_maintenance.schedule',
                    entity: $maintenance,
                    newValues: $this->snapshot($maintenance),
                    module: 'settings',
                );

                return $maintenance;
            }

            $before = $this->snapshot($current);
            $current->fill([
                ...$values,
                'updated_by' => $actor->localUserId(),
            ] + $actor->externalAttribution('updated'))->save();

            $this->auditor->record(
                'app_maintenance.update',
                entity: $current,
                newValues: $this->snapshot($current),
                oldValues: $before,
                module: 'settings',
            );

            return $current;
        });

        $this->state->forget();

        return $maintenance;
    }

    /** @return array<string, mixed> */
    private function snapshot(SiteMaintenance $maintenance): array
    {
        return [
            'title' => $maintenance->title,
            'message' => $maintenance->message,
            'starts_at' => $maintenance->starts_at?->toIso8601String(),
            'ends_at' => $maintenance->ends_at?->toIso8601String(),
        ];
    }
}
