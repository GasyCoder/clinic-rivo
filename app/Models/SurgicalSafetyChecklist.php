<?php

namespace App\Models;

use App\Enums\SurgicalChecklistPhase;
use App\Enums\SurgicalChecklistRole;
use App\Models\Concerns\Auditable;
use App\Support\SurgicalSafetyChecklistItems;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ADR-170 — un temps de la checklist de sécurité (SIGN IN, TIME OUT, SIGN OUT)
 * pour un dossier du bloc.
 *
 * `completed_at` n'est jamais saisi : il est **calculé** par
 * `refreshCompletion()` à partir des faits — les items obligatoires cochés et
 * chaque rôle requis ayant confirmé. Un temps ne se déclare donc pas terminé
 * par un clic : il l'est quand il l'est réellement.
 */
#[Fillable([
    'surgical_request_id', 'phase', 'checked_items', 'notes',
    'completed_at', 'created_by', 'updated_by',
])]
class SurgicalSafetyChecklist extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'phase' => SurgicalChecklistPhase::class,
            'checked_items' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function surgicalRequest(): BelongsTo
    {
        return $this->belongsTo(SurgicalRequest::class);
    }

    public function confirmations(): HasMany
    {
        return $this->hasMany(SurgicalSafetyChecklistConfirmation::class);
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    /** @return array<int, string> les items obligatoires encore à cocher */
    public function missingRequiredItems(): array
    {
        $checked = $this->checked_items ?? [];

        return array_values(array_filter(
            SurgicalSafetyChecklistItems::requiredKeys($this->phase),
            fn (string $key) => ! ($checked[$key] ?? false),
        ));
    }

    /** @return array<int, SurgicalChecklistRole> les rôles qui n'ont pas encore confirmé */
    public function missingConfirmations(): array
    {
        $confirmed = $this->confirmations
            ->map(fn (SurgicalSafetyChecklistConfirmation $row) => $row->role->value)
            ->all();

        return array_values(array_filter(
            $this->phase->requiredRoles(),
            fn (SurgicalChecklistRole $role) => ! in_array($role->value, $confirmed, true),
        ));
    }

    /**
     * Recalcule `completed_at` depuis les faits, sans jamais le déplacer une
     * fois posé : un temps confirmé le reste, même si l'on décoche ensuite un
     * item — sinon un clic défairait une vérification d'équipe déjà faite.
     */
    public function refreshCompletion(): void
    {
        $this->loadMissing('confirmations');

        if ($this->completed_at !== null) {
            return;
        }

        if ($this->missingRequiredItems() === [] && $this->missingConfirmations() === []) {
            $this->completed_at = now();
            $this->save();
        }
    }

    protected function auditModule(): ?string
    {
        return 'surgery';
    }
}
