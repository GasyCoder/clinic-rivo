<?php

namespace App\Models;

use App\Enums\AdministrationRoute;
use App\Enums\ClinicalSuggestionSource;
use App\Enums\PrescriptionLineReviewStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'prescription_id', 'medicine_id', 'medication_name', 'quantity',
    'stock_available_at_prescription', 'earliest_expiration_at',
    'dosage', 'route', 'frequency', 'duration', 'instructions',
    'is_manual_entry', 'catalog_review_status', 'catalog_reviewed_by',
    'catalog_reviewed_at', 'catalog_review_note',
    'suggestion_source', 'clinical_protocol_id',
])]
class PrescriptionLine extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'route' => AdministrationRoute::class,
            'quantity' => 'integer',
            'stock_available_at_prescription' => 'integer',
            'earliest_expiration_at' => 'date',
            'is_manual_entry' => 'boolean',
            'catalog_review_status' => PrescriptionLineReviewStatus::class,
            'catalog_reviewed_at' => 'datetime',
            'suggestion_source' => ClinicalSuggestionSource::class,
        ];
    }

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function stockReservations(): HasMany
    {
        return $this->hasMany(MedicineStockReservation::class);
    }

    public function catalogReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'catalog_reviewed_by');
    }

    protected function auditModule(): ?string
    {
        return 'medical';
    }
}
