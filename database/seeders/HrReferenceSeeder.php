<?php

namespace Database\Seeders;

use App\Enums\HrReferenceType;
use App\Models\HrReferenceValue;
use Illuminate\Database\Seeder;

class HrReferenceSeeder extends Seeder
{
    /** @var array<string, array<string, string>> */
    private const REFERENCES = [
        'DEPARTMENT' => [
            'MEDICINE' => 'Médecine',
            'SURGERY' => 'Chirurgie',
            'LABORATORY' => 'Laboratoire',
            'PHARMACY' => 'Pharmacie',
            'ADMINISTRATION' => 'Administration',
            'MATERNITY' => 'Maternité',
            'SUPPORT' => 'Support',
            'TSARASHOP' => 'Tsarashop',
            'DENTISTRY' => 'Dentisterie',
        ],
        'JOB_TITLE' => [
            'ADMIN' => 'Admin',
            'DOCTOR' => 'Médecin',
            'GENERAL_NURSE' => 'Infirmier généraliste',
            'MIDWIFE' => 'Sage-femme',
            'ANESTHETIST_NURSE' => 'Infirmier Anesthésiste',
            'OPERATING_ROOM_NURSE' => 'Infirmier de bloc',
            'LAB_TECHNICIAN' => 'Laborantin',
            'GUARD' => 'Gardien',
            'HOUSEKEEPER' => 'Servante',
            'GARDENER' => 'Jardinier',
            'DRIVER' => 'Chauffeur',
            'MAINTENANCE' => 'Maintenance',
            'PHARMACIST' => 'Pharmacien',
            'LAUNDRY' => 'Lingerie',
            'WAITER' => 'Serveur',
            'MANAGER' => 'Gérant',
            'DENTIST' => 'Dentiste',
            'DENTAL_ASSISTANT' => 'Assistant Dentisterie',
        ],
        'CONTRACT_TYPE' => [
            'CDI' => 'CDI',
            'CDD' => 'CDD',
            'CONSULTANT' => 'Consultant',
            'INTERN' => 'Stagiaire',
            'VOLUNTEER' => 'Bénévole',
        ],
    ];

    /** @var array<string, array{label: string, metadata: array<string, mixed>}> */
    private const LEAVE_TYPES = [
        'ANNUAL_LEAVE' => [
            'label' => 'Congé annuel',
            'metadata' => ['consumes_annual_balance' => true, 'annual_quota_days' => 30, 'max_days_per_request' => null, 'requires_attachment' => false, 'requires_approval' => true, 'day_count_method' => 'CALENDAR_DAYS_INCLUSIVE'],
        ],
        'PERMISSION' => [
            'label' => 'Permission',
            'metadata' => ['consumes_annual_balance' => false, 'annual_quota_days' => null, 'max_days_per_request' => null, 'requires_attachment' => false, 'requires_approval' => true, 'day_count_method' => 'CALENDAR_DAYS_INCLUSIVE'],
        ],
        'SICK_LEAVE' => [
            'label' => 'Congé maladie',
            'metadata' => ['consumes_annual_balance' => false, 'annual_quota_days' => null, 'max_days_per_request' => null, 'requires_attachment' => true, 'requires_approval' => true, 'day_count_method' => 'CALENDAR_DAYS_INCLUSIVE'],
        ],
        'EXCEPTIONAL_LEAVE' => [
            'label' => 'Congé exceptionnel',
            'metadata' => ['consumes_annual_balance' => false, 'annual_quota_days' => null, 'max_days_per_request' => null, 'requires_attachment' => false, 'requires_approval' => true, 'day_count_method' => 'CALENDAR_DAYS_INCLUSIVE'],
        ],
        'MATERNITY_LEAVE' => [
            'label' => 'Congé maternité',
            'metadata' => ['consumes_annual_balance' => false, 'annual_quota_days' => null, 'max_days_per_request' => null, 'requires_attachment' => true, 'requires_approval' => true, 'day_count_method' => 'CALENDAR_DAYS_INCLUSIVE'],
        ],
        'UNPAID_LEAVE' => [
            'label' => 'Congé sans solde',
            'metadata' => ['consumes_annual_balance' => false, 'annual_quota_days' => null, 'max_days_per_request' => null, 'requires_attachment' => false, 'requires_approval' => true, 'day_count_method' => 'CALENDAR_DAYS_INCLUSIVE'],
        ],
        'OTHER' => [
            'label' => 'Autre',
            'metadata' => ['consumes_annual_balance' => false, 'annual_quota_days' => null, 'max_days_per_request' => null, 'requires_attachment' => false, 'requires_approval' => true, 'day_count_method' => 'CALENDAR_DAYS_INCLUSIVE'],
        ],
    ];

    public function run(): void
    {
        foreach (self::REFERENCES as $type => $references) {
            foreach (array_values($references) as $position => $label) {
                $code = array_search($label, $references, true);

                HrReferenceValue::withTrashed()->updateOrCreate(
                    ['type' => HrReferenceType::from($type), 'code' => $code],
                    ['label' => $label, 'position' => $position, 'active' => true],
                );
            }
        }

        foreach (array_values(self::LEAVE_TYPES) as $position => $definition) {
            $code = array_search($definition, self::LEAVE_TYPES, true);

            HrReferenceValue::withTrashed()->updateOrCreate(
                ['type' => HrReferenceType::LeaveType, 'code' => $code],
                ['label' => $definition['label'], 'metadata' => $definition['metadata'], 'position' => $position, 'active' => true],
            );
        }
    }
}
