<?php

namespace App\Actions\Bonus;

use App\Enums\BonusAwardStatus;
use App\Models\BonusAward;
use App\Models\BonusCategory;
use App\Models\Employee;
use App\Models\User;
use App\Services\Bonus\BonusMeter;
use App\Support\Authorization\RemoteActorAttribution;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-212 — valider un bonus atteint.
 *
 * Le serveur recompte : le seuil doit être atteint pour ce mois, ce membre du
 * personnel concerné par cette catégorie, la catégorie en service. Nombre de
 * patients, seuil, montant et liste des patients comptés sont figés sur le
 * bonus. Un seul bonus en vigueur par catégorie, employé et mois.
 */
class ValidateBonusAwardAction
{
    public function __construct(private readonly BonusMeter $meter) {}

    public function execute(BonusCategory $category, Employee $employee, Carbon $month, User $actor): BonusAward
    {
        if ($actor->cannot('bonus_awards.validate')) {
            throw new AuthorizationException('Vous ne pouvez pas valider un bonus.');
        }

        $month = $month->copy()->startOfMonth();

        if ($month->isAfter(now()->startOfMonth())) {
            throw ValidationException::withMessages(['period' => 'Un mois à venir n’a pas encore de bonus.']);
        }

        return DB::transaction(function () use ($category, $employee, $month, $actor): BonusAward {
            $category = BonusCategory::query()->lockForUpdate()->findOrFail($category->getKey());

            if (! $category->employees()->whereKey($employee->getKey())->exists()) {
                throw ValidationException::withMessages(['employee' => 'Ce membre du personnel n’est pas concerné par cette catégorie.']);
            }

            $key = BonusAward::activeKey($category->getKey(), $employee->getKey(), $month->format('Y-m'));

            if (BonusAward::query()->where('active_key', $key)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['period' => 'Ce bonus est déjà validé pour ce mois.']);
            }

            $patients = $this->meter->patients($category->measure, collect([$employee]), $month)[$employee->getKey()] ?? [];

            if (count($patients) < $category->threshold) {
                throw ValidationException::withMessages([
                    'period' => 'Seuil non atteint : '.count($patients).' patient(s) sur '.$category->threshold.' ce mois-ci.',
                ]);
            }

            return BonusAward::query()->create([
                'bonus_category_id' => $category->getKey(),
                'employee_id' => $employee->getKey(),
                'period' => $month->toDateString(),
                'category_name' => $category->name,
                'measure' => $category->measure,
                'patients_count' => count($patients),
                'threshold' => $category->threshold,
                'amount' => $category->amount,
                'counted_patients' => array_slice(array_column($patients, 'patient_number'), 0, 500),
                'status' => BonusAwardStatus::Validated,
                'active_key' => $key,
                'validated_at' => now(),
                'validated_by' => $actor->getKey(),
                ...RemoteActorAttribution::fields('validated', $actor),
            ]);
        });
    }
}
