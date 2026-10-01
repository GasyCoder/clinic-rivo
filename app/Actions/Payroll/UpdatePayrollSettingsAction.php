<?php

namespace App\Actions\Payroll;

use App\Models\PayrollSetting;
use App\Models\User;
use App\Support\Authorization\RemoteActorAttribution;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * ADR-233 — enregistrer les paramètres de paie du site (une seule ligne). Une paie déjà
 * payée garde les paramètres qu'elle a utilisés : rien n'est recalculé après coup. Chaque
 * modification est auditée avec l'ancienne et la nouvelle valeur (`Auditable`).
 */
class UpdatePayrollSettingsAction
{
    /** @param  array<string, mixed>  $settings  `PayrollSettingsRequest::settings()` */
    public function execute(array $settings, User $actor): PayrollSetting
    {
        if ($actor->cannot('salary_settings.update')) {
            throw new AuthorizationException('Vous ne pouvez pas modifier les paramètres de paie.');
        }

        return DB::transaction(function () use ($settings, $actor): PayrollSetting {
            $row = PayrollSetting::query()->lockForUpdate()->orderBy('id')->first() ?? new PayrollSetting;
            $row->fill([
                ...$settings,
                'updated_by' => $actor->getKey(),
                ...RemoteActorAttribution::fields('updated', $actor),
            ])->save();

            return $row;
        });
    }
}
