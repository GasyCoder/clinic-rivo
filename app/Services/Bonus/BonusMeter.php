<?php

namespace App\Services\Bonus;

use App\Enums\BonusMeasure;
use App\Enums\ConsultationStatus;
use App\Enums\ReferralSource;
use App\Models\Employee;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ADR-212 — combien de patients distincts chaque membre du personnel compte,
 * sur un mois, pour une mesure de bonus.
 *
 * Chaque mesure lit un fait déjà enregistré et attribué à une personne ; un
 * même patient ne compte qu'une fois dans le mois, quel que soit le nombre de
 * ses actes. Les patients recommandés se lisent sur la fiche de l'employé ; les
 * patients soignés sur son compte de connexion (ADR-188) : sans compte relié,
 * l'employé n'en a aucun. Une requête par mesure, pour tous les employés.
 */
class BonusMeter
{
    /**
     * @param  Collection<int, Employee>  $employees
     * @return array<int, list<array{patient_id: int, patient_number: string}>> par identifiant d'employé
     */
    public function patients(BonusMeasure $measure, Collection $employees, Carbon $month): array
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();
        $result = $employees->mapWithKeys(fn (Employee $employee) => [$employee->getKey() => []])->all();

        if ($employees->isEmpty()) {
            return $result;
        }

        if ($measure === BonusMeasure::ReferredPatients) {
            $rows = DB::table('patient_referrals')
                ->join('patients', 'patients.id', '=', 'patient_referrals.patient_id')
                ->where('patient_referrals.source', ReferralSource::Employee->value)
                ->whereIn('patient_referrals.employee_id', $employees->map(fn (Employee $employee) => $employee->getKey())->all())
                ->whereBetween('patient_referrals.referred_at', [$from, $to])
                ->distinct()
                ->get(['patient_referrals.employee_id as owner', 'patients.id as patient_id', 'patients.patient_number']);

            return $this->group($rows, $result, fn (int $owner) => $owner);
        }

        $employeeByUser = $employees->filter(fn (Employee $employee) => $employee->user_id !== null)
            ->mapWithKeys(fn (Employee $employee) => [$employee->user_id => $employee->getKey()]);

        if ($employeeByUser->isEmpty()) {
            return $result;
        }

        $rows = $this->treated($measure, $employeeByUser->keys()->all(), $from, $to)
            ->join('episodes', 'episodes.id', '=', 'bonus_source.episode_id')
            ->join('patients', 'patients.id', '=', 'episodes.patient_id')
            ->distinct()
            ->get(['bonus_source.owner', 'patients.id as patient_id', 'patients.patient_number']);

        return $this->group($rows, $result, fn (int $owner) => $employeeByUser->get($owner));
    }

    /** Les faits attribués aux comptes, ramenés à (compte, passage) : `bonus_source.owner`, `bonus_source.episode_id`. */
    private function treated(BonusMeasure $measure, array $userIds, Carbon $from, Carbon $to): Builder
    {
        $source = match ($measure) {
            BonusMeasure::Consultations => DB::table('consultations')
                ->where('status', ConsultationStatus::Completed->value)
                ->whereNull('deleted_at')
                ->whereIn('doctor_id', $userIds)
                ->whereBetween('completed_at', [$from, $to])
                ->select(['doctor_id as owner', 'episode_id']),
            BonusMeasure::Surgeries => DB::table('surgical_interventions')
                ->join('surgical_requests', 'surgical_requests.id', '=', 'surgical_interventions.surgical_request_id')
                ->whereIn('surgical_interventions.performed_by', $userIds)
                ->whereBetween('surgical_interventions.ended_at', [$from, $to])
                ->select(['surgical_interventions.performed_by as owner', 'surgical_requests.episode_id']),
            BonusMeasure::LabResults => DB::table('lab_request_items')
                ->join('lab_requests', 'lab_requests.id', '=', 'lab_request_items.lab_request_id')
                ->whereNull('lab_requests.cancelled_at')
                ->whereIn('lab_request_items.resulted_by', $userIds)
                ->whereBetween('lab_request_items.resulted_at', [$from, $to])
                ->select(['lab_request_items.resulted_by as owner', 'lab_requests.episode_id']),
            BonusMeasure::ImagingResults => DB::table('imaging_request_items')
                ->join('imaging_requests', 'imaging_requests.id', '=', 'imaging_request_items.imaging_request_id')
                ->whereNull('imaging_requests.cancelled_at')
                ->whereIn('imaging_request_items.resulted_by', $userIds)
                ->whereBetween('imaging_request_items.resulted_at', [$from, $to])
                ->select(['imaging_request_items.resulted_by as owner', 'imaging_requests.episode_id']),
            BonusMeasure::CareActs => DB::table('care_record_procedures')
                ->join('care_records', 'care_records.id', '=', 'care_record_procedures.care_record_id')
                ->whereIn('care_record_procedures.performed_by', $userIds)
                ->whereBetween('care_record_procedures.performed_at', [$from, $to])
                ->select(['care_record_procedures.performed_by as owner', 'care_records.episode_id']),
            BonusMeasure::MaternityActs => DB::table('maternity_procedures')
                ->join('maternity_records', 'maternity_records.id', '=', 'maternity_procedures.maternity_record_id')
                ->whereNull('maternity_procedures.deleted_at')
                ->whereIn('maternity_procedures.performed_by', $userIds)
                ->whereBetween('maternity_procedures.performed_at', [$from, $to])
                ->select(['maternity_procedures.performed_by as owner', 'maternity_records.episode_id']),
            BonusMeasure::ReferredPatients => throw new \LogicException('Les patients recommandés ne passent pas par un compte.'),
        };

        return DB::query()->fromSub($source, 'bonus_source');
    }

    /**
     * @param  array<int, list<array{patient_id: int, patient_number: string}>>  $result
     * @return array<int, list<array{patient_id: int, patient_number: string}>>
     */
    private function group(Collection $rows, array $result, callable $employeeOf): array
    {
        foreach ($rows as $row) {
            $employeeId = $employeeOf((int) $row->owner);

            if ($employeeId === null || ! array_key_exists($employeeId, $result)) {
                continue;
            }

            $result[$employeeId][$row->patient_id] = ['patient_id' => (int) $row->patient_id, 'patient_number' => (string) $row->patient_number];
        }

        return array_map(fn (array $patients) => array_values($patients), $result);
    }
}
