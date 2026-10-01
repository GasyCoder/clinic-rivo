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
 * aucune limite, sauf le montant minimum et maximum : ils sont exigés pour ouvrir les
 * demandes (amendement du 2026-09-30). Les nouvelles règles valent pour les demandes et
 * décisions à venir : aucune dette déjà accordée n'est recalculée. Audité par le modèle,
 * au nom de l'acteur.
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

        $open = (bool) ($data['requests_open'] ?? true);

        // ADR-229 (amendement du 2026-09-30) — des demandes ouvertes sont bornées : sans
        // minimum et maximum, le personnel pourrait demander n'importe quel montant.
        $missing = [];
        foreach (['min_amount' => [$min, 'minimum'], 'max_amount' => [$max, 'maximum']] as $field => [$value, $word]) {
            if ($value !== null && Money::toMinor($value) <= 0) {
                $missing[$field] = "Le montant {$word} doit être supérieur à 0 Ar.";
            } elseif ($value === null && $open) {
                $missing[$field] = "Réglez le montant {$word} pour ouvrir les demandes, ou fermez-les.";
            }
        }
        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }

        if ($min !== null && $max !== null && Money::toMinor($max) < Money::toMinor($min)) {
            throw ValidationException::withMessages(['max_amount' => 'Le montant maximum ne peut pas être inférieur au minimum.']);
        }

        $tiers = StaffDebtInterest::normalize(is_array($data['interest_tiers'] ?? null) ? $data['interest_tiers'] : []);
        $penalty = $this->penalty($data);

        return DB::transaction(function () use ($data, $actor, $min, $max, $tiers, $open, $penalty): StaffDebtSetting {
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
                ...$penalty,
                'updated_by' => $actor->getKey(),
                ...RemoteActorAttribution::fields('updated', $actor),
            ])->save();

            return $setting;
        });
    }

    /**
     * ADR-230 — la pénalité de retard : un taux mensuel (au plus 10 %) sur le seul montant
     * en retard, un délai de grâce en jours et un plafond, obligatoire, en pourcentage du
     * montant emprunté. Un taux vide : aucune pénalité.
     *
     * @param  array<string, mixed>  $data
     * @return array{penalty_rate: ?string, penalty_grace_days: ?int, penalty_cap_rate: ?string}
     */
    private function penalty(array $data): array
    {
        $rate = filled($data['penalty_rate'] ?? null) ? Money::normalize((string) $data['penalty_rate']) : null;

        if ($rate === null || Money::toMinor($rate) <= 0) {
            return ['penalty_rate' => null, 'penalty_grace_days' => null, 'penalty_cap_rate' => null];
        }

        if (Money::toMinor($rate) > 1_000) {
            throw ValidationException::withMessages(['penalty_rate' => 'Une pénalité de retard ne dépasse pas 10 % par mois.']);
        }

        $cap = filled($data['penalty_cap_rate'] ?? null) ? Money::normalize((string) $data['penalty_cap_rate']) : null;
        if ($cap === null || Money::toMinor($cap) <= 0) {
            throw ValidationException::withMessages(['penalty_cap_rate' => 'Une pénalité de retard se plafonne : indiquez le plafond, en pourcentage du montant emprunté.']);
        }

        if (Money::toMinor($cap) > 10_000) {
            throw ValidationException::withMessages(['penalty_cap_rate' => 'Le plafond ne dépasse pas 100 % du montant emprunté.']);
        }

        return [
            'penalty_rate' => $rate,
            'penalty_grace_days' => filled($data['penalty_grace_days'] ?? null) ? max(0, (int) $data['penalty_grace_days']) : 0,
            'penalty_cap_rate' => $cap,
        ];
    }

    /** Une limite vide (ou zéro) n'en est pas une. */
    private function count(mixed $value): ?int
    {
        return filled($value) && (int) $value > 0 ? (int) $value : null;
    }
}
