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

/**
 * A laboratory REQUEST from Medicine or Reception — kept distinct from the
 * EpisodeOrientation that routes the sample/technician (ORIENTATION) and the
 * per-item RESULT. Billing remains owned by Reception/Cash.
 */
#[Fillable([
    'episode_id', 'hospital_stay_id', 'maternity_record_id', 'consultation_id', 'source_orientation_id', 'lab_orientation_id',
    'requested_by', 'notes', 'requested_at',
    'cancelled_at', 'cancelled_by', 'cancel_reason',
    'archived_at', 'archived_by',
    'lab_archived_at', 'lab_archived_by',
    'lab_number', 'received_at', 'received_by', 'payment_exemption',
    'conclusion', 'conclusion_at', 'conclusion_by',
    'results_recipient_id', 'results_addressed_at', 'results_addressed_by',
])]
class LabRequest extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime', 'cancelled_at' => 'datetime', 'archived_at' => 'datetime', 'lab_archived_at' => 'datetime',
            'received_at' => 'datetime', 'conclusion_at' => 'datetime', 'results_addressed_at' => 'datetime',
        ];
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function labOrientation(): BelongsTo
    {
        return $this->belongsTo(EpisodeOrientation::class, 'lab_orientation_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(LabRequestItem::class);
    }

    /** ADR-214 — les prélèvements de la demande, dans l'ordre de leurs étiquettes. */
    public function samples(): HasMany
    {
        return $this->hasMany(LabSample::class)->orderBy('sequence');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** ADR-216 — le médecin à qui le technicien a envoyé les résultats ; vide = personne. */
    public function resultsRecipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'results_recipient_id');
    }

    /**
     * Amendement ADR-216 du 2026-09-29 (ter) — tous les médecins à qui les résultats
     * ont été adressés (un, plusieurs ou tous) ; `resultsRecipient` reste le premier.
     */
    public function recipients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'lab_request_recipients')
            ->withPivot(['addressed_at', 'addressed_by'])
            ->withTimestamps()
            ->orderBy('lab_request_recipients.id');
    }

    /** @return list<int> les destinataires, y compris le premier enregistré avant la table */
    public function recipientIds(): array
    {
        $ids = $this->relationLoaded('recipients')
            ? $this->recipients->pluck('id')->all()
            : $this->recipients()->pluck('users.id')->all();

        if ($this->results_recipient_id !== null) {
            $ids[] = $this->results_recipient_id;
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    public function isAddressedTo(?User $user): bool
    {
        return $user?->getKey() !== null && in_array((int) $user->getKey(), $this->recipientIds(), true);
    }

    /** Les noms des destinataires, « A, B » ; `null` quand personne n'est nommé. */
    public function recipientNames(): ?string
    {
        $this->loadMissing('recipients:users.id,users.name');
        $names = $this->recipients->pluck('name');

        if ($names->isEmpty() && $this->results_recipient_id !== null) {
            $names = collect([$this->resultsRecipient?->name])->filter();
        }

        return $names->isEmpty() ? null : $names->implode(', ');
    }

    public function resultsAddressedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'results_addressed_by');
    }

    /** ADR-216 — les résultats ont-ils déjà été envoyés au moins une fois ? */
    public function resultsAddressed(): bool
    {
        return $this->results_addressed_at !== null;
    }

    public function conclusionBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conclusion_by');
    }

    /** ADR-214 — la demande est passée par la réception du laboratoire. */
    public function isReceived(): bool
    {
        return $this->received_at !== null;
    }

    /** ADR-220 — rangée par le laboratoire (distinct de `archived_at`, ADR-131, côté médecin). */
    public function isLabArchived(): bool
    {
        return $this->lab_archived_at !== null;
    }

    /**
     * ADR-220 — une demande d'analyses est une donnée médicale : elle se met à
     * la corbeille et se restaure, elle n'est jamais détruite (ADR-010).
     */
    public function isForceDeleteProtected(): bool
    {
        return true;
    }

    /** Display-only, computed from item resolution — never a second persisted flag. */
    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /**
     * Whether anything has already been produced for this request. A request
     * that carries a result is never cancellable: the act happened, and
     * ADR-010 forbids erasing it.
     */
    public function hasAnyResult(): bool
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();

        return $items->contains(fn (LabRequestItem $item): bool => $item->hasStarted());
    }

    public function displayStatus(): string
    {
        // A cancelled request is not "awaiting a result": it was
        // withdrawn, and the queues must stop counting it.
        if ($this->isCancelled()) {
            return 'CANCELLED';
        }

        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();

        // ADR-216 — l'état lu par le prescripteur : ce qui lui a été envoyé,
        // pas ce que le laboratoire a saisi ou rendu sans l'envoyer encore.
        if ($items->isEmpty() || $items->every(fn (LabRequestItem $item) => ! $item->isDelivered())) {
            return 'REQUESTED';
        }

        return $items->every(fn (LabRequestItem $item) => $item->isDelivered())
            ? 'COMPLETED'
            : 'IN_PROGRESS';
    }

    protected function auditModule(): ?string
    {
        return 'clinical_flow';
    }

    /** ADR-162 — la demande faite depuis le séjour, sans consultation. */
    public function hospitalStay(): BelongsTo
    {
        return $this->belongsTo(HospitalStay::class);
    }

    /** ADR-204 — la demande faite depuis une prise en charge Maternité, sans consultation Médecine. */
    public function maternityRecord(): BelongsTo
    {
        return $this->belongsTo(MaternityRecord::class);
    }
}
