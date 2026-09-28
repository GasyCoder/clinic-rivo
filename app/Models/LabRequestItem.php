<?php

namespace App\Models;

use App\Enums\LabItemStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One requested analysis, snapshotting the catalog at request time. */
#[Fillable([
    'lab_request_id', 'catalog_item_id', 'billable_item_id', 'catalog_item_code_snapshot', 'catalog_item_name_snapshot',
    'result_value', 'result_notes', 'reference_snapshot', 'resulted_at', 'resulted_by',
    'status', 'conclusion', 'started_at', 'started_by', 'validated_at', 'validated_by',
    'returned_at', 'returned_by', 'return_reason',
    'external_lab_name', 'external_reference', 'sent_out_notes', 'sent_out_at', 'sent_out_by',
])]
class LabRequestItem extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'reference_snapshot' => 'array',
            'resulted_at' => 'datetime',
            'status' => LabItemStatus::class,
            'started_at' => 'datetime',
            'validated_at' => 'datetime',
            'returned_at' => 'datetime',
            'sent_out_at' => 'datetime',
        ];
    }

    /** ADR-213 — l'état de l'analyse ; une ligne pas encore relue de la base est « à analyser ». */
    public function currentStatus(): LabItemStatus
    {
        return $this->status instanceof LabItemStatus ? $this->status : LabItemStatus::Pending;
    }

    /** Une saisie a commencé, ou un résultat a été rendu : l'acte a eu lieu (ADR-010, ADR-079). */
    public function hasStarted(): bool
    {
        return $this->resulted_at !== null || $this->currentStatus() !== LabItemStatus::Pending;
    }

    /** ADR-214 — l'analyse est confiée à un laboratoire extérieur. */
    public function isSentOut(): bool
    {
        return $this->sent_out_at !== null;
    }

    public function sentOutBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_out_by');
    }

    public function results(): HasMany
    {
        return $this->hasMany(LabResult::class);
    }

    public function antibiograms(): HasMany
    {
        return $this->hasMany(LabAntibiogram::class)->orderBy('bacterium_name_snapshot');
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function validatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    public function labRequest(): BelongsTo
    {
        return $this->belongsTo(LabRequest::class);
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function resultedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resulted_by');
    }

    protected function auditModule(): ?string
    {
        return 'clinical_flow';
    }

    /** ADR-105 — la prestation portée au compte du patient pour cet examen. */
    public function billableItem(): BelongsTo
    {
        return $this->belongsTo(BillableItem::class);
    }
}
