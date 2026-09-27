<?php

namespace App\Models;

use App\Enums\SupplierCatalogFileKind;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'medicine_supplier_id', 'original_name', 'path', 'mime_type', 'size', 'kind',
    'catalog_date', 'imported_at', 'active_key', 'notes', 'created_by', 'updated_by',
    'external_created_by_uuid', 'external_created_by_name',
])]
class SupplierCatalog extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected function casts(): array
    {
        return [
            'kind' => SupplierCatalogFileKind::class,
            'size' => 'integer',
            'catalog_date' => 'date',
            'imported_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(MedicineSupplier::class, 'medicine_supplier_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplierCatalogItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isActive(): bool
    {
        return $this->active_key === 'ACTIVE';
    }

    public function isImported(): bool
    {
        return $this->imported_at !== null;
    }

    /**
     * Mis à vrai uniquement quand le dossier fournisseur lui-même est
     * détruit : un catalogue ne survit pas au fournisseur qui l'a fourni.
     * Jamais exposé à une requête — c'est TrashDirectory qui le pose.
     */
    public bool $deletingWithFolder = false;

    /**
     * A catalog that was imported, or whose lines already feed a medicine or
     * a supplier price, is history: only a file uploaded by mistake and
     * never used can leave the trash for good.
     */
    public function isForceDeleteProtected(): bool
    {
        if ($this->deletingWithFolder) {
            return false;
        }

        return $this->isImported() || $this->items()->exists();
    }

    protected function auditModule(): ?string
    {
        return 'pharmacy';
    }
}
