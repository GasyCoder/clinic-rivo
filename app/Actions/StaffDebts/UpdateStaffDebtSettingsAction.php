<?php

namespace App\Actions\StaffDebts;

use App\Models\StaffDebtSetting;
use App\Models\User;
use App\Support\Authorization\RemoteActorAttribution;
use App\Support\Money;
use App\Support\StaffDebts\StaffDebtInterest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ADR-229 — le Super Admin règle les dettes du personnel d'un site depuis le portail
 * (Finance › Dettes du personnel › Réglages), par l'API du site. Une valeur vide ne pose
 * aucune limite. Les nouvelles règles valent pour les demandes et décisions à venir :
 * aucune dette déjà accordée n'est recalculée. Audité par le modèle, au nom de l'acteur.
 */
class UpdateStaffDebtSettingsAction
{
    /** @param  array<string, mixed>  $data */
    public function execute(array $data, User $actor): StaffDebtSetting
    {
        if ($actor->cannot('staff_debts.settings')) {
            throw new AuthorizationException('Seul le Super Admin règle les dettes du personnel.');
        }

        $min = filled($data['min_amount'] ?? null) ? Money::normalize((string) $data['min_amount']) : null;
        $max = filled($data['max_amount'] ?? null) ? Money::normalize((string) $data['max_amount']) : null;

        if ($min !== null && $max !== null && Money::toMinor($max) < Money::toMinor($min)) {
            throw ValidationException::withMessages(['max_amount' => 'Le montant maximum ne peut pas être inférieur au minimum.']);
        }

        $tiers = StaffDebtInterest::normalize(is_array($data['interest_tiers'] ?? null) ? $data['interest_tiers'] : []);
        $open = (bool) ($data['requests_open'] ?? true);

        return DB::transaction(function () use ($data, $actor, $min, $max, $tiers, $open): StaffDebtSetting {
            $setting = StaffDebtSetting::query()->lockForUpdate()->first() ?? new StaffDebtSetting;

            $setting->fill([
                'requests_open' => $open,
                'closed_message' => filled($data['closed_message'] ?? null) ? Str::squish((string) $data['closed_message']) : null,
                'min_amount' => $min,
                'max_amount' => $max,
                'max_months' => $this->count($data['max_months'] ?? null),
                'max_salary_share' => $this->count($data['max_salary_share'] ?? null),
                'max_open_debts' => $this->count($data['max_open_debts'] ?? null),
                'min_seniority_months' => $this->count($data['min_seniority_months'] ?? null),
                'exclude_interns' => (bool) ($data['exclude_interns'] ?? false),
                'interest_tiers' => $tiers ?: null,
                'updated_by' => $actor->getKey(),
                ...RemoteActorAttribution::fields('updated', $actor),
            ])->save();

            return $setting;
        });
    }

    /** Une limite vide (ou zéro) n'en est pas une. */
    private function count(mixed $value): ?int
    {
        return filled($value) && (int) $value > 0 ? (int) $value : null;
    }
}
