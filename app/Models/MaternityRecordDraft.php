<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Saisie non validée du dossier Maternité, gardée côté serveur pour qu'une
 * actualisation ne la perde pas (ADR-136, même principe que l'ADR-073).
 *
 * Volontairement **pas** `Auditable` : elle est réécrite toutes les quelques
 * secondes pendant la frappe, et noyer le journal d'audit sous des lots de
 * saisie enterrerait les entrées qui comptent. Ce qui est audité, c'est le
 * dossier réellement enregistré.
 */
#[Fillable(['episode_orientation_id', 'created_by', 'payload'])]
class MaternityRecordDraft extends Model
{
    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    /**
     * Retire **une section** du brouillon d'un compte, sans toucher aux autres :
     * le panier d'actes enregistré ne doit pas emporter le dossier encore en
     * cours de saisie. Un brouillon vidé est supprimé.
     */
    public static function forgetSection(int $orientationId, int $userId, string $section): void
    {
        $draft = static::query()
            ->where('episode_orientation_id', $orientationId)
            ->where('created_by', $userId)
            ->first();

        if ($draft === null || ! array_key_exists($section, $draft->payload)) {
            return;
        }

        $payload = $draft->payload;
        unset($payload[$section]);

        $payload === [] ? $draft->delete() : $draft->forceFill(['payload' => $payload])->save();
    }

    public function orientation(): BelongsTo
    {
        return $this->belongsTo(EpisodeOrientation::class, 'episode_orientation_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
