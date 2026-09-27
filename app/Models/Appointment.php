<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un rendez-vous programmé (ADR-204).
 *
 * `Appointment ≠ Episode` : un rendez-vous est un événement futur. Il n'ouvre
 * aucun passage, n'entre dans aucune file et ne facture rien. Le jour venu, la
 * patiente passe par la Réception, qui ouvre son nouveau passage ; la nouvelle
 * consultation rejoint la même grossesse par le choix explicite de l'ADR-201.
 *
 * Il n'est jamais supprimé : un rendez-vous annulé garde qui l'a annulé et pourquoi.
 */
#[Fillable([
    'patient_id', 'pregnancy_id', 'source_maternity_record_id', 'scheduled_at',
    'reason', 'notes', 'status', 'created_by', 'cancelled_at', 'cancelled_by', 'cancel_reason',
])]
class Appointment extends Model
{
    use Auditable, HasUuid;

    protected $attributes = [
        'status' => AppointmentStatus::Scheduled->value,
    ];

    protected function casts(): array
    {
        return [
            'status' => AppointmentStatus::class,
            'scheduled_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    public function pregnancy(): BelongsTo
    {
        return $this->belongsTo(Pregnancy::class);
    }

    public function sourceMaternityRecord(): BelongsTo
    {
        return $this->belongsTo(MaternityRecord::class, 'source_maternity_record_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        return [
            'uuid' => $this->uuid,
            'scheduled_at' => $this->scheduled_at,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            // Dit, jamais décidé : un rendez-vous passé resté « programmé »
            // n'est pas marqué non honoré à la place de personne.
            'is_past' => $this->scheduled_at?->isPast() ?? false,
            'created_by' => $this->creator?->name,
        ];
    }

    protected function auditModule(): ?string
    {
        return 'maternity';
    }
}
