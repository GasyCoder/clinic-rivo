<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
