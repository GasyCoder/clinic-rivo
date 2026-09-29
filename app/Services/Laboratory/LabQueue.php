<?php

namespace App\Services\Laboratory;

use App\Enums\LabItemStatus;
use App\Models\LabRequest;
use Illuminate\Database\Eloquent\Builder;

/**
 * ADR-213 — la file du Laboratoire, par demande. Chaque demande est dans une
 * seule vue, lue sur l'état de ses analyses, par ordre de priorité :
 *
 *   to_receive   pas encore réceptionnée au laboratoire (ADR-214)
 *   to_redo      une analyse renvoyée à refaire
 *   to_do        une analyse à analyser ou en cours
 *   to_validate  tout est rendu, une analyse attend d'être envoyée au médecin (ADR-216)
 *   validated    toutes les analyses sont envoyées
 *
 * Une demande retirée par le prescripteur n'y est jamais (ADR-079).
 */
class LabQueue
{
    public const VIEWS = ['to_receive', 'to_do', 'to_redo', 'to_validate', 'validated', 'all'];

    public function base(): Builder
    {
        return LabRequest::query()->whereNull('cancelled_at')->whereHas('items');
    }

    public function scope(Builder $query, string $view): Builder
    {
        $open = [LabItemStatus::Pending->value, LabItemStatus::InProgress->value];
        $status = fn (array $values) => fn ($items) => $items->whereIn('status', $values);

        $received = fn (Builder $query) => $query->whereNotNull('received_at');

        return match ($view) {
            'to_receive' => $query->whereNull('received_at'),
            'to_redo' => $received($query)->whereHas('items', $status([LabItemStatus::ToRedo->value])),
            'to_do' => $received($query)->whereDoesntHave('items', $status([LabItemStatus::ToRedo->value]))
                ->whereHas('items', $status($open)),
            'to_validate' => $received($query)->whereDoesntHave('items', $status([...$open, LabItemStatus::ToRedo->value]))
                ->whereHas('items', $status([LabItemStatus::Completed->value])),
            'validated' => $received($query)->whereDoesntHave('items', fn ($items) => $items->where('status', '!=', LabItemStatus::Validated->value)),
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

    /**
     * ADR-214 — les demandes dont une analyse est confiée à un laboratoire
     * extérieur et attend encore son résultat. Un filtre, pas une vue : la
     * demande reste dans la vue de son état.
     */
    public function sentOut(Builder $query): Builder
    {
        return $query->whereHas('items', fn ($items) => $items->whereNotNull('sent_out_at')
            ->whereIn('status', [LabItemStatus::Pending->value, LabItemStatus::InProgress->value, LabItemStatus::ToRedo->value]));
    }

    /** L'état d'une demande dans la file, lu sur ses analyses. */
    public static function stateOf(LabRequest $request): string
    {
        if ($request->received_at === null) {
            return 'to_receive';
        }

        $statuses = $request->items->map(fn ($item) => $item->currentStatus());

        return match (true) {
            $statuses->contains(LabItemStatus::ToRedo) => 'to_redo',
            $statuses->contains(LabItemStatus::Pending) || $statuses->contains(LabItemStatus::InProgress) => 'to_do',
            $statuses->contains(LabItemStatus::Completed) => 'to_validate',
            default => 'validated',
        };
    }
}
