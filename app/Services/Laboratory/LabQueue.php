<?php

namespace App\Services\Laboratory;

use App\Enums\LabItemStatus;
use App\Models\LabRequest;
use Illuminate\Database\Eloquent\Builder;

/**
 * ADR-213 — la file du Laboratoire, par demande. Chaque demande est dans une
 * seule vue, lue sur l'état de ses analyses, par ordre de priorité :
 *
 *   to_redo      une analyse renvoyée à refaire
 *   to_do        une analyse à analyser ou en cours
 *   to_validate  tout est terminé, une analyse attend le biologiste
 *   validated    toutes les analyses sont validées
 *
 * Une demande retirée par le prescripteur n'y est jamais (ADR-079).
 */
class LabQueue
{
    public const VIEWS = ['to_do', 'to_redo', 'to_validate', 'validated', 'all'];

    public function base(): Builder
    {
        return LabRequest::query()->whereNull('cancelled_at')->whereHas('items');
    }

    public function scope(Builder $query, string $view): Builder
    {
        $open = [LabItemStatus::Pending->value, LabItemStatus::InProgress->value];
        $status = fn (array $values) => fn ($items) => $items->whereIn('status', $values);

        return match ($view) {
            'to_redo' => $query->whereHas('items', $status([LabItemStatus::ToRedo->value])),
            'to_do' => $query->whereDoesntHave('items', $status([LabItemStatus::ToRedo->value]))
                ->whereHas('items', $status($open)),
            'to_validate' => $query->whereDoesntHave('items', $status([...$open, LabItemStatus::ToRedo->value]))
                ->whereHas('items', $status([LabItemStatus::Completed->value])),
            'validated' => $query->whereDoesntHave('items', fn ($items) => $items->where('status', '!=', LabItemStatus::Validated->value)),
            default => $query,
        };
    }

    /** @return array<string, int> */
    public function counts(?callable $refine = null): array
    {
        $counts = [];
        foreach (self::VIEWS as $view) {
            $query = $this->scope($this->base(), $view);
            if ($refine) {
                $refine($query);
            }
            $counts[$view] = $query->count();
        }

        return $counts;
    }

    /** L'état d'une demande dans la file, lu sur ses analyses. */
    public static function stateOf(LabRequest $request): string
    {
        $statuses = $request->items->map(fn ($item) => $item->currentStatus());

        return match (true) {
            $statuses->contains(LabItemStatus::ToRedo) => 'to_redo',
            $statuses->contains(LabItemStatus::Pending) || $statuses->contains(LabItemStatus::InProgress) => 'to_do',
            $statuses->contains(LabItemStatus::Completed) => 'to_validate',
            default => 'validated',
        };
    }
}
