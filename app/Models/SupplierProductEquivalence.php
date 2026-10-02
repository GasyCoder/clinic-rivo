<?php

namespace App\Models;

use App\Enums\SupplierEquivalenceStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-241 — une paire de produits fournisseurs, et ce qu'on en a dit.
 *
 * Un produit est désigné par son fournisseur et sa référence (son libellé
 * normalisé à défaut), jamais par une ligne de catalogue : relire le
 * catalogue recrée ses lignes, la décision doit lui survivre.
 */
#[Fillable([
    'medicine_supplier_id', 'product_ref', 'label',
    'other_medicine_supplier_id', 'other_product_ref', 'other_label',
    'medicine_id', 'status', 'source', 'reason',
    'decided_by', 'external_decided_by_uuid', 'external_decided_by_name', 'decided_at',
])]
class SupplierProductEquivalence extends Model
{
    use Auditable, HasUuid;

    public const SOURCE_HUMAN = 'HUMAN';

    public const SOURCE_AI = 'AI';

    protected function casts(): array
    {
        return [
            'status' => SupplierEquivalenceStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(MedicineSupplier::class, 'medicine_supplier_id')->withTrashed();
    }

    public function otherSupplier(): BelongsTo
    {
        return $this->belongsTo(MedicineSupplier::class, 'other_medicine_supplier_id')->withTrashed();
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    protected function auditModule(): ?string
    {
        return 'pharmacy';
    }
}
