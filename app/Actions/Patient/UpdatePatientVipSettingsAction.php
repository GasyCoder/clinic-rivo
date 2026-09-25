<?php

namespace App\Actions\Patient;

use App\Enums\DiscountType;
use App\Models\PatientVipSetting;
use App\Services\Audit\Auditor;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * ADR-133 — règle les seuils des patients VIP d'un site.
 *
 * Une seule ligne, remplacée sur place : le statut VIP n'est jamais stocké, il
 * se recalcule à chaque lecture depuis ces seuils. Un changement de seuil ne
 * réécrit donc aucun dossier ni aucune facture — il change seulement qui est
 * VIP à partir de maintenant. L'audit garde l'ancienne et la nouvelle valeur,
 * avec l'identité du Super Administrateur central (ADR-027).
 *
 * ADR-192 — la remise VIP se règle ici, avec les seuils : elle ne réécrit aucune
 * facture déjà remisée, elle vaut pour les encaissements suivants.
 */
class UpdatePatientVipSettingsAction
{
    public function __construct(private readonly Auditor $auditor) {}

    /** @param array{enabled: bool, min_episodes: int, min_amount: numeric-string|int|float, window_months: int, discount_type?: ?string, discount_value?: numeric-string|int|float|null} $data */
    public function execute(array $data, CatalogActor $actor): PatientVipSetting
    {
        if ($actor->cannot('patient_vip.update')) {
            throw new AuthorizationException('Vous ne pouvez pas régler les seuils des patients VIP.');
        }

        return DB::transaction(function () use ($data, $actor): PatientVipSetting {
            $setting = PatientVipSetting::query()->lockForUpdate()->first() ?? new PatientVipSetting;
            $before = $setting->exists ? $this->snapshot($setting) : [];

            // ADR-192 — la remise VIP : un type et une valeur ensemble, ou aucune remise.
            $discount = DiscountType::rule($data['discount_type'] ?? null, $data['discount_value'] ?? null);

            $setting->fill([
                'discount_type' => $discount['type'] ?? null,
                'discount_value' => $discount['value'] ?? null,
                'enabled' => (bool) $data['enabled'],
                'min_episodes' => (int) $data['min_episodes'],
                'min_amount' => $data['min_amount'],
                'window_months' => (int) $data['window_months'],
                'updated_by' => $actor->localUserId(),
            ] + $actor->externalAttribution('updated'))->save();

            $this->auditor->record(
                'patient_vip.settings.update',
                entity: $setting,
                oldValues: $before,
                newValues: $this->snapshot($setting->fresh()),
                module: 'reception',
            );

            return $setting->fresh();
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(PatientVipSetting $setting): array
    {
        return [
            'enabled' => (bool) $setting->enabled,
            'min_episodes' => $setting->min_episodes,
            'min_amount' => (string) $setting->min_amount,
            'window_months' => $setting->window_months,
            'discount' => $setting->discount_type?->describe((string) $setting->discount_value),
        ];
    }
}
