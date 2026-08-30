<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'episode_id', 'partner_organization_id',
    'organization_uuid_snapshot', 'organization_name_snapshot',
    'created_by', 'updated_by',
])]
class EpisodePartnerCoverage extends Model
{
    use Auditable, HasUuid;

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(PartnerOrganization::class, 'partner_organization_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected function auditModule(): ?string
    {
        return 'reception';
    }
}
