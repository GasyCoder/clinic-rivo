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
    }
}
