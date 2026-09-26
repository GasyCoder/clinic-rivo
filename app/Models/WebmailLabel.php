<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * ADR-194 — un libellé de la messagerie d'un compte : un nom et une couleur ici,
 * un mot-clé IMAP sur les messages de sa boîte. Le mot-clé ne change jamais : le
 * renommer ne touche aucun message.
 */
#[Fillable(['user_id', 'name', 'color', 'keyword'])]
class WebmailLabel extends Model
{
    use HasUuid;

    /** Les couleurs proposées : des noms, jamais une couleur libre. */
    public const COLORS = ['blue', 'green', 'red', 'amber', 'violet', 'slate'];

    public const MAX_PER_USER = 30;

    public static function newKeyword(): string
    {
        return 'rivo'.Str::lower(Str::random(10));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
