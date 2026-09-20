<?php

namespace App\Models;

use App\Enums\BillableItemStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'maternity_record_id', 'catalog_item_id', 'catalog_item_uuid', 'procedure_code',
    'procedure_name', 'quantity', 'notes', 'performed_by', 'performed_by_role', 'performed_at', 'billable_item_id', 'billing_origin',
    'edited_by', 'edited_at',
])]
class MaternityProcedure extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    /** Le rôle dont les actes ne se corrigent pas par le personnel Maternité (ADR-140). */
    public const PHYSICIAN_ROLE = 'MEDICINE';

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'performed_at' => 'datetime', 'edited_at' => 'datetime'];
    }

    /**
     * Un acte enregistré par un médecin reste intact pour les autres comptes :
     * son auteur, lui, peut toujours le corriger. Le rôle est celui que l'auteur
     * avait **à l'enregistrement** (`performed_by_role`), jamais celui qu'il
     * a aujourd'hui.
     */
    public function isLockedFor(User $actor): bool
    {
        return $this->performed_by_role === self::PHYSICIAN_ROLE
            && $this->performed_by !== $actor->getKey();
    }

    public function billableItem(): BelongsTo
    {
        return $this->belongsTo(BillableItem::class);
    }

    /** L'acte est déjà porté sur une facture : seule la Réception/Caisse y touche (ADR-012). */
    public function isInvoiced(): bool
    {
        return $this->billableItem?->status === BillableItemStatus::Invoiced;
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(MaternityRecord::class, 'maternity_record_id');
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    protected function auditModule(): ?string
    {
        return 'maternity';
    }
}
