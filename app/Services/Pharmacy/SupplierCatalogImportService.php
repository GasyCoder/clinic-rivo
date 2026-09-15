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
    public const HEADERS = ['reference', 'medicament', 'presentation', 'prix_fournisseur'];

    public const HEADER_LABELS = [
        'reference' => 'Référence',
        'medicament' => 'Médicament',
        'presentation' => 'Présentation',
        'prix_fournisseur' => 'Prix fournisseur',
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
            $checked[] = ['row_number' => $check['row_number'], ...$check['data'], 'errors' => $check['errors']];
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
                $errors[] = "Ligne {$check['row_number']} : {$check['errors'][0]}";

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

            // Re-importing a catalog replaces its previously parsed rows —
            // a re-read of the same file, not a second, competing source.
            // Offers already created from a row keep their history
            // (medicine_supplier_offers is append-only, nullOnDelete).
            $catalog->items()->delete();

            foreach ($validatedRows as $row) {
                $catalog->items()->create([
                    'row_number' => $row['row_number'],
                    'reference' => $row['reference'],
                    'medicine_label' => $row['medicine_label'],
                    'presentation' => $row['presentation'],
                    'supplier_price' => $row['supplier_price'],
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

    /** @return array{row_number: int, data: array<string, mixed>, errors: array<int, string>} */
    private function checkRow(array $row, int $line): array
    {
        $data = [
            'reference' => filled($row['reference'] ?? null) ? trim((string) $row['reference']) : null,
            'medicine_label' => trim((string) ($row['medicament'] ?? '')),
            'presentation' => filled($row['presentation'] ?? null) ? trim((string) $row['presentation']) : null,
            'supplier_price' => $row['prix_fournisseur'] ?? null,
        ];

        $validator = Validator::make($data, [
            'reference' => ['nullable', 'string', 'max:120'],
            'medicine_label' => ['required', 'string', 'max:255'],
            'presentation' => ['nullable', 'string', 'max:255'],
            'supplier_price' => ['required', 'numeric', 'gt:0', 'max:999999999999.99', 'decimal:0,2'],
        ], [], [
            'reference' => 'référence',
            'medicine_label' => 'médicament',
            'presentation' => 'présentation',
            'supplier_price' => 'prix fournisseur',
        ]);

        $failed = $validator->fails();

        return [
            'row_number' => $line,
            'data' => $failed ? $data : $validator->validated(),
            'errors' => $failed ? $validator->errors()->all() : [],
        ];
    }
}
