<?php

namespace App\Support\Hr;

use App\Enums\HrReferenceType;
use Illuminate\Support\Facades\DB;

/**
 * ADR-194 — la proposition de départ qui relie chaque fonction livrée aux
 * départements où elle existe, lue sur le CDC §9 (« Fonctions internes ») et
 * sur les départements livrés. C'est une proposition : tout se corrige dans
 * Paramètres RH.
 *
 * Elle ne relie qu'une fonction qui n'a encore aucun département : une
 * correspondance déjà réglée par la clinique n'est jamais réécrite, et la
 * rejouer (migration, seeder) ne change rien.
 */
final class DefaultJobTitleDepartments
{
    /** @var array<string, array<int, string>> code de fonction => codes de département */
    public const MAP = [
        'ADMIN' => ['ADMINISTRATION'],
        'MANAGER' => ['ADMINISTRATION', 'TSARASHOP'],
        'DOCTOR' => ['MEDICINE', 'SURGERY', 'MATERNITY'],
        'GENERAL_NURSE' => ['MEDICINE', 'SURGERY', 'MATERNITY'],
        'MIDWIFE' => ['MATERNITY'],
        'ANESTHETIST_NURSE' => ['SURGERY'],
        'OPERATING_ROOM_NURSE' => ['SURGERY'],
        'LAB_TECHNICIAN' => ['LABORATORY'],
        'PHARMACIST' => ['PHARMACY'],
        'GUARD' => ['SUPPORT'],
        'HOUSEKEEPER' => ['SUPPORT'],
        'GARDENER' => ['SUPPORT'],
        'DRIVER' => ['SUPPORT'],
        'MAINTENANCE' => ['SUPPORT'],
        'LAUNDRY' => ['SUPPORT'],
        'WAITER' => ['TSARASHOP'],
        'DENTIST' => ['DENTISTRY'],
        'DENTAL_ASSISTANT' => ['DENTISTRY'],
    ];

    /** Relie les fonctions encore sans département ; renvoie le nombre de liens créés. */
    public static function apply(): int
    {
        $jobTitles = DB::table('hr_reference_values')
            ->where('type', HrReferenceType::JobTitle->value)
            ->whereIn('code', array_keys(self::MAP))
            ->pluck('id', 'code');
        $departments = DB::table('hr_reference_values')
            ->where('type', HrReferenceType::Department->value)
            ->pluck('id', 'code');
        $linked = DB::table('hr_job_title_departments')->distinct()->pluck('job_title_id')->all();
        $created = 0;

        foreach ($jobTitles as $code => $jobTitleId) {
            if (in_array($jobTitleId, $linked, true)) {
                continue;
            }

            foreach (self::MAP[$code] as $departmentCode) {
                if (! isset($departments[$departmentCode])) {
                    continue;
                }

                DB::table('hr_job_title_departments')->insert([
                    'job_title_id' => $jobTitleId,
                    'department_id' => $departments[$departmentCode],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $created++;
            }
        }

        return $created;
    }
}
