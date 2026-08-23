<?php

namespace Database\Seeders;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\MedicineForm;
use App\Enums\PharmacyStockMovementType;
use App\Models\CatalogItem;
use App\Models\Medicine;
use App\Models\MedicineLot;
use App\Models\PharmacyStockMovement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

class DevelopmentMedicineStockSeeder extends Seeder
{
    /**
     * Local fixtures used to exercise every client-provided pharmaceutical
     * form as well as available, low/near-expiry and exhausted states.
     * No price is invented here: tariffs remain a separate Super Admin job.
     *
     * @var array<int, array<string, mixed>>
     */
    private const MEDICINES = [
        ['code' => 'DEV-PARA-500', 'name' => 'Paracétamol 500 mg', 'generic' => 'Paracétamol', 'form' => MedicineForm::Tablet, 'strength' => '500 mg', 'unit' => 'comprimé', 'quantity' => 120, 'expiry_months' => 18],
        ['code' => 'DEV-CEFTRI-1G', 'name' => 'Ceftriaxone 1 g', 'generic' => 'Ceftriaxone', 'form' => MedicineForm::Injectable, 'strength' => '1 g', 'unit' => 'flacon', 'quantity' => 24, 'expiry_months' => 12],
        ['code' => 'DEV-COLLYRE', 'name' => 'Collyre antiseptique', 'generic' => null, 'form' => MedicineForm::OralAmpouleEyeDropsDrops, 'strength' => null, 'unit' => 'flacon', 'quantity' => 18, 'expiry_months' => 9],
        ['code' => 'DEV-POVIDONE', 'name' => 'Solution antiseptique', 'generic' => 'Povidone iodée', 'form' => MedicineForm::Liquid, 'strength' => '10 %', 'unit' => 'flacon', 'quantity' => 12, 'expiry_months' => 8],
        ['code' => 'DEV-CONSOMMABLE', 'name' => 'Produit de parapharmacie test', 'generic' => null, 'form' => MedicineForm::ParapharmacyConsumable, 'strength' => null, 'unit' => 'unité', 'quantity' => 15, 'expiry_months' => 20],
        ['code' => 'DEV-CREME', 'name' => 'Crème dermatologique test', 'generic' => null, 'form' => MedicineForm::OintmentCream, 'strength' => null, 'unit' => 'tube', 'quantity' => 9, 'expiry_months' => 10],
        ['code' => 'DEV-SRO', 'name' => 'Solution de réhydratation orale', 'generic' => 'Sels de réhydratation orale', 'form' => MedicineForm::Sachet, 'strength' => null, 'unit' => 'sachet', 'quantity' => 40, 'expiry_months' => 16],
        ['code' => 'DEV-SIROP', 'name' => 'Paracétamol sirop', 'generic' => 'Paracétamol', 'form' => MedicineForm::Syrup, 'strength' => '120 mg / 5 ml', 'unit' => 'flacon', 'quantity' => 8, 'expiry_months' => 2],
        ['code' => 'DEV-SUPPO', 'name' => 'Suppositoire glycériné', 'generic' => 'Glycérol', 'form' => MedicineForm::SuppositoryOvule, 'strength' => null, 'unit' => 'suppositoire', 'quantity' => 20, 'expiry_months' => 14],
        ['code' => 'DEV-EPUISE', 'name' => 'Médicament épuisé test', 'generic' => null, 'form' => MedicineForm::Other, 'strength' => null, 'unit' => 'unité', 'quantity' => 0, 'expiry_months' => 12],
    ];

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new LogicException(
                'DevelopmentMedicineStockSeeder est strictement interdit hors des environnements local et testing.',
            );
        }

        if (config('rivo.site.type') !== 'clinic') {
            throw new LogicException('Le stock Pharmacie existe uniquement sur un site opérationnel.');
        }

        $actor = User::query()->where('active', true)->orderBy('id')->first();

        if (! $actor) {
            throw new RuntimeException(
                'Créez d’abord les comptes locaux avec DevelopmentUserSeeder.',
            );
        }

        $today = CarbonImmutable::today();
        $rows = [];

        DB::transaction(function () use ($actor, $today, &$rows): void {
            foreach (self::MEDICINES as $definition) {
                $item = CatalogItem::query()->withTrashed()->firstOrNew(['code' => $definition['code']]);
                $item->fill([
                    'name' => $definition['name'],
                    'type' => CatalogItemType::Medicine,
                    'module' => CatalogModule::Pharmacy,
                    'unit' => $definition['unit'],
                    'billable' => false,
                    'stockable' => true,
                    'reception_selectable' => false,
                    'reception_routing_mode' => null,
                    'description' => 'Donnée locale de développement — tarif réel à configurer par la Super Administration.',
                    'care_requires_allergy_check' => false,
                    'care_recommends_vitals' => false,
                    'created_by' => $item->created_by ?? $actor->getKey(),
                    'updated_by' => $actor->getKey(),
                ]);
                $item->save();

                if ($item->trashed()) {
                    $item->restore();
                }

                $medicine = Medicine::query()->firstOrNew(['catalog_item_id' => $item->getKey()]);
                $medicine->fill([
                    'generic_name' => $definition['generic'],
                    'form' => $definition['form'],
                    'strength' => $definition['strength'],
                    'active' => true,
                    'created_by' => $medicine->created_by ?? $actor->getKey(),
                    'updated_by' => $actor->getKey(),
                ])->save();

                if ($definition['quantity'] > 0) {
                    $lot = $this->synchronizeLot(
                        $medicine,
                        $actor,
                        lotNumber: "{$definition['code']}-LOT-01",
                        targetQuantity: $definition['quantity'],
                        expiresAt: $today->addMonths($definition['expiry_months']),
                    );

                    if ($definition['code'] === 'DEV-PARA-500') {
                        $this->synchronizeLot(
                            $medicine,
                            $actor,
                            lotNumber: 'DEV-PARA-500-EXPIRED',
                            targetQuantity: 7,
                            expiresAt: $today->subMonth(),
                        );
                    }

                    $rows[] = [$item->name, $definition['form']->label(), $lot->quantity_on_hand, $lot->expires_at->format('d/m/Y')];
                } else {
                    $rows[] = [$item->name, $definition['form']->label(), 0, '—'];
                }
            }
        });

        $this->command?->table(['Médicament', 'Forme', 'Stock utilisable', 'Péremption'], $rows);
        $this->command?->warn('Données de démonstration locales uniquement ; aucun tarif clinique réel n’a été créé.');
    }

    private function synchronizeLot(
        Medicine $medicine,
        User $actor,
        string $lotNumber,
        int $targetQuantity,
        CarbonImmutable $expiresAt,
    ): MedicineLot {
        $lot = MedicineLot::query()->firstOrNew([
            'medicine_id' => $medicine->getKey(),
            'lot_number' => $lotNumber,
        ]);
        $previousQuantity = $lot->exists ? $lot->quantity_on_hand : 0;
        $lot->fill([
            'received_at' => $lot->received_at ?? now(),
            'expires_at' => $expiresAt,
            'quantity_on_hand' => $targetQuantity,
            'active' => true,
            'created_by' => $lot->created_by ?? $actor->getKey(),
            'updated_by' => $actor->getKey(),
        ])->save();

        $delta = $targetQuantity - $previousQuantity;

        if ($delta !== 0) {
            PharmacyStockMovement::query()->create([
                'medicine_lot_id' => $lot->getKey(),
                'type' => $previousQuantity === 0
                    ? PharmacyStockMovementType::Opening
                    : PharmacyStockMovementType::Adjustment,
                'quantity_delta' => $delta,
                'balance_after' => $targetQuantity,
                'source_key' => sprintf('DEV-SEED:%s:%s:%d', $lot->uuid, now()->format('YmdHisv'), $targetQuantity),
                'reason' => $previousQuantity === 0
                    ? 'Stock initial local de développement.'
                    : 'Réinitialisation explicite du stock local de développement.',
                'occurred_at' => now(),
                'performed_by' => $actor->getKey(),
            ]);
        }

        return $lot;
    }
}
