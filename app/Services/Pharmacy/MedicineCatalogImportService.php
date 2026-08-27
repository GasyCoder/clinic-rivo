<?php

namespace App\Services\Pharmacy;

use App\Actions\Pharmacy\CreateMedicineProductAction;
use App\Enums\MedicineForm;
use App\Models\MedicineCategory;
use App\Models\MedicineSupplier;
use App\Models\User;
use App\Services\Spreadsheet\ExcelWorkbook;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MedicineCatalogImportService
{
    public const HEADERS = [
        'code', 'nom_commercial', 'dci', 'forme', 'dosage', 'unite',
        'laboratoire', 'code_barres', 'categorie_code', 'fournisseurs_codes',
        'ordonnance_obligatoire', 'stock_minimum', 'prix_vente', 'motif_tarif',
        'description',
    ];

    public function __construct(
        private readonly ExcelWorkbook $workbook,
        private readonly CreateMedicineProductAction $createProduct,
    ) {}

    /** @return array{rows: int} */
    public function import(UploadedFile $file, User $actor): array
    {
        $rows = $this->workbook->rows($file);

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'Le fichier ne contient aucune ligne de médicament.']);
        }

        $missingHeaders = array_diff(self::HEADERS, array_keys($rows[0]));

        if ($missingHeaders !== []) {
            throw ValidationException::withMessages([
                'file' => 'Colonnes manquantes : '.implode(', ', $missingHeaders).'.',
            ]);
        }

        $codes = collect($rows)->map(fn (array $row) => mb_strtoupper(trim((string) $row['code'])));

        if ($codes->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['file' => 'Un même code médicament apparaît plusieurs fois dans le fichier.']);
        }

        return DB::transaction(function () use ($rows, $actor): array {
            foreach ($rows as $index => $row) {
                $line = $index + 2;
                $prescriptionRequired = $this->boolean($row['ordonnance_obligatoire'], $line);
                $category = filled($row['categorie_code'])
                    ? MedicineCategory::query()->where('code', mb_strtoupper(trim((string) $row['categorie_code'])))->first()
                    : null;

                if (filled($row['categorie_code']) && ! $category) {
                    throw ValidationException::withMessages([
                        "rows.{$index}.categorie_code" => "Ligne {$line} : la catégorie indiquée n’existe pas.",
                    ]);
                }

                $supplierCodes = collect(preg_split('/[,;]/', (string) $row['fournisseurs_codes']))
                    ->map(fn (string $code) => mb_strtoupper(trim($code)))
                    ->filter()
                    ->unique()
                    ->values();
                $suppliers = MedicineSupplier::query()->whereIn('code', $supplierCodes)->get();

                if ($suppliers->count() !== $supplierCodes->count()) {
                    throw ValidationException::withMessages([
                        "rows.{$index}.fournisseurs_codes" => "Ligne {$line} : au moins un fournisseur indiqué n’existe pas.",
                    ]);
                }

                $data = [
                    'code' => mb_strtoupper(trim((string) $row['code'])),
                    'name' => trim((string) $row['nom_commercial']),
                    'generic_name' => trim((string) $row['dci']),
                    'form' => mb_strtoupper(trim((string) $row['forme'])),
                    'strength' => trim((string) $row['dosage']),
                    'unit' => trim((string) $row['unite']),
                    'manufacturer' => filled($row['laboratoire']) ? trim((string) $row['laboratoire']) : null,
                    'barcode' => filled($row['code_barres']) ? trim((string) $row['code_barres']) : null,
                    'medicine_category_uuid' => $category?->uuid,
                    'supplier_uuids' => $suppliers->pluck('uuid')->all(),
                    'prescription_required' => $prescriptionRequired,
                    'minimum_stock' => $row['stock_minimum'],
                    'sale_price' => $row['prix_vente'],
                    'tariff_reason' => trim((string) $row['motif_tarif']),
                    'description' => filled($row['description']) ? trim((string) $row['description']) : null,
                ];
                $validator = Validator::make($data, [
                    'code' => ['required', 'string', 'max:60', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/', Rule::unique('catalog_items', 'code')],
                    'name' => ['required', 'string', 'max:255'],
                    'generic_name' => ['required', 'string', 'max:255'],
                    'form' => ['required', Rule::enum(MedicineForm::class)],
                    'strength' => ['required', 'string', 'max:100'],
                    'unit' => ['required', 'string', 'max:50'],
                    'manufacturer' => ['nullable', 'string', 'max:255'],
                    'barcode' => ['nullable', 'string', 'max:100', Rule::unique('medicines', 'barcode')],
                    'minimum_stock' => ['required', 'integer', 'min:0', 'max:1000000000'],
                    'sale_price' => ['required', 'numeric', 'gt:0', 'max:999999999999.99', 'decimal:0,2'],
                    'tariff_reason' => ['required', 'string', 'min:3', 'max:1000'],
                    'description' => ['nullable', 'string', 'max:2000'],
                ]);

                if ($validator->fails()) {
                    throw ValidationException::withMessages([
                        'file' => "Ligne {$line} : ".$validator->errors()->first(),
                    ]);
                }

                $this->createProduct->execute($data, $actor);
            }

            return ['rows' => count($rows)];
        });
    }

    private function boolean(mixed $value, int $line): bool
    {
        $normalized = mb_strtoupper(trim((string) $value));

        return match ($normalized) {
            '1', 'OUI', 'TRUE' => true,
            '0', 'NON', 'FALSE' => false,
            default => throw ValidationException::withMessages([
                'file' => "Ligne {$line} : ordonnance_obligatoire doit valoir OUI ou NON.",
            ]),
        };
    }
}
