<?php

namespace App\Models;

use App\Enums\ReferralSource;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-212 — qui a recommandé la clinique à un nouveau patient.
 *
 * Une par patient, notée à sa première arrivée. Le recommandant est un membre
 * du personnel, un partenaire (par sa fiche) ou une autre personne (son nom) ;
 * `referrer_name` garde le nom tel qu'il était. Le cadeau remis au
 * recommandant est tracé ici (qui, quand) ; il n'a aucun effet financier.
 */
#[Fillable([
    'patient_id', 'episode_id', 'source', 'employee_id', 'partner_organization_id',
    'referrer_name', 'referrer_phone', 'referred_at', 'recorded_by',
    'external_recorded_by_uuid', 'external_recorded_by_name',
])]
class PatientReferral extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'source' => ReferralSource::class,
            'referred_at' => 'datetime',
            'gift_given_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(PartnerOrganization::class, 'partner_organization_id')->withTrashed();
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function giftGiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gift_given_by');
    }

    protected function auditModule(): ?string
    {
        return 'reception';
    }
}
