<?php

namespace App\Services\Pharmacy;

use App\Enums\SupplierCatalogFileKind;
use App\Models\SupplierCatalog;
use App\Services\Catalog\CatalogActor;
use App\Services\Spreadsheet\ExcelWorkbook;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * ADR-097 — spec §3: parses an already-uploaded Excel supplier catalog into
 * raw SupplierCatalogItem rows (Référence | Médicament | Présentation |
 * Prix fournisseur). Deliberately NOT the clinic stock/catalog — rows stay
 * unlinked until an explicit LinkSupplierCatalogItemAction. PDF catalogs
 * are never parsed. The import validates everything first, then writes in
 * one transaction and aborts the whole file on any error.
 *
 * ADR-098 — preview() runs the very same checks without writing anything,
 * so what the pharmacist verifies is exactly what import() will accept.
 */
class SupplierCatalogImportService
{
    /**
     * Columns the file must carry. « Famille » is deliberately absent: the
     * catalogues already distributed do not have it, and a supplier who
     * does not classify its products must still be importable.
     */
    public const HEADERS = ['reference', 'medicament', 'presentation', 'prix_fournisseur'];

    public const HEADER_LABELS = [
        'reference' => 'Référence',
        'medicament' => 'Médicament',
        'presentation' => 'Présentation',
        'famille' => 'Famille',
        'prix_fournisseur' => 'Prix fournisseur',
    ];

    /** Which column of the file each validated field comes from. */
    private const COLUMN_OF = [
        'reference' => 'reference',
        'medicine_label' => 'medicament',
        'presentation' => 'presentation',
        'family_label' => 'famille',
        'supplier_price' => 'prix_fournisseur',
    ];

    public function __construct(private readonly ExcelWorkbook $workbook) {}

    /**
     * @return array{
     *   expected_headers: array<int, string>,
     *   missing_headers: array<int, string>,
     *   rows: array<int, array<string, mixed>>,
     *   valid_count: int,
     *   invalid_count: int,
     *   error: ?string
     * }
     */
    public function preview(SupplierCatalog $catalog): array
    {
        $result = [
            'expected_headers' => array_values(self::HEADER_LABELS),
            'missing_headers' => [],
            'rows' => [],
            'valid_count' => 0,
            'invalid_count' => 0,
            'error' => null,
        ];

        try {
            $rows = $this->readRows($catalog);
        } catch (ValidationException $exception) {
            return [...$result, 'error' => collect($exception->errors())->flatten()->first()];
        }

        if ($rows === []) {
            return [...$result, 'error' => 'Le fichier ne contient aucune ligne de produit.'];
        }

        $missing = array_values(array_diff(self::HEADERS, array_keys($rows[0])));

        if ($missing !== []) {
            return [...$result, 'missing_headers' => array_map(fn (string $header) => self::HEADER_LABELS[$header], $missing)];
        }

        $checked = [];

        foreach ($rows as $index => $row) {
            $check = $this->checkRow($row, $index + 2);
            $checked[] = [
                'row_number' => $check['row_number'],
                ...$check['data'],
                'errors' => $check['errors'],
                // Column, value read and reason: enough to fix the file
                // without hunting for what the line is missing.
                'issues' => $check['issues'],
            ];
        }

        $invalid = count(array_filter($checked, fn (array $row) => $row['errors'] !== []));

        return [
            ...$result,
            'rows' => $checked,
            'valid_count' => count($checked) - $invalid,
            'invalid_count' => $invalid,
        ];
    }

    /** @return array{rows: int} */
    public function import(SupplierCatalog $catalog, CatalogActor $actor): array
    {
        $rows = $this->readRows($catalog);

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'Le fichier ne contient aucune ligne de produit.']);
        }

        $missingHeaders = array_diff(self::HEADERS, array_keys($rows[0]));

        if ($missingHeaders !== []) {
            throw ValidationException::withMessages([
                'file' => 'Colonnes manquantes : '.implode(', ', $missingHeaders).'.',
            ]);
        }

        $errors = [];
        $validatedRows = [];

        foreach ($rows as $index => $row) {
            $check = $this->checkRow($row, $index + 2);

            if ($check['errors'] !== []) {
                $issue = $check['issues'][0];
                $read = $issue['value'] === '' ? 'vide' : '« '.$issue['value'].' »';
                $errors[] = "Ligne {$check['row_number']}, colonne « {$issue['column']} » ({$read}) : {$issue['message']}";

                if (count($errors) >= 20) {
                    break;
                }

                continue;
            }

            $validatedRows[] = ['row_number' => $check['row_number'], ...$check['data']];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['file' => $errors]);
        }

        return DB::transaction(function () use ($catalog, $validatedRows, $actor): array {
            $catalog = SupplierCatalog::query()->lockForUpdate()->findOrFail($catalog->id);

            // What the clinic had already taken up from this file, kept by
            // the supplier's own reference. Re-reading a price list must not
            // undo the work of linking its products to the clinic catalogue.
            $linked = $catalog->items()->withTrashed()
                ->whereNotNull('linked_medicine_id')
                ->pluck('linked_medicine_id', 'reference');

            // Re-importing a catalog replaces its previously parsed rows —
            // a re-read of the same file, not a second, competing source.
            // Physically, not to the bin: a hundred withdrawn lines per
            // re-read would be noise, and these rows carry no history of
            // their own. Offers already created from a row keep theirs
            // (medicine_supplier_offers is append-only, nullOnDelete).
            $catalog->items()->withTrashed()->forceDelete();

            foreach ($validatedRows as $row) {
                $catalog->items()->create([
                    'row_number' => $row['row_number'],
                    'reference' => $row['reference'],
                    'medicine_label' => $row['medicine_label'],
                    'presentation' => $row['presentation'],
                    'family_label' => $row['family_label'],
                    'supplier_price' => $row['supplier_price'],
                    'linked_medicine_id' => $linked[$row['reference']] ?? null,
                    // A portal import has no local author; the audit log
                    // keeps the remote Super Admin (ADR-098).
                    'created_by' => $actor->localUserId(),
                ]);
            }

            $catalog->update(['imported_at' => now(), 'updated_by' => $actor->localUserId()]);

            return ['rows' => count($validatedRows)];
        });
    }

    /** @return array<int, array<string, mixed>> */
    private function readRows(SupplierCatalog $catalog): array
    {
        if ($catalog->kind !== SupplierCatalogFileKind::Excel) {
            throw ValidationException::withMessages([
                'file' => 'Seul un catalogue Excel peut être analysé automatiquement — ce fichier est un PDF.',
            ]);
        }

        if (! Storage::disk('local')->exists($catalog->path)) {
            throw ValidationException::withMessages(['file' => 'Le fichier du catalogue est introuvable.']);
        }

        return $this->workbook->rows(new UploadedFile(
            Storage::disk('local')->path($catalog->path),
            $catalog->original_name,
            $catalog->mime_type,
            null,
            true,
        ));
    }

    /**
     * A supplier catalogue is first the list of what the supplier proposes:
     * its price may arrive separately, or later. A row without a price is
     * therefore imported (ADR-098 amended) — it is the link to a clinic
     * medicine that requires one, since that is what creates the versioned
     * purchase price.
     *
     * An error names the column, the value read and the reason: « ligne 15 »
     * alone leaves the pharmacist looking for what to fix.
     *
     * @return array{row_number: int, data: array<string, mixed>, errors: array<int, string>, issues: array<int, array<string, mixed>>}
     */
    private function checkRow(array $row, int $line): array
    {
        $raw = [
            'reference' => $row['reference'] ?? null,
            'medicine_label' => $row['medicament'] ?? null,
            'presentation' => $row['presentation'] ?? null,
            'family_label' => $row['famille'] ?? null,
            'supplier_price' => $row['prix_fournisseur'] ?? null,
        ];
        $data = [
            'reference' => filled($raw['reference']) ? trim((string) $raw['reference']) : null,
            'medicine_label' => trim((string) ($raw['medicine_label'] ?? '')),
            'presentation' => filled($raw['presentation']) ? trim((string) $raw['presentation']) : null,
            'family_label' => filled($raw['family_label']) ? trim((string) $raw['family_label']) : null,
            // "4 500,50 Ar" and "4500.50" are the same price to a
            // pharmacist; only what is left after the currency and the
            // spacing is judged as a number.
            'supplier_price' => $this->normalizePrice($raw['supplier_price']),
        ];

        $validator = Validator::make($data, [
            'reference' => ['nullable', 'string', 'max:120'],
            'medicine_label' => ['required', 'string', 'max:255'],
            'presentation' => ['nullable', 'string', 'max:255'],
            'family_label' => ['nullable', 'string', 'max:120'],
            'supplier_price' => ['nullable', 'numeric', 'gt:0', 'max:999999999999.99', 'decimal:0,2'],
        ], [
            'medicine_label.required' => 'le nom du médicament est obligatoire.',
            'supplier_price.numeric' => 'le prix doit être un nombre (sans « Ar » ni texte).',
            'supplier_price.gt' => 'le prix doit être supérieur à zéro.',
            'supplier_price.decimal' => 'le prix accepte au plus deux décimales.',
        ], [
            'reference' => 'référence',
            'medicine_label' => 'médicament',
            'presentation' => 'présentation',
            'family_label' => 'famille',
            'supplier_price' => 'prix fournisseur',
        ]);

        $failed = $validator->fails();
        $issues = [];

        foreach ($validator->errors()->messages() as $field => $messages) {
            $issues[] = [
                'column' => self::HEADER_LABELS[self::COLUMN_OF[$field]] ?? $field,
                'value' => is_scalar($raw[$field] ?? null) ? (string) $raw[$field] : '',
                'message' => $messages[0],
            ];
        }

        return [
            'row_number' => $line,
            'data' => $failed ? $data : $validator->validated(),
            'errors' => $failed ? $validator->errors()->all() : [],
            'issues' => $issues,
        ];
    }

    /** Excel gives a price as a number, a string, or a formatted amount. */
    private function normalizePrice(mixed $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        // Non-breaking spaces, thousands separators, a decimal comma and a
        // trailing currency are formatting, not a wrong price.
        $cleaned = preg_replace('/[\s\x{00A0}\x{202F}]|(?:ar|mga)$/iu', '', trim((string) $value)) ?? '';
        $cleaned = str_replace(',', '.', $cleaned);

        return $cleaned === '' ? (string) $value : $cleaned;
    }
}
