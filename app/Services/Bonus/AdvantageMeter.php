<?php

namespace App\Services\Bonus;

use App\Enums\AdvantageSource;
use App\Enums\BillableItemStatus;
use App\Enums\ReferralSource;
use App\Models\AdvantageArticle;
use App\Models\Employee;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Avantages à l'acte : combien d'actes de chaque article chaque personne compte sur
 * un mois. Rien n'est saisi : tout se lit sur des faits déjà enregistrés.
 *
 *   actes réalisés    l'acte fait par la personne — compte rendu d'imagerie, résultat
 *                     d'analyse, intervention, acte de soins ou de maternité — lu sur son
 *                     compte de connexion (ADR-188) ; employés seulement
 *   patients référés  chaque acte de l'article facturé sur le passage où un patient
 *                     recommandé par la personne est arrivé (ADR-212) ; employés et
 *                     partenaires ; jamais les passages suivants du patient
 *
 * Seules comptent les personnes dont les avantages sont ouverts : un employé dont la
 * case « Avantages » (ou la fonction) le dit, un partenaire en service.
 *
 * Une clé par personne : « E12 » (employé), « P3 » (partenaire).
 */
class AdvantageMeter
{
    /**
     * @param  Collection<int, AdvantageArticle>  $articles  avec leurs `catalogItems`
     * @return array<string, array<int, list<string>>> clé de personne → id d'article → numéros de passage (un par acte)
     */
    public function count(Collection $articles, Carbon $month, ?string $onlyKey = null): array
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();
        $result = [];

        foreach ($articles as $article) {
            $itemIds = $article->catalogItems->modelKeys();

            if ($itemIds === []) {
                continue;
            }

            $rows = $article->source === AdvantageSource::Performed
                ? $this->performed($itemIds, $from, $to, $onlyKey)
                : $this->referred($itemIds, $from, $to, $onlyKey);

            foreach ($rows as $row) {
                $result[$row['key']][$article->getKey()][] = $row['reference'];
            }
        }

        return $result;
    }

    /** @return list<array{key: string, reference: string}> */
    private function performed(array $itemIds, Carbon $from, Carbon $to, ?string $onlyKey): array
    {
        if ($onlyKey !== null && ! str_starts_with($onlyKey, 'E')) {
            return [];
        }

        $employees = Employee::query()->with('jobTitle')->whereNotNull('user_id')->where('active', true)
            ->when($onlyKey !== null, fn ($query) => $query->whereKey((int) substr($onlyKey, 1)))
            ->get()
            ->filter(fn (Employee $employee) => $employee->grantsBenefits())
            ->mapWithKeys(fn (Employee $employee) => [$employee->user_id => 'E'.$employee->getKey()]);

        if ($employees->isEmpty()) {
            return [];
        }

        $users = $employees->keys()->all();
        $sources = [
            DB::table('imaging_request_items')
                ->join('imaging_requests', 'imaging_requests.id', '=', 'imaging_request_items.imaging_request_id')
                ->whereNull('imaging_requests.cancelled_at')
                ->whereIn('imaging_request_items.catalog_item_id', $itemIds)
                ->whereIn('imaging_request_items.resulted_by', $users)
                ->whereBetween('imaging_request_items.resulted_at', [$from, $to])
                ->select(['imaging_request_items.resulted_by as owner', 'imaging_requests.episode_id']),
            DB::table('lab_request_items')
                ->join('lab_requests', 'lab_requests.id', '=', 'lab_request_items.lab_request_id')
                ->whereNull('lab_requests.cancelled_at')
                ->whereNull('lab_requests.deleted_at')
                ->whereNull('lab_request_items.deleted_at')
                ->whereIn('lab_request_items.catalog_item_id', $itemIds)
                ->whereIn('lab_request_items.resulted_by', $users)
                ->whereBetween('lab_request_items.resulted_at', [$from, $to])
                ->select(['lab_request_items.resulted_by as owner', 'lab_requests.episode_id']),
            DB::table('surgical_interventions')
                ->join('surgical_requests', 'surgical_requests.id', '=', 'surgical_interventions.surgical_request_id')
                ->whereIn('surgical_requests.catalog_item_id', $itemIds)
                ->whereIn('surgical_interventions.performed_by', $users)
                ->whereBetween('surgical_interventions.ended_at', [$from, $to])
                ->select(['surgical_interventions.performed_by as owner', 'surgical_requests.episode_id']),
            DB::table('care_record_procedures')
                ->join('care_records', 'care_records.id', '=', 'care_record_procedures.care_record_id')
                ->whereIn('care_record_procedures.catalog_item_id', $itemIds)
                ->whereIn('care_record_procedures.performed_by', $users)
                ->whereBetween('care_record_procedures.performed_at', [$from, $to])
                ->select(['care_record_procedures.performed_by as owner', 'care_records.episode_id']),
            DB::table('maternity_procedures')
                ->join('maternity_records', 'maternity_records.id', '=', 'maternity_procedures.maternity_record_id')
                ->whereNull('maternity_procedures.deleted_at')
                ->whereIn('maternity_procedures.catalog_item_id', $itemIds)
                ->whereIn('maternity_procedures.performed_by', $users)
                ->whereBetween('maternity_procedures.performed_at', [$from, $to])
                ->select(['maternity_procedures.performed_by as owner', 'maternity_records.episode_id']),
        ];

        $rows = [];

        foreach ($sources as $source) {
            foreach ($this->withEpisodeNumber($source) as $row) {
                $rows[] = ['key' => $employees->get((int) $row->owner), 'reference' => (string) $row->episode_number];
            }
        }

        return $rows;
    }

    /** @return list<array{key: string, reference: string}> */
    private function referred(array $itemIds, Carbon $from, Carbon $to, ?string $onlyKey): array
    {
        $referrals = DB::table('patient_referrals')
            ->whereNotNull('patient_referrals.episode_id')
            ->where(function ($query): void {
                $query->where(fn ($q) => $q->where('source', ReferralSource::Employee->value)->whereNotNull('employee_id'))
                    ->orWhere(fn ($q) => $q->where('source', ReferralSource::Partner->value)->whereNotNull('partner_organization_id'));
            })
            ->when($onlyKey !== null, fn ($query) => str_starts_with($onlyKey, 'E')
                ? $query->where('employee_id', (int) substr($onlyKey, 1))
                : $query->where('partner_organization_id', (int) substr($onlyKey, 1)))
            ->get(['episode_id', 'employee_id', 'partner_organization_id']);

        if ($referrals->isEmpty()) {
            return [];
        }

        $eligibleEmployees = Employee::query()->with('jobTitle')->where('active', true)
            ->whereKey($referrals->pluck('employee_id')->filter()->unique()->all())->get()
            ->filter(fn (Employee $employee) => $employee->grantsBenefits())->modelKeys();
        $activePartners = DB::table('partner_organizations')->whereNull('deleted_at')->where('active', true)
            ->whereIn('id', $referrals->pluck('partner_organization_id')->filter()->unique()->all())->pluck('id')->all();

        $keyByEpisode = [];

        foreach ($referrals as $referral) {
            if ($referral->employee_id && in_array((int) $referral->employee_id, $eligibleEmployees, true)) {
                $keyByEpisode[(int) $referral->episode_id] = 'E'.$referral->employee_id;
            } elseif ($referral->partner_organization_id && in_array((int) $referral->partner_organization_id, $activePartners, true)) {
                $keyByEpisode[(int) $referral->episode_id] = 'P'.$referral->partner_organization_id;
            }
        }

        if ($keyByEpisode === []) {
            return [];
        }

        $items = DB::table('billable_items')
            ->join('episodes', 'episodes.id', '=', 'billable_items.episode_id')
            ->whereIn('billable_items.episode_id', array_keys($keyByEpisode))
            ->whereIn('billable_items.catalog_item_id', $itemIds)
            ->where('billable_items.status', '!=', BillableItemStatus::Cancelled->value)
            ->whereBetween('billable_items.created_at', [$from, $to])
            ->get(['billable_items.episode_id', 'billable_items.quantity', 'episodes.episode_number']);

        $rows = [];

        foreach ($items as $item) {
            // Une ligne facturée en quantité 2 compte deux actes.
            for ($i = 0; $i < max(1, (int) $item->quantity); $i++) {
                $rows[] = ['key' => $keyByEpisode[(int) $item->episode_id], 'reference' => (string) $item->episode_number];
            }
        }

        return $rows;
    }

    private function withEpisodeNumber(Builder $source): Collection
    {
        return DB::query()->fromSub($source, 'advantage_source')
            ->join('episodes', 'episodes.id', '=', 'advantage_source.episode_id')
            ->get(['advantage_source.owner', 'episodes.episode_number']);
    }
}
