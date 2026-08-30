<?php

namespace App\Models;

use App\Enums\MutualBeneficiaryType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'episode_id', 'mutual_organization_id', 'employer_name',
    'beneficiary_type', 'membership_number', 'organization_uuid_snapshot',
    'organization_name_snapshot', 'coverage_rate_snapshot', 'created_by', 'updated_by',
])]
class EpisodeMutualCoverage extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'beneficiary_type' => MutualBeneficiaryType::class,
            'coverage_rate_snapshot' => 'decimal:2',
        ];
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(MutualOrganization::class, 'mutual_organization_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EpisodeMutualCoverageAttachment::class);
    }

    protected function auditModule(): ?string
    {
        return 'reception';
    }
}
