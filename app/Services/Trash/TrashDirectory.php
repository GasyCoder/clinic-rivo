<?php

namespace App\Services\Trash;

use App\Services\Settings\AppSettings;
use App\Actions\Catalog\RestoreCatalogItemAction;
use App\Actions\Patient\RestorePatientAction;
use App\Actions\Pharmacy\RestoreMedicineSupplierAction;
use App\Actions\Pharmacy\RestorePurchaseOrderAction;
use App\Actions\Pharmacy\RestoreSupplierCatalogAction;
use App\Actions\Pharmacy\RestoreSupplierInvoiceAction;
use App\Enums\PurchaseOrderStatus;
use App\Enums\TrashCategory;
use App\Models\AddressEntry;
use App\Models\AuditLog;
use App\Models\CashRegister;
use App\Models\CatalogItem;
use App\Models\MedicineSupplier;
use App\Models\MutualOrganization;
use App\Models\Patient;
use App\Models\PurchaseOrder;
use App\Models\SupplierCatalog;
use App\Models\SupplierInvoice;
use App\Services\Administration\AddressEntryManager;
use App\Services\Administration\MutualOrganizationManager;
use App\Services\Cash\CashRegisterManager;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Explicit registry of records that may safely be restored from the central
 * trash. Clinical/financial records are intentionally absent: those require
 * their own cancel/correct/reverse workflow and must never reach a generic
 * restore() call.
 */
class TrashDirectory
{
    public const MAX_RESULTS = 100;

    public function __construct(
        private readonly RestorePatientAction $restorePatient,
        private readonly RestoreCatalogItemAction $restoreCatalogItem,
        private readonly AddressEntryManager $addressEntries,
        private readonly MutualOrganizationManager $mutualOrganizations,
        private readonly CashRegisterManager $cashRegisters,
        private readonly RestoreMedicineSupplierAction $restoreSupplier,
        private readonly RestoreSupplierCatalogAction $restoreCatalog,
        private readonly RestoreSupplierInvoiceAction $restoreInvoice,
        private readonly RestorePurchaseOrderAction $restoreOrder,
    ) {}

    /**
     * @param  array{search?: string|null, category?: string|null, deleted_from?: string|null, deleted_to?: string|null}  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(array $filters): array
    {
        $selected = ($filters['category'] ?? 'ALL') === 'ALL'
            ? TrashCategory::cases()
            : [TrashCategory::from($filters['category'])];

        $counts = [];
        $entities = collect();

        foreach (TrashCategory::cases() as $category) {
            $query = $this->query($category, $filters);
            $counts[$category->value] = (clone $query)->count();

            if (in_array($category, $selected, true)) {
                $entities->push(...$query
                    ->latest('deleted_at')
                    ->limit(self::MAX_RESULTS)
                    ->get()
                    ->map(fn (Model $model): array => [
                        'category' => $category,
                        'model' => $model,
                    ]));
            }
        }

        $entities = $entities
            ->sortByDesc(fn (array $entry) => $entry['model']->deleted_at?->getTimestamp() ?? 0)
            ->take(self::MAX_RESULTS)
            ->values();
        $actors = $this->deletionActors($entities);
        $selectedTotal = collect($selected)->sum(fn (TrashCategory $category): int => $counts[$category->value]);

        return [
            'data' => $entities->map(fn (array $entry): array => $this->serialize(
                $entry['category'],
                $entry['model'],
                $actors->get($this->actorKey($entry['model'])),
            ))->all(),
            'meta' => [
                'site' => [
                    'code' => config('rivo.site.code'),
                    'name' => config('rivo.site.name'),
                ],
                'summary' => [
                    'total' => $selectedTotal,
                    'displayed' => $entities->count(),
                    'limited' => $selectedTotal > self::MAX_RESULTS,
                    'categories' => $counts,
                ],
            ],
        ];
    }

    /** @return array{category: string, uuid: string, already_restored: bool} */
    public function restore(TrashCategory $category, string $uuid, CatalogActor $actor): array
    {
        if ($actor->cannot('trash.restore') || $actor->cannot($category->restorePermission())) {
            throw new AuthorizationException('Cette restauration distante n’est pas autorisée.');
        }

        return DB::transaction(function () use ($category, $uuid, $actor): array {
            /** @var Model&SoftDeletes $model */
            $model = $this->modelQuery($category)
                ->withTrashed()
                ->where('uuid', $uuid)
                ->lockForUpdate()
                ->firstOrFail();
            $alreadyRestored = ! $model->trashed();

            if (! $alreadyRestored) {
                match ($category) {
                    TrashCategory::Patient => $this->restorePatient->execute($model),
                    TrashCategory::CatalogItem => $this->restoreCatalogItem->execute($model, $actor),
                    TrashCategory::AddressEntry => $this->addressEntries->restore($model),
                    TrashCategory::MutualOrganization => $this->mutualOrganizations->restore($model),
                    TrashCategory::CashRegister => $this->cashRegisters->restore($model),
                    TrashCategory::MedicineSupplier => $this->restoreSupplier->execute($model, $actor),
                    TrashCategory::SupplierCatalog => $this->restoreCatalog->execute($model, $actor),
                    TrashCategory::SupplierInvoice => $this->restoreInvoice->execute($model, $actor),
                    TrashCategory::PurchaseOrder => $this->restoreOrder->execute($model, $actor),
                };
            }

            return [
                'category' => $category->value,
                'uuid' => $uuid,
                'already_restored' => $alreadyRestored,
            ];
        });
    }

    /**
     * Destroy a trashed record for good.
     *
     * Deliberately narrow (ADR-010, same reasoning as ADR-062 for an account
     * that never served): the model itself decides, through
     * `isForceDeleteProtected()`, and refuses as soon as anything references
     * it — an order, a reception, a price, a stock movement. A supplier that
     * delivered once, a catalog that was imported and a supplier invoice are
     * therefore never destroyed: they are the history of what was bought.
     *
     * @return array{category: string, uuid: string}
     */
    public function forceDelete(TrashCategory $category, string $uuid, CatalogActor $actor): array
    {
        if ($actor->cannot('trash.force_delete') || $actor->cannot($category->restorePermission())) {
            throw new AuthorizationException('Cette suppression définitive n’est pas autorisée.');
        }

        return DB::transaction(function () use ($category, $uuid): array {
            /** @var Model&SoftDeletes $model */
            $model = $this->modelQuery($category)
                ->withTrashed()
                ->where('uuid', $uuid)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $model->trashed()) {
                throw ValidationException::withMessages([
                    'uuid' => 'Cet élément n’est pas dans la corbeille : mettez-le d’abord à la corbeille.',
                ]);
            }

            // The model's own guard is the rule; this message names it before
            // the exception does, so the screen can say why it is refused.
            if ($model->isForceDeleteProtected()) {
                throw ValidationException::withMessages([
                    'uuid' => 'Cet élément a déjà servi : il reste dans la corbeille et peut être restauré, mais il ne peut pas être supprimé définitivement.',
                ]);
            }

            // Ce qui n'appartient qu'à l'élément part avec lui : sinon
            // « supprimer définitivement » laisserait des fichiers et des
            // lignes qui ne désignent plus rien.
            $this->deleteOwnedContent($category, $model);

            $model->forceDelete();

            return ['category' => $category->value, 'uuid' => $uuid];
        });
    }

    /**
     * Le contenu propre d'un élément détruit pour de bon.
     *
     * Un dossier fournisseur possède ses fichiers de catalogue et ses
     * rattachements « peut fournir » : ni l'un ni l'autre n'a de sens sans
     * lui. Ce qui relève de l'histoire (commandes, factures, lots,
     * mouvements, prix) a déjà bloqué la suppression en amont.
     */
    private function deleteOwnedContent(TrashCategory $category, Model $model): void
    {
        match ($category) {
            TrashCategory::MedicineSupplier => $this->deleteSupplierFolder($model),
            TrashCategory::SupplierCatalog => $this->deleteCatalogFile($model),
            default => null,
        };
    }

    private function deleteSupplierFolder(MedicineSupplier $supplier): void
    {
        $supplier->medicines()->detach();

        foreach ($supplier->catalogs()->withTrashed()->get() as $catalog) {
            $this->deleteCatalogFile($catalog);
            // Les lignes partent avec le catalogue (cascadeOnDelete). Le
            // catalogue ne se protège pas contre la fin de son propre
            // dossier : il n'aurait plus rien à désigner.
            $catalog->deletingWithFolder = true;
            $catalog->forceDelete();
        }
    }

    private function deleteCatalogFile(SupplierCatalog $catalog): void
    {
        if (filled($catalog->path) && Storage::disk('local')->exists($catalog->path)) {
            Storage::disk('local')->delete($catalog->path);
        }
    }

    /** @return Builder<Model> */
    private function query(TrashCategory $category, array $filters): Builder
    {
        $query = $this->modelQuery($category)->onlyTrashed();
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function (Builder $nested) use ($category, $search): void {
                match ($category) {
                    TrashCategory::Patient => $nested
                        ->where('patient_number', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"),
                    TrashCategory::CatalogItem => $nested
                        ->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%"),
                    TrashCategory::AddressEntry => $nested
                        ->where('label', 'like', "%{$search}%"),
                    TrashCategory::MutualOrganization, TrashCategory::CashRegister => $nested
                        ->where('name', 'like', "%{$search}%"),
                    TrashCategory::MedicineSupplier => $nested
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%"),
                    TrashCategory::SupplierCatalog => $nested
                        ->where('original_name', 'like', "%{$search}%"),
                    TrashCategory::SupplierInvoice => $nested
                        ->where('invoice_number', 'like', "%{$search}%"),
                    TrashCategory::PurchaseOrder => $nested
                        ->where('order_number', 'like', "%{$search}%"),
                };
            });
        }

        if (filled($filters['deleted_from'] ?? null)) {
            $query->whereDate('deleted_at', '>=', $filters['deleted_from']);
        }

        if (filled($filters['deleted_to'] ?? null)) {
            $query->whereDate('deleted_at', '<=', $filters['deleted_to']);
        }

        return $query;
    }

    /** @return Builder<Model> */
    private function modelQuery(TrashCategory $category): Builder
    {
        return match ($category) {
            TrashCategory::Patient => Patient::query(),
            TrashCategory::CatalogItem => CatalogItem::query(),
            TrashCategory::AddressEntry => AddressEntry::query(),
            TrashCategory::MutualOrganization => MutualOrganization::query(),
            TrashCategory::CashRegister => CashRegister::query(),
            TrashCategory::MedicineSupplier => MedicineSupplier::query(),
            TrashCategory::SupplierCatalog => SupplierCatalog::query()->with('supplier'),
            TrashCategory::SupplierInvoice => SupplierInvoice::query()->with('supplier'),
            TrashCategory::PurchaseOrder => PurchaseOrder::query()->with('supplier'),
        };
    }

    /**
     * @param  Collection<int, array{category: TrashCategory, model: Model}>  $entities
     * @return Collection<string, AuditLog>
     */
    private function deletionActors(Collection $entities): Collection
    {
        return $entities
            ->groupBy(fn (array $entry): string => $entry['model']->getMorphClass())
            ->flatMap(function (Collection $entries, string $type): Collection {
                $ids = $entries->pluck('model.id')->filter()->values();

                return AuditLog::query()
                    ->with('user:id,name')
                    ->where('action', 'delete')
                    ->where('entity_type', $type)
                    ->whereIn('entity_id', $ids)
                    ->latest('id')
                    ->get()
                    ->unique('entity_id')
                    ->mapWithKeys(fn (AuditLog $log): array => [
                        $type.':'.$log->entity_id => $log,
                    ]);
            });
    }

    private function actorKey(Model $model): string
    {
        return $model->getMorphClass().':'.$model->getKey();
    }

    /** @return array<string, mixed> */
    private function serialize(TrashCategory $category, Model $model, ?AuditLog $deletion): array
    {
        [$title, $reference, $subtitle] = match ($category) {
            TrashCategory::Patient => [
                trim($model->last_name.' '.$model->first_name),
                $model->patient_number,
                $model->patient_type?->label(),
            ],
            TrashCategory::CatalogItem => [
                $model->name,
                $model->code,
                $model->module?->label(),
            ],
            TrashCategory::AddressEntry => [$model->label, null, 'Référentiel administratif'],
            TrashCategory::MutualOrganization => [
                $model->name,
                null,
                'Couverture '.$model->coverage_rate.' %',
            ],
            TrashCategory::CashRegister => [$model->name, null, 'Référentiel des caisses'],
            TrashCategory::MedicineSupplier => [$model->name, $model->code, 'Dossier fournisseur'],
            TrashCategory::SupplierCatalog => [
                $model->original_name,
                null,
                'Catalogue de '.($model->supplier?->name ?? 'fournisseur archivé'),
            ],
            TrashCategory::SupplierInvoice => [
                'Facture '.$model->invoice_number,
                $model->invoice_number,
                ($model->supplier?->name ?? 'Fournisseur archivé').' · '.app(AppSettings::class)->formatMoney($model->total_amount),
            ],
            TrashCategory::PurchaseOrder => [
                'Commande '.$model->order_number,
                $model->order_number,
                'Brouillon · '.($model->supplier?->name ?? 'Fournisseur archivé').' · '.app(AppSettings::class)->formatMoney($model->total_amount),
            ],
        };

        // ADR-098 / ADR-061 — l'écran doit savoir AVANT le clic : ce qui retient
        // un élément est nommé ici, plutôt que renvoyé comme un refus après
        // la confirmation.
        $blockers = $this->forceDeleteBlockers($category, $model);

        return [
            'uuid' => $model->uuid,
            'category' => $category->value,
            'category_label' => $category->singularLabel(),
            'category_icon' => $category->icon(),
            'title' => $title,
            'reference' => $reference,
            'subtitle' => $subtitle,
            'deleted_at' => $model->deleted_at?->toIso8601String(),
            'deleted_by' => $deletion?->user?->name
                ?? $deletion?->external_actor_name
                ?? 'Système',
            'delete_reason' => $model->delete_reason,
            'can_restore' => true,
            'can_force_delete' => $blockers === [],
            'force_delete_blockers' => $blockers,
        ];
    }

    /**
     * Ce qui retient un élément dans la corbeille, nommé et compté.
     *
     * `isForceDeleteProtected()` reste la règle — elle seule refuse. Ceci en
     * est la lecture : les mêmes relations, dites à l'écran pour qu'il
     * n'offre pas un bouton qui sera refusé.
     *
     * @return array<int, string>
     */
    private function forceDeleteBlockers(TrashCategory $category, Model $model): array
    {
        if (! $model->isForceDeleteProtected()) {
            return [];
        }

        $counts = match ($category) {
            TrashCategory::MedicineSupplier => [
                'commande' => $model->purchaseOrders()->count(),
                'facture' => $model->invoices()->withTrashed()->count(),
                'lot reçu' => $model->lots()->count(),
                'mouvement de stock' => $model->stockMovements()->count(),
                'prix d’achat' => $model->offers()->count(),
            ],
            TrashCategory::CatalogItem => [
                'tarif' => $model->tariffs()->count(),
                'prestation facturée' => $model->billableItems()->count(),
                'demande de la Réception' => $model->episodeServiceRequests()->count(),
                'acte demandé aux Soins' => $model->careOrderItems()->count(),
            ],
            TrashCategory::AddressEntry => [
                'patient' => $model->patients()->withTrashed()->count(),
                'employé' => $model->employees()->withTrashed()->count(),
            ],
            TrashCategory::MutualOrganization => [
                'couverture patient' => $model->coverages()->count(),
                'couverture de passage' => $model->episodeCoverages()->count(),
            ],
            TrashCategory::CashRegister => [
                'session de caisse' => $model->sessions()->count(),
            ],
            TrashCategory::PurchaseOrder => [
                'réception' => $model->receipts()->count(),
                'facture' => $model->invoices()->count(),
            ],
            TrashCategory::Patient => [
                'passage' => $model->episodes()->count(),
                'facture' => $model->invoices()->count(),
                'antécédent' => $model->antecedents()->count(),
                'allergie' => $model->allergies()->count(),
                'traitement habituel' => $model->treatments()->count(),
            ],
            TrashCategory::SupplierCatalog => [
                'ligne importée' => $model->items()->count(),
            ],
            default => [],
        };

        // Deux cas ne se comptent pas : ils tiennent à ce qu'est l'élément.
        $stated = match (true) {
            $category === TrashCategory::SupplierInvoice => ['une facture fournisseur est une pièce comptable'],
            $category === TrashCategory::PurchaseOrder && $model->status !== PurchaseOrderStatus::Draft => ['la commande a été envoyée au fournisseur'],
            default => [],
        };

        $named = collect($counts)
            ->filter()
            ->map(fn (int $total, string $label): string => $total.' '.$label.($total > 1 && ! str_contains($label, 'prix') ? 's' : ''))
            ->values()
            ->all();

        // Un modèle protégé sans compte lisible le dit quand même : mieux vaut
        // une phrase générale qu'un bouton qui échoue.
        $blockers = [...$stated, ...$named];

        return $blockers !== [] ? $blockers : ['cet élément a déjà servi'];
    }
}
