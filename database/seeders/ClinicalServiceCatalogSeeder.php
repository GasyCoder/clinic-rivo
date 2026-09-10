<?php

namespace Database\Seeders;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\CatalogTariffCategory;
use App\Enums\ReceptionRoutingMode;
use App\Models\CatalogItem;
use App\Models\User;
use App\Support\Money;
use App\Support\SurgeryReferenceData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ClinicalServiceCatalogSeeder extends Seeder
{
    /**
     * Provisional local data used to validate the Reception billing workflow.
     * Amounts are MGA and must be confirmed by the clinic before production.
     *
     * @var array<int, array{code: string, name: string, module: CatalogModule, unit: string, amount: ?int, mutual_amount?: ?int, description: string, reception_selectable: bool, routing_mode: ?ReceptionRoutingMode, billable?: bool, care_requires_allergy_check?: bool, care_recommends_vitals?: bool, clinician_orderable?: bool}>
     */
    private const SERVICES = [
        [
            'code' => 'CONSULT-GEN',
            'name' => 'Consultation de médecine générale',
            'module' => CatalogModule::Medicine,
            'unit' => 'consultation',
            'amount' => 20000,
            'description' => 'Consultation médicale générale.',
            'reception_selectable' => true,
            'routing_mode' => ReceptionRoutingMode::CareThenMedicine,
        ],
        [
            'code' => 'CONSULT-SPEC',
            'name' => 'Consultation spécialisée',
            'module' => CatalogModule::Medicine,
            'unit' => 'consultation',
            'amount' => 30000,
            'description' => 'Consultation auprès d’un médecin spécialiste.',
            'reception_selectable' => true,
            'routing_mode' => ReceptionRoutingMode::MedicineDirect,
        ],
        [
            'code' => 'ECG',
            'name' => 'Électrocardiogramme (ECG)',
            'module' => CatalogModule::Medicine,
            'unit' => 'examen',
            'amount' => 25000,
            'description' => 'Enregistrement de l’activité électrique du cœur.',
            'reception_selectable' => true,
            'routing_mode' => ReceptionRoutingMode::MedicineDirect,
        ],
        [
            'code' => 'ECHO-ABD',
            'name' => 'Échographie abdominale',
            'module' => CatalogModule::Medicine,
            'unit' => 'examen',
            'amount' => 50000,
            'description' => 'Examen échographique de la région abdominale.',
            'reception_selectable' => true,
            'routing_mode' => ReceptionRoutingMode::MedicineDirect,
        ],
        [
            'code' => 'ECHO-OBS',
            'name' => 'Échographie obstétricale',
            'module' => CatalogModule::Medicine,
            'unit' => 'examen',
            'amount' => 50000,
            'description' => 'Suivi échographique de la grossesse.',
            'reception_selectable' => true,
            'routing_mode' => ReceptionRoutingMode::MedicineDirect,
        ],
        [
            'code' => 'ECHO-PEL',
            'name' => 'Échographie pelvienne',
            'module' => CatalogModule::Medicine,
            'unit' => 'examen',
            'amount' => 50000,
            'description' => 'Examen échographique de la région pelvienne.',
            'reception_selectable' => true,
            'routing_mode' => ReceptionRoutingMode::MedicineDirect,
        ],
        [
            'code' => 'PANSEMENT-S',
            'name' => 'Pansement simple',
            'module' => CatalogModule::Care,
            'unit' => 'soin',
            'amount' => 10000,
            'description' => 'Nettoyage et pansement d’une plaie simple.',
            'reception_selectable' => true,
            'routing_mode' => ReceptionRoutingMode::CareOnly,
            'clinician_orderable' => true,
        ],
        [
            'code' => 'PANSEMENT-C',
            'name' => 'Gros pansement avec plaie souillée K=1',
            'module' => CatalogModule::Care,
            'unit' => 'soin',
            'amount' => 20000,
            'description' => 'Prise en charge et pansement d’une plaie complexe.',
            'reception_selectable' => true,
            'routing_mode' => ReceptionRoutingMode::CareOnly,
            'clinician_orderable' => true,
        ],
        [
            'code' => 'PANSEMENT-S-INT',
            'name' => 'Pansement simple interne',
            'module' => CatalogModule::Care,
            'unit' => 'soin',
            'amount' => null,
            'description' => 'Nettoyage et pansement d’une plaie simple interne, distincte de la variante externe.',
            'reception_selectable' => true,
            'routing_mode' => ReceptionRoutingMode::CareOnly,
            'clinician_orderable' => true,
        ],
        [
            'code' => 'PANSEMENT-C-INT',
            'name' => 'Pansement chirurgical interne',
            'module' => CatalogModule::Care,
            'unit' => 'soin',
            'amount' => null,
            'description' => 'Prise en charge et pansement d’une plaie chirurgicale interne, distincte de la variante externe.',
            'reception_selectable' => true,
            'routing_mode' => ReceptionRoutingMode::CareOnly,
            'clinician_orderable' => true,
        ],
        [
            'code' => 'INJECTION-IM',
            'name' => 'Injection IM',
            'module' => CatalogModule::Care,
            'unit' => 'soin',
            'amount' => 5000,
            'description' => 'Réalisation d’une injection par voie intramusculaire.',
            'reception_selectable' => true,
            'routing_mode' => ReceptionRoutingMode::CareOnly,
            'care_requires_allergy_check' => true,
            'clinician_orderable' => true,
        ],
        [
            'code' => 'PERFUSION',
            'name' => 'Pose sérum + surveillance',
            'module' => CatalogModule::Care,
            'unit' => 'soin',
            'amount' => 10000,
            'description' => 'Pose et surveillance initiale d’une perfusion.',
            'reception_selectable' => true,
            'routing_mode' => ReceptionRoutingMode::CareOnly,
            'care_requires_allergy_check' => true,
            'clinician_orderable' => true,
        ],
        [
            'code' => 'CARE-ABL-SONDE',
            'name' => 'Ablation sonde',
            'module' => CatalogModule::Care,
            'unit' => 'acte',
            'amount' => null,
            'description' => 'Retrait d’une sonde et surveillance infirmière.',
            'reception_selectable' => false,
            'routing_mode' => null,
            'clinician_orderable' => true,
        ],
        [
            'code' => 'CARE-CURET-LR-K1',
            'name' => 'Curetage de propreté sous anesthésie loco-régionale K=1',
            'module' => CatalogModule::Care,
            'unit' => 'acte',
            'amount' => null,
            'description' => 'Acte de curetage tracé sur la fiche de soins.',
            'reception_selectable' => false,
            'routing_mode' => null,
            'clinician_orderable' => true,
        ],
        [
            'code' => 'CARE-CURET-S-K1',
            'name' => 'Curetage simple de propreté K=1',
            'module' => CatalogModule::Care,
            'unit' => 'acte',
            'amount' => null,
            'description' => 'Acte de curetage simple tracé sur la fiche de soins.',
            'reception_selectable' => false,
            'routing_mode' => null,
            'clinician_orderable' => true,
        ],
        [
            'code' => 'INJECTION-IV',
            'name' => 'Injection IV',
            'module' => CatalogModule::Care,
            'unit' => 'soin',
            'amount' => null,
            'description' => 'Injection réalisée par voie intraveineuse.',
            'reception_selectable' => false,
            'routing_mode' => null,
            'care_requires_allergy_check' => true,
            'clinician_orderable' => true,
        ],
        [
            'code' => 'CARE-LAV-GAST',
            'name' => 'Lavage gastrique',
            'module' => CatalogModule::Care,
            'unit' => 'soin',
            'amount' => null,
            'description' => 'Lavage gastrique et surveillance associée.',
            'reception_selectable' => false,
            'routing_mode' => null,
            'clinician_orderable' => true,
        ],
        [
            'code' => 'CARE-LAV-SONDE-V',
            'name' => 'Lavage sonde vésicale',
            'module' => CatalogModule::Care,
            'unit' => 'soin',
            'amount' => null,
            'description' => 'Lavage d’une sonde vésicale.',
            'reception_selectable' => false,
            'routing_mode' => null,
            'clinician_orderable' => true,
        ],
        [
            'code' => 'CARE-POSE-SONDE',
            'name' => 'Pose sonde',
            'module' => CatalogModule::Care,
            'unit' => 'acte',
            'amount' => null,
            'description' => 'Pose d’une sonde et surveillance associée.',
            'reception_selectable' => false,
            'routing_mode' => null,
            'clinician_orderable' => true,
        ],
        [
            'code' => 'CARE-PRELEV-LAB',
            'name' => 'Prélèvement labo',
            'module' => CatalogModule::Care,
            'unit' => 'prélèvement',
            'amount' => null,
            'description' => 'Réalisation d’un prélèvement destiné au laboratoire.',
            'reception_selectable' => false,
            'routing_mode' => null,
        ],
        [
            'code' => 'CARE-REFECT-PLAIE-K1',
            'name' => 'Réfection chirurgicale de la plaie traumatique K=1',
            'module' => CatalogModule::Care,
            'unit' => 'acte',
            'amount' => null,
            'description' => 'Réfection d’une plaie traumatique tracée par l’équipe de soins.',
            'reception_selectable' => false,
            'routing_mode' => null,
            'clinician_orderable' => true,
        ],
        [
            'code' => 'CARE-NEBUL',
            'name' => 'Soins nébuliseur',
            'module' => CatalogModule::Care,
            'unit' => 'séance',
            'amount' => null,
            'description' => 'Séance de nébulisation et surveillance.',
            'reception_selectable' => false,
            'routing_mode' => null,
            'clinician_orderable' => true,
        ],
        [
            'code' => 'CARE-SUTURES',
            'name' => 'Sutures',
            'module' => CatalogModule::Care,
            'unit' => 'acte',
            'amount' => null,
            'description' => 'Réalisation et traçabilité de sutures.',
            'reception_selectable' => false,
            'routing_mode' => null,
            'clinician_orderable' => true,
        ],
        [
            'code' => 'CARE-O2-EXTRACT',
            'name' => 'Utilisation extracteur O₂',
            'module' => CatalogModule::Care,
            'unit' => 'séance',
            'amount' => null,
            'description' => 'Oxygénothérapie avec extracteur et surveillance.',
            'reception_selectable' => false,
            'routing_mode' => null,
            'clinician_orderable' => true,
        ],
        [
            'code' => 'CARE-ASPIRATION',
            'name' => 'Aspiration',
            'module' => CatalogModule::Care,
            'unit' => 'soin',
            'amount' => null,
            'description' => 'Acte d’aspiration et surveillance associée.',
            'reception_selectable' => false,
            'routing_mode' => null,
            'clinician_orderable' => true,
        ],
        [
            'code' => 'CARE-OTHER',
            'name' => 'Autres',
            'module' => CatalogModule::Care,
            'unit' => 'acte',
            'amount' => null,
            'description' => 'Autre acte infirmier, à préciser obligatoirement dans la fiche.',
            'reception_selectable' => false,
            'routing_mode' => null,
            'billable' => false,
        ],
        [
            'code' => 'LAB-NFS',
            'name' => 'Numération formule sanguine (NFS)',
            'module' => CatalogModule::Laboratory,
            'unit' => 'analyse',
            'amount' => 15000,
            'description' => 'Analyse hématologique de type NFS.',
            'reception_selectable' => true,
            'routing_mode' => ReceptionRoutingMode::LaboratoryDirect,
        ],
        [
            'code' => 'LAB-GLYC',
            'name' => 'Glycémie',
            'module' => CatalogModule::Laboratory,
            'unit' => 'analyse',
            'amount' => 10000,
            'description' => 'Dosage du glucose sanguin.',
            'reception_selectable' => true,
            'routing_mode' => ReceptionRoutingMode::LaboratoryDirect,
        ],
        [
            'code' => 'LAB-GROUP-RH',
            'name' => 'Groupage sanguin et rhésus',
            'module' => CatalogModule::Laboratory,
            'unit' => 'analyse',
            'amount' => 15000,
            'description' => 'Détermination du groupe sanguin et du facteur rhésus.',
            'reception_selectable' => true,
            'routing_mode' => ReceptionRoutingMode::LaboratoryDirect,
        ],
        [
            'code' => 'LAB-TDR-PALU',
            'name' => 'Test rapide du paludisme',
            'module' => CatalogModule::Laboratory,
            'unit' => 'analyse',
            'amount' => 10000,
            'description' => 'Test de diagnostic rapide du paludisme.',
            'reception_selectable' => true,
            'routing_mode' => ReceptionRoutingMode::LaboratoryDirect,
        ],
        [
            'code' => 'CONSULT-CHIR',
            'name' => 'Consultation chirurgicale',
            'module' => CatalogModule::Surgery,
            'unit' => 'consultation',
            'amount' => 30000,
            'description' => 'Évaluation clinique par l’équipe de chirurgie.',
            'reception_selectable' => false,
            'routing_mode' => null,
        ],
        [
            'code' => 'PETITE-CHIR',
            'name' => 'Acte de petite chirurgie',
            'module' => CatalogModule::Surgery,
            'unit' => 'acte',
            'amount' => 50000,
            'description' => 'Acte chirurgical mineur réalisé selon indication médicale.',
            'reception_selectable' => false,
            'routing_mode' => null,
        ],
        [
            'code' => 'MAT-CONSULT-PRENATAL', 'name' => 'Consultation prénatale',
            'module' => CatalogModule::Maternity, 'unit' => 'consultation', 'amount' => null,
            'description' => 'Première consultation de suivi prénatal.', 'reception_selectable' => true,
            'routing_mode' => ReceptionRoutingMode::MaternityDirect, 'clinician_orderable' => true,
        ],
        [
            'code' => 'MAT-CONSULT-PRENATAL-SUIVI', 'name' => 'Consultation prénatale de suivi',
            'module' => CatalogModule::Maternity, 'unit' => 'consultation', 'amount' => null,
            'description' => 'Consultation de suivi prénatal ultérieure, distincte de la première consultation.',
            'reception_selectable' => true,
            'routing_mode' => ReceptionRoutingMode::MaternityDirect, 'clinician_orderable' => true,
        ],
        [
            'code' => 'MAT-DELIVERY-SIMPLE', 'name' => 'Accouchement simple',
            'module' => CatalogModule::Maternity, 'unit' => 'accouchement', 'amount' => null,
            'description' => 'Prise en charge d’un accouchement simple.', 'reception_selectable' => true,
            'routing_mode' => ReceptionRoutingMode::MaternityDirect,
        ],
        [
            'code' => 'MAT-DELIVERY-TWIN', 'name' => 'Accouchement gémellaire',
            'module' => CatalogModule::Maternity, 'unit' => 'accouchement', 'amount' => null,
            'description' => 'Prise en charge d’un accouchement gémellaire.', 'reception_selectable' => true,
            'routing_mode' => ReceptionRoutingMode::MaternityDirect,
        ],
        [
            'code' => 'MAT-CESAREAN-SIMPLE', 'name' => 'Opération Césarienne Simple',
            'module' => CatalogModule::Maternity, 'unit' => 'orientation', 'amount' => null,
            'description' => 'Référence de décision Maternité ; l’intervention est exclusivement réalisée dans Chirurgie.',
            'reception_selectable' => false, 'routing_mode' => null,
        ],
        [
            'code' => 'MAT-CESAREAN-TWIN', 'name' => 'Opération Césarienne Gémellaire',
            'module' => CatalogModule::Maternity, 'unit' => 'orientation', 'amount' => null,
            'description' => 'Référence de décision Maternité ; l’intervention est exclusivement réalisée dans Chirurgie.',
            'reception_selectable' => false, 'routing_mode' => null,
        ],
        [
            'code' => 'MAT-IUD-INSERT', 'name' => 'Insertion DIU',
            'module' => CatalogModule::Maternity, 'unit' => 'acte', 'amount' => null,
            'description' => 'Insertion d’un dispositif intra-utérin.', 'reception_selectable' => true, 'routing_mode' => ReceptionRoutingMode::MaternityDirect,
        ],
        [
            'code' => 'MAT-IUD-REMOVE', 'name' => 'Retrait DIU',
            'module' => CatalogModule::Maternity, 'unit' => 'acte', 'amount' => null,
            'description' => 'Retrait d’un dispositif intra-utérin.', 'reception_selectable' => true, 'routing_mode' => ReceptionRoutingMode::MaternityDirect,
        ],
        [
            'code' => 'MAT-IMPLANON-INSERT', 'name' => 'Insertion Implanon',
            'module' => CatalogModule::Maternity, 'unit' => 'acte', 'amount' => null,
            'description' => 'Insertion d’un implant contraceptif.', 'reception_selectable' => true, 'routing_mode' => ReceptionRoutingMode::MaternityDirect,
        ],
        [
            'code' => 'MAT-IMPLANON-REMOVE', 'name' => 'Retrait Implanon',
            'module' => CatalogModule::Maternity, 'unit' => 'acte', 'amount' => null,
            'description' => 'Retrait d’un implant contraceptif.', 'reception_selectable' => true, 'routing_mode' => ReceptionRoutingMode::MaternityDirect,
        ],
        [
            'code' => 'MAT-UMBILICAL-DRESSING', 'name' => 'Pansement ombilical',
            'module' => CatalogModule::Maternity, 'unit' => 'soin', 'amount' => null,
            'description' => 'Soin et pansement ombilical du nouveau-né.', 'reception_selectable' => true, 'routing_mode' => ReceptionRoutingMode::MaternityDirect,
        ],
        [
            'code' => 'MAT-BABY-WEIGHT', 'name' => 'Pèse bébé',
            'module' => CatalogModule::Maternity, 'unit' => 'acte', 'amount' => null,
            'description' => 'Mesure et traçabilité du poids du bébé.', 'reception_selectable' => true, 'routing_mode' => ReceptionRoutingMode::MaternityDirect,
        ],
        [
            'code' => 'MAT-BABY-CARE', 'name' => 'Soins bébé',
            'module' => CatalogModule::Maternity, 'unit' => 'soin', 'amount' => null,
            'description' => 'Soins courants réalisés au nouveau-né.', 'reception_selectable' => true, 'routing_mode' => ReceptionRoutingMode::MaternityDirect,
        ],
        [
            'code' => 'MAT-DOPPLER', 'name' => 'Doppler',
            'module' => CatalogModule::Maternity, 'unit' => 'examen', 'amount' => null,
            'description' => 'Surveillance Doppler en maternité.', 'reception_selectable' => true, 'routing_mode' => ReceptionRoutingMode::MaternityDirect,
        ],
        [
            'code' => 'MAT-PHOTOTHERAPY', 'name' => 'Photothérapie',
            'module' => CatalogModule::Maternity, 'unit' => 'séance', 'amount' => null,
            'description' => 'Séance de photothérapie du nouveau-né.', 'reception_selectable' => true, 'routing_mode' => ReceptionRoutingMode::MaternityDirect,
        ],
        [
            'code' => 'MAT-OTHER', 'name' => 'Autres',
            'module' => CatalogModule::Maternity, 'unit' => 'acte', 'amount' => null,
            'description' => 'Autre acte de maternité, à préciser.', 'reception_selectable' => false,
            'routing_mode' => null, 'billable' => false,
        ],
        [
            'code' => 'FP-INJECTABLE', 'name' => 'Contraceptif injectable (Sayana Press / Depo-Provera)',
            'module' => CatalogModule::FamilyPlanning, 'unit' => 'acte', 'amount' => null,
            'description' => 'Administration d’un contraceptif injectable trimestriel.',
            'reception_selectable' => false, 'routing_mode' => null,
        ],
        [
            'code' => 'FP-PILPLAN', 'name' => 'Pilplan',
            'module' => CatalogModule::FamilyPlanning, 'unit' => 'boîte', 'amount' => null,
            'description' => 'Délivrance de contraceptif oral Pilplan.',
            'reception_selectable' => false, 'routing_mode' => null,
        ],
        [
            'code' => 'OPHT-CONSULT', 'name' => 'Consultation ophtalmologique',
            'module' => CatalogModule::Ophthalmology, 'unit' => 'consultation', 'amount' => null,
            'description' => 'Consultation d’ophtalmologie.',
            'reception_selectable' => false, 'routing_mode' => null,
        ],
        [
            'code' => 'OPHT-LUNETTE-T1', 'name' => 'Lunette Type 1',
            'module' => CatalogModule::Ophthalmology, 'unit' => 'paire', 'amount' => null,
            'description' => 'Monture et verres correcteurs, type 1.',
            'reception_selectable' => false, 'routing_mode' => null,
        ],
        [
            'code' => 'OPHT-LUNETTE-T2', 'name' => 'Lunette Type 2',
            'module' => CatalogModule::Ophthalmology, 'unit' => 'paire', 'amount' => null,
            'description' => 'Monture et verres correcteurs, type 2.',
            'reception_selectable' => false, 'routing_mode' => null,
        ],
        [
            'code' => 'OPHT-LUNETTE-T3', 'name' => 'Lunette Type 3',
            'module' => CatalogModule::Ophthalmology, 'unit' => 'paire', 'amount' => null,
            'description' => 'Monture et verres correcteurs, type 3.',
            'reception_selectable' => false, 'routing_mode' => null,
        ],
        [
            'code' => 'OPHT-LUNETTE-T4', 'name' => 'Lunette Type 4',
            'module' => CatalogModule::Ophthalmology, 'unit' => 'paire', 'amount' => null,
            'description' => 'Monture et verres correcteurs, type 4.',
            'reception_selectable' => false, 'routing_mode' => null,
        ],
    ];

    /**
     * Real tariffs confirmed by the owner from the physical "Prestations et
     * Tarifs" price sheet (Clinique Saint Georges). Every other surgical
     * procedure intentionally keeps amount => null: its price on the sheet
     * was either illegible, ambiguous (handwritten correction), or simply
     * not yet transcribed — the clinic confirms it later via the UI.
     *
     * @var array<string, array{amount: ?int, mutual_amount: ?int}>
     */
    private const SURGICAL_TARIFFS = [
        'SURG-CESARIENNE' => ['amount' => 650000, 'mutual_amount' => 800000],
        'SURG-CERCLAGE' => ['amount' => 300000, 'mutual_amount' => null],
        'SURG-LAPAROTOMIE' => ['amount' => 900000, 'mutual_amount' => null],
        'SURG-TRAUMA-ZEBU' => ['amount' => 800000, 'mutual_amount' => null],
        'SURG-BLESSURE-BALLE' => ['amount' => 400000, 'mutual_amount' => null],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'ClinicalServiceCatalogSeeder est réservé aux environnements local et testing.',
            );
        }

        $actor = $this->resolveActor();
        $guard = Auth::guard();
        $previousActor = $guard->user();
        $guard->setUser($actor);

        $created = 0;
        $tariffsCreated = 0;
        $preserved = 0;
        $withoutTariff = 0;

        try {
            DB::transaction(function () use (
                $actor,
                &$created,
                &$tariffsCreated,
                &$preserved,
                &$withoutTariff,
            ): void {
                foreach (self::services() as $service) {
                    $existing = CatalogItem::withTrashed()
                        ->where('code', $service['code'])
                        ->first();

                    if ($existing?->trashed()) {
                        throw new RuntimeException(
                            "La désignation {$service['code']} est archivée. Restaurez-la explicitement avant le seeding.",
                        );
                    }

                    if ($existing) {
                        $this->assertCompatible($existing, $service);
                        $this->applyLegacyCareLabel($existing, $service);
                        $this->applyInitialReceptionRouteIfUnset($existing, $service);
                        $this->applyAcceptedRoutingCorrection($existing, $service, $actor);
                        $this->applyClinicianOrderableIfUnset($existing, $service, $actor);

                        $item = $existing;
                    } else {
                        $item = CatalogItem::query()->create([
                            'code' => $service['code'],
                            'name' => $service['name'],
                            'type' => CatalogItemType::Service->value,
                            'module' => $service['module']->value,
                            'unit' => $service['unit'],
                            'billable' => $service['billable'] ?? true,
                            'stockable' => false,
                            'reception_selectable' => $service['reception_selectable'],
                            'reception_routing_mode' => $service['routing_mode'],
                            'care_requires_allergy_check' => $service['care_requires_allergy_check'] ?? false,
                            'care_recommends_vitals' => $service['care_recommends_vitals'] ?? false,
                            'clinician_orderable' => $service['clinician_orderable'] ?? false,
                            'description' => $service['description'],
                            'created_by' => $actor->id,
                            'updated_by' => $actor->id,
                        ]);
                        $created++;
                    }

                    $this->syncTariff(
                        $item, CatalogTariffCategory::Standard, $service['amount'], $actor,
                        $preserved, $withoutTariff, $tariffsCreated,
                    );
                    $this->syncTariff(
                        $item, CatalogTariffCategory::Mutual, $service['mutual_amount'] ?? null, $actor,
                        $preserved, $withoutTariff, $tariffsCreated,
                    );
                }
            });
        } finally {
            if ($previousActor) {
                $guard->setUser($previousActor);
            } else {
                $guard->forgetUser();
            }
        }

        $this->command?->info(sprintf(
            '%d désignations disponibles : %d créées, %d tarifs ajoutés, %d tarifs existants conservés, %d en attente de tarif.',
            count(self::services()),
            $created,
            $tariffsCreated,
            $preserved,
            $withoutTariff,
        ));
    }

    /** @return array<int, array<string, mixed>> */
    private static function services(): array
    {
        $surgicalProcedures = array_map(function (array $procedure): array {
            $tariff = self::SURGICAL_TARIFFS[$procedure['code']] ?? ['amount' => null, 'mutual_amount' => null];

            return [
                'code' => $procedure['code'],
                'name' => $procedure['name'],
                'module' => CatalogModule::Surgery,
                'unit' => 'intervention',
                'amount' => $tariff['amount'],
                'mutual_amount' => $tariff['mutual_amount'],
                'description' => $procedure['code'] === 'SURG-OTHER'
                    ? 'Autre intervention chirurgicale, à préciser obligatoirement dans le dossier.'
                    : 'Intervention chirurgicale issue du référentiel validé par la clinique.',
                'reception_selectable' => false,
                'routing_mode' => null,
                'billable' => $procedure['code'] !== 'SURG-OTHER',
            ];
        }, SurgeryReferenceData::procedures());

        return [...self::SERVICES, ...$surgicalProcedures];
    }

    private function resolveActor(): User
    {
        $identity = trim((string) config('rivo.seeders.catalog_actor'));

        if ($identity !== '') {
            $actor = User::query()
                ->where(fn ($query) => $query
                    ->where('uuid', $identity)
                    ->orWhere('email', $identity))
                ->first();

            if (! $actor) {
                throw new RuntimeException('Le compte configuré pour le seeding du catalogue est introuvable.');
            }

            $this->assertProvisioningActor($actor);

            return $actor;
        }

        $actor = User::query()
            ->with('role')
            ->where('active', true)
            ->whereNull('deactivated_at')
            ->orderBy('id')
            ->get()
            ->first(fn (User $user) => $this->canSeedCatalog($user));

        if (! $actor) {
            throw new RuntimeException(
                'Aucun compte actif ne possède les permissions requises pour provisionner le référentiel '.
                '(catalog.items.create, catalog.items.update, catalog.tariffs.create). '.
                'Définissez RIVO_CATALOG_SEED_ACTOR avec l’UUID ou l’email du compte actif à enregistrer comme auteur du provisioning.',
            );
        }

        return $actor;
    }

    private function assertProvisioningActor(User $actor): void
    {
        if (! $actor->isActive() || ! $actor->role_id) {
            throw new RuntimeException(
                'Le compte configuré comme auteur du provisioning doit être actif et posséder un rôle local.',
            );
        }

        $missingPermissions = collect([
            'catalog.items.create',
            'catalog.items.update',
            'catalog.tariffs.create',
        ])->reject(fn (string $permission): bool => $actor->hasPermissionTo($permission));

        if ($missingPermissions->isNotEmpty()) {
            throw new RuntimeException(
                'Le compte configuré par RIVO_CATALOG_SEED_ACTOR n’est pas autorisé à provisionner le catalogue. '.
                'Permissions manquantes : '.$missingPermissions->implode(', ').'.',
            );
        }
    }

    private function canSeedCatalog(User $actor): bool
    {
        return $actor->hasPermissionTo('catalog.items.create')
            && $actor->hasPermissionTo('catalog.items.update')
            && $actor->hasPermissionTo('catalog.tariffs.create');
    }

    /** @param array{billable?: bool} $service */
    private function assertCompatible(CatalogItem $item, array $service): void
    {
        if (
            $item->type !== CatalogItemType::Service
            || $item->billable !== ($service['billable'] ?? true)
            || $item->stockable
        ) {
            throw new RuntimeException(
                "Le code {$item->code} existe avec une configuration incompatible ; aucune donnée n’a été écrasée.",
            );
        }
    }

    /**
     * Rename only labels created by an older version of this same local
     * seeder. An administrator-customized label is never overwritten.
     *
     * @param  array{name: string}  $service
     */
    private function applyLegacyCareLabel(CatalogItem $item, array $service): void
    {
        $legacyLabels = [
            'PANSEMENT-C' => 'Pansement complexe',
            'INJECTION-IM' => 'Injection intramusculaire',
            'PERFUSION' => 'Pose de perfusion',
        ];

        if (($legacyLabels[$item->code] ?? null) !== $item->name) {
            return;
        }

        $item->forceFill(['name' => $service['name']])->save();
    }

    /**
     * Existing local seed data predates reception routing. Backfill only an
     * entirely unconfigured route; never overwrite a route already chosen by
     * an administrator.
     *
     * @param  array{reception_selectable: bool, routing_mode: ?ReceptionRoutingMode}  $service
     */
    private function applyInitialReceptionRouteIfUnset(CatalogItem $item, array $service): void
    {
        if (! $service['reception_selectable']
            || $item->reception_selectable
            || $item->reception_routing_mode !== null) {
            return;
        }

        $item->forceFill([
            'reception_selectable' => true,
            'reception_routing_mode' => $service['routing_mode'],
        ])->save();
    }

    /**
     * The initial local fixture routed specialist consultations through Care.
     * The client later confirmed that a known specialist consultation goes
     * directly to Medicine. Correct only the former Care-then-Medicine value;
     * other configured routes remain untouched. Existing episode requests
     * keep their routing snapshot unchanged.
     *
     * @param  array{code: string, routing_mode: ?ReceptionRoutingMode}  $service
     */
    private function applyAcceptedRoutingCorrection(CatalogItem $item, array $service, User $actor): void
    {
        if ($service['code'] !== 'CONSULT-SPEC'
            || $item->reception_routing_mode !== ReceptionRoutingMode::CareThenMedicine
            || $service['routing_mode'] !== ReceptionRoutingMode::MedicineDirect) {
            return;
        }

        $item->forceFill([
            'reception_routing_mode' => ReceptionRoutingMode::MedicineDirect,
            'updated_by' => $actor->id,
        ])->save();
    }

    /**
     * ADR-055: clinician_orderable is a deliberate, explicit flag — never
     * deduced from the name/code. Backfill only an item still at its unset
     * default (false); never flip one already true back off. Like the
     * routing backfill above, this can't distinguish "never configured"
     * from "explicitly disabled" — the same accepted limitation.
     *
     * @param  array{clinician_orderable?: bool}  $service
     */
    private function applyClinicianOrderableIfUnset(CatalogItem $item, array $service, User $actor): void
    {
        if (! ($service['clinician_orderable'] ?? false) || $item->clinician_orderable) {
            return;
        }

        $item->forceFill([
            'clinician_orderable' => true,
            'updated_by' => $actor->id,
        ])->save();
    }

    /**
     * ADR-031: STANDARD and MUTUAL are independent price lists. Each is
     * synced separately so an existing STANDARD tariff is never touched by
     * this same pass over the MUTUAL category, and vice versa.
     */
    private function syncTariff(
        CatalogItem $item,
        CatalogTariffCategory $category,
        ?int $amount,
        User $actor,
        int &$preserved,
        int &$withoutTariff,
        int &$tariffsCreated,
    ): void {
        if ($item->currentTariffFor($category)->exists()) {
            $preserved++;

            return;
        }

        if ($amount === null) {
            $withoutTariff++;

            return;
        }

        $this->createTariff($item, $amount, $actor, $category);
        $tariffsCreated++;
    }

    private function createTariff(
        CatalogItem $item,
        int $amount,
        User $actor,
        CatalogTariffCategory $category = CatalogTariffCategory::Standard,
    ): void {
        $item->tariffs()->create([
            'tariff_category' => $category->value,
            'amount' => Money::normalize($amount),
            'currency' => 'MGA',
            'effective_from' => now(),
            'active_key' => 'CURRENT',
            'change_reason' => 'Tarif initial de validation locale à confirmer par la clinique.',
            'created_by' => $actor->id,
        ]);
    }
}
