<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ADR-195 — un message type d'un compte, à insérer en rédigeant. */
#[Fillable(['user_id', 'name', 'subject', 'body_html'])]
class WebmailTemplate extends Model
{
    use HasUuid;

    public const MAX_PER_USER = 50;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
