<?php

namespace Database\Seeders;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ReceptionRoutingMode;
use App\Models\CatalogItem;
use App\Models\User;
use App\Support\Money;
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
     * @var array<int, array{code: string, name: string, module: CatalogModule, unit: string, amount: int, description: string, reception_selectable: bool, routing_mode: ?ReceptionRoutingMode}>
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
            'routing_mode' => ReceptionRoutingMode::CareThenMedicine,
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
        ],
        [
            'code' => 'PANSEMENT-C',
            'name' => 'Pansement complexe',
            'module' => CatalogModule::Care,
            'unit' => 'soin',
            'amount' => 20000,
            'description' => 'Prise en charge et pansement d’une plaie complexe.',
            'reception_selectable' => true,
            'routing_mode' => ReceptionRoutingMode::CareOnly,
        ],
        [
            'code' => 'INJECTION-IM',
            'name' => 'Injection intramusculaire',
            'module' => CatalogModule::Care,
            'unit' => 'soin',
            'amount' => 5000,
            'description' => 'Réalisation d’une injection par voie intramusculaire.',
            'reception_selectable' => true,
            'routing_mode' => ReceptionRoutingMode::CareOnly,
        ],
        [
            'code' => 'PERFUSION',
            'name' => 'Pose de perfusion',
            'module' => CatalogModule::Care,
            'unit' => 'soin',
            'amount' => 10000,
            'description' => 'Pose et surveillance initiale d’une perfusion.',
            'reception_selectable' => true,
            'routing_mode' => ReceptionRoutingMode::CareOnly,
        ],
        [
            'code' => 'LAB-NFS',
            'name' => 'Numération formule sanguine (NFS)',
            'module' => CatalogModule::Laboratory,
            'unit' => 'analyse',
            'amount' => 15000,
            'description' => 'Analyse hématologique de type NFS.',
            'reception_selectable' => false,
            'routing_mode' => null,
        ],
        [
            'code' => 'LAB-GLYC',
            'name' => 'Glycémie',
            'module' => CatalogModule::Laboratory,
            'unit' => 'analyse',
            'amount' => 10000,
            'description' => 'Dosage du glucose sanguin.',
            'reception_selectable' => false,
            'routing_mode' => null,
        ],
        [
            'code' => 'LAB-GROUP-RH',
            'name' => 'Groupage sanguin et rhésus',
            'module' => CatalogModule::Laboratory,
            'unit' => 'analyse',
            'amount' => 15000,
            'description' => 'Détermination du groupe sanguin et du facteur rhésus.',
            'reception_selectable' => false,
            'routing_mode' => null,
        ],
        [
            'code' => 'LAB-TDR-PALU',
            'name' => 'Test rapide du paludisme',
            'module' => CatalogModule::Laboratory,
            'unit' => 'analyse',
            'amount' => 10000,
            'description' => 'Test de diagnostic rapide du paludisme.',
            'reception_selectable' => false,
            'routing_mode' => null,
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

        try {
            DB::transaction(function () use (
                $actor,
                &$created,
                &$tariffsCreated,
                &$preserved,
            ): void {
                foreach (self::SERVICES as $service) {
                    $existing = CatalogItem::withTrashed()
                        ->where('code', $service['code'])
                        ->first();

                    if ($existing?->trashed()) {
                        throw new RuntimeException(
                            "La désignation {$service['code']} est archivée. Restaurez-la explicitement avant le seeding.",
                        );
                    }

                    if ($existing) {
                        $this->assertCompatible($existing);
                        $this->applyInitialReceptionRouteIfUnset($existing, $service);

                        if ($existing->currentTariff()->exists()) {
                            $preserved++;

                            continue;
                        }

                        $this->createTariff($existing, $service['amount'], $actor);
                        $tariffsCreated++;

                        continue;
                    }

                    $item = CatalogItem::query()->create([
                        'code' => $service['code'],
                        'name' => $service['name'],
                        'type' => CatalogItemType::Service->value,
                        'module' => $service['module']->value,
                        'unit' => $service['unit'],
                        'billable' => true,
                        'stockable' => false,
                        'reception_selectable' => $service['reception_selectable'],
                        'reception_routing_mode' => $service['routing_mode'],
                        'description' => $service['description'],
                        'created_by' => $actor->id,
                        'updated_by' => $actor->id,
                    ]);
                    $this->createTariff($item, $service['amount'], $actor);
                    $created++;
                    $tariffsCreated++;
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
            '%d désignations disponibles : %d créées, %d tarifs ajoutés, %d tarifs existants conservés.',
            count(self::SERVICES),
            $created,
            $tariffsCreated,
            $preserved,
        ));
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
                'Aucun compte actif ne peut créer le référentiel et ses tarifs. '.
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
    }

    private function canSeedCatalog(User $actor): bool
    {
        return $actor->hasPermissionTo('catalog.items.create')
            && $actor->hasPermissionTo('catalog.tariffs.create');
    }

    private function assertCompatible(CatalogItem $item): void
    {
        if (
            $item->type !== CatalogItemType::Service
            || ! $item->billable
            || $item->stockable
        ) {
            throw new RuntimeException(
                "Le code {$item->code} existe avec une configuration incompatible ; aucune donnée n’a été écrasée.",
            );
        }
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

    private function createTariff(CatalogItem $item, int $amount, User $actor): void
    {
        $item->tariffs()->create([
            'amount' => Money::normalize($amount),
            'currency' => 'MGA',
            'effective_from' => now(),
            'active_key' => 'CURRENT',
            'change_reason' => 'Tarif initial de validation locale à confirmer par la clinique.',
            'created_by' => $actor->id,
        ]);
    }
}
