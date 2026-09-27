<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable([
    'code', 'name', 'contact_name', 'phone', 'email', 'address',
    'created_by', 'updated_by',
])]
class MedicineSupplier extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    public function medicines(): BelongsToMany
    {
        return $this->belongsToMany(Medicine::class, 'medicine_supplier')->withTimestamps();
    }

    public function lots(): HasMany
    {
        return $this->hasMany(MedicineLot::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(PharmacyStockMovement::class);
    }

    public function catalogs(): HasMany
    {
        return $this->hasMany(SupplierCatalog::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(MedicineSupplierOffer::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class);
    }

    /**
     * Les médicaments de la clinique que ce fournisseur vend : un prix en
     * cours, le lien « peut fournir », une ligne de son catalogue actif qui
     * les désigne, ou une commande déjà passée chez lui.
     *
     * ADR-182 — un produit livré se choisit là-dedans, jamais dans tout le
     * catalogue de la pharmacie : le livreur apporte ce que son fournisseur
     * vend. Une seule définition, lue par la liste proposée et par la garde
     * du serveur, pour qu'elles ne se contredisent pas.
     *
     * @return Collection<int, int>
     */
    public function suppliedMedicineIds(): Collection
    {
        return $this->offers()->where('active_key', 'CURRENT')->pluck('medicine_id')
            ->merge($this->medicines()->pluck('medicines.id'))
            ->merge(SupplierCatalogItem::query()
                ->whereIn('supplier_catalog_id', $this->catalogs()->where('active_key', 'ACTIVE')->select('id'))
                ->whereNotNull('linked_medicine_id')
                ->pluck('linked_medicine_id'))
            ->merge(PurchaseOrderLine::query()
                ->whereIn('purchase_order_id', $this->purchaseOrders()
                    ->where('status', '!=', PurchaseOrderStatus::Cancelled->value)
                    ->select('id'))
                ->pluck('medicine_id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Ce qui interdit la suppression définitive, c'est l'histoire de ce
     * fournisseur : une commande, une facture, un lot reçu, un mouvement de
     * stock, un prix d'achat déjà enregistré. Détruire le dossier
     * effacerait alors le sens de lignes qui le désignent (ADR-010).
     *
     * Ses catalogues et ses rattachements « peut fournir » ne sont pas de
     * l'histoire : ils n'appartiennent qu'au dossier et disparaissent avec
     * lui (TrashDirectory::forceDelete), fichiers compris.
     */
    public function isForceDeleteProtected(): bool
    {
        return $this->lots()->exists()
            || $this->stockMovements()->exists()
            || $this->offers()->exists()
            || $this->purchaseOrders()->exists()
            || $this->invoices()->withTrashed()->exists();
    }

    protected function auditModule(): ?string
    {
        return 'pharmacy';
    }
}
