<?php

namespace App\Actions\Pharmacy;

use App\Models\Medicine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-098 — a delivery of several medicines recorded at once. Each line goes
 * through the unchanged single-entry Action (lot rules, expiry, supplier,
 * immutable movement); if one line is refused, nothing is recorded, and the
 * refusal names the line so the pharmacist corrects it in the list.
 */
class RecordStockEntriesAction
{
    public function __construct(private readonly RecordStockEntryAction $entry) {}

    /**
     * @param  array<string, mixed>  $delivery  received_at, supplier_uuid, origin, destination, reason
     * @param  array<int, array<string, mixed>>  $entries
     */
    public function execute(array $delivery, array $entries, User $actor): int
    {
        return DB::transaction(function () use ($delivery, $entries, $actor): int {
            foreach (array_values($entries) as $index => $line) {
                try {
                    $this->entry->execute([...$delivery, ...$line], $actor);
                } catch (ValidationException $exception) {
                    $name = Medicine::query()->with('catalogItem:id,name')->where('uuid', $line['medicine_uuid'])->first()?->catalogItem?->name ?? 'Médicament';

                    throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(
                        fn (array $messages, string $field) => ["entries.{$index}.{$field}" => sprintf('Ligne %d (%s) : %s', $index + 1, $name, $messages[0])],
                    )->all());
                }
            }

            return count($entries);
        });
    }
}
