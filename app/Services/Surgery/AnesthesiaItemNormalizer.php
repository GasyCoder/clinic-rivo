<?php

namespace App\Services\Surgery;

use App\Support\SurgeryReferenceData;
use Illuminate\Validation\ValidationException;

class AnesthesiaItemNormalizer
{
    /**
     * @param  array<int, array<string, mixed>>|null  $items
     * @return array<int, array<string, mixed>>|null
     */
    public function normalize(?array $items): ?array
    {
        if ($items === null) {
            return null;
        }

        $seen = [];

        return array_map(function (array $item, int $index) use (&$seen): array {
            $code = (string) ($item['reference_code'] ?? '');
            $reference = SurgeryReferenceData::anesthesiaItem($code);

            if ($reference === null) {
                throw ValidationException::withMessages([
                    "anesthetic_items.{$index}.reference_code" => 'Cet élément ne fait pas partie du référentiel Anesthésie.',
                ]);
            }

            if (isset($seen[$code])) {
                throw ValidationException::withMessages([
                    "anesthetic_items.{$index}.reference_code" => 'Chaque élément d’anesthésie ne peut apparaître qu’une fois.',
                ]);
            }
            $seen[$code] = true;

            $details = filled($item['details'] ?? null) ? trim((string) $item['details']) : null;
            if ($code === 'ANESTH-OTHER' && $details === null) {
                throw ValidationException::withMessages([
                    "anesthetic_items.{$index}.details" => 'Précisez l’élément d’anesthésie « Autres ».',
                ]);
            }

            return [
                'reference_code' => $code,
                'label_snapshot' => $reference['name'],
                'category' => $reference['category'],
                'details' => $details,
                'quantity' => $item['quantity'] ?? null,
                'unit' => filled($item['unit'] ?? null) ? trim((string) $item['unit']) : null,
            ];
        }, $items, array_keys($items));
    }
}
