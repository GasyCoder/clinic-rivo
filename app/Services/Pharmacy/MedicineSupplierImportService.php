<?php

namespace App\Services\Pharmacy;

use App\Actions\Pharmacy\CreateMedicineSupplierAction;
use App\Actions\Pharmacy\UpdateMedicineSupplierAction;
use App\Models\MedicineSupplier;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-098 — supplier list imported from Excel, in two passes that apply the
 * exact same rules: plan() says what each line would do without writing,
 * import() re-plans under lock and writes everything or nothing.
 *
 * The code identifies a supplier and never changes. An empty cell keeps the
 * current value: omitting a column must not erase a phone number. An archived
 * supplier is never silently recreated or revived by a file.
 */
class MedicineSupplierImportService
{
    public const MAX_ROWS = 1000;

    public const FIELDS = [
        'name' => 'Nom',
        'contact_name' => 'Personne à contacter',
        'phone' => 'Téléphone',
        'email' => 'E-mail',
        'address' => 'Adresse',
    ];

    private const LIMITS = ['name' => 255, 'contact_name' => 255, 'phone' => 50, 'email' => 255, 'address' => 2000];

    public function __construct(
        private readonly CreateMedicineSupplierAction $creator,
        private readonly UpdateMedicineSupplierAction $updater,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{rows: array<int, array<string, mixed>>, summary: array<string, int>}
     */
    public function plan(array $rows): array
    {
        $rows = array_values($rows);
        $codes = array_map(fn (array $row): string => $this->code($row['code'] ?? null), $rows);
        $existing = MedicineSupplier::withTrashed()
            ->whereIn('code', array_values(array_unique(array_filter($codes))))
            ->get()
            ->keyBy('code');

        $seen = [];
        $planned = [];

        foreach ($rows as $index => $row) {
            $line = (int) ($row['line'] ?? $index + 2);
            $code = $codes[$index];
            $values = $this->values($row);
            $errors = [];

            if ($code === '') {
                $errors[] = 'Le code est obligatoire.';
            } elseif (mb_strlen($code) > 60 || ! preg_match('/^[A-Z0-9][A-Z0-9._-]*$/', $code)) {
                $errors[] = 'Le code ne peut contenir que des lettres, des chiffres, « . », « - » ou « _ » (60 caractères au plus).';
            } elseif (isset($seen[$code])) {
                $errors[] = "Le code {$code} figure déjà à la ligne {$seen[$code]}.";
            } else {
                $seen[$code] = $line;
            }

            foreach (self::LIMITS as $field => $max) {
                if ($values[$field] !== null && mb_strlen($values[$field]) > $max) {
                    $errors[] = self::FIELDS[$field]." : {$max} caractères au plus.";
                }
            }

            if ($values['email'] !== null && filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = 'L’adresse e-mail n’est pas valide.';
            }

            $supplier = $code !== '' ? $existing->get($code) : null;

            if ($supplier?->trashed()) {
                $errors[] = "Le fournisseur {$code} est archivé : restaurez-le avant de l’importer.";
            } elseif (! $supplier && $values['name'] === null) {
                $errors[] = 'Le nom est obligatoire pour un nouveau fournisseur.';
            }

            $changes = [];

            if ($supplier && ! $supplier->trashed()) {
                foreach (self::FIELDS as $field => $label) {
                    if ($values[$field] !== null && $values[$field] !== $supplier->{$field}) {
                        $changes[] = ['field' => $field, 'label' => $label, 'from' => $supplier->{$field}, 'to' => $values[$field]];
                    }
                }
            }

            $planned[] = [
                'line' => $line,
                'code' => $code,
                ...$values,
                'action' => match (true) {
                    $errors !== [] => 'ERROR',
                    $supplier === null => 'CREATE',
                    $changes !== [] => 'UPDATE',
                    default => 'UNCHANGED',
                },
                'current_name' => $supplier?->name,
                'changes' => $changes,
                'errors' => $errors,
            ];
        }

        $counts = array_count_values(array_column($planned, 'action'));

        return [
            'rows' => $planned,
            'summary' => [
                'create' => $counts['CREATE'] ?? 0,
                'update' => $counts['UPDATE'] ?? 0,
                'unchanged' => $counts['UNCHANGED'] ?? 0,
                'error' => $counts['ERROR'] ?? 0,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{created: int, updated: int, unchanged: int}
     */
    public function import(array $rows, CatalogActor $actor): array
    {
        if ($actor->cannot('medicine_suppliers.import')) {
            throw new AuthorizationException('Vous ne pouvez pas importer de fournisseurs.');
        }

        return DB::transaction(function () use ($rows, $actor): array {
            // The file may have been previewed minutes ago: decide again on
            // locked rows, so a supplier archived meanwhile is not revived.
            $codes = array_values(array_unique(array_filter(array_map(
                fn (array $row): string => $this->code($row['code'] ?? null),
                $rows,
            ))));
            MedicineSupplier::withTrashed()->whereIn('code', $codes)->lockForUpdate()->get();

            $plan = $this->plan($rows);
            $invalid = array_values(array_filter($plan['rows'], fn (array $row): bool => $row['action'] === 'ERROR'));

            if ($invalid !== []) {
                throw ValidationException::withMessages([
                    'rows' => sprintf(
                        '%d ligne(s) incorrecte(s) : aucun fournisseur n’a été importé. Ligne %d : %s',
                        count($invalid),
                        $invalid[0]['line'],
                        $invalid[0]['errors'][0],
                    ),
                ]);
            }

            foreach ($plan['rows'] as $row) {
                if ($row['action'] === 'CREATE') {
                    $this->creator->execute(['code' => $row['code'], ...array_intersect_key($row, self::FIELDS)], $actor);
                }

                if ($row['action'] === 'UPDATE') {
                    $this->updater->execute(
                        MedicineSupplier::query()->where('code', $row['code'])->firstOrFail(),
                        collect($row['changes'])->pluck('to', 'field')->all(),
                        $actor,
                    );
                }
            }

            return [
                'created' => $plan['summary']['create'],
                'updated' => $plan['summary']['update'],
                'unchanged' => $plan['summary']['unchanged'],
            ];
        });
    }

    private function code(mixed $value): string
    {
        return mb_strtoupper(trim($this->text($value) ?? ''));
    }

    /** @return array<string, ?string> */
    private function values(array $row): array
    {
        return collect(self::FIELDS)
            ->mapWithKeys(fn (string $label, string $field): array => [$field => $this->text($row[$field] ?? null)])
            ->all();
    }

    private function text(mixed $value): ?string
    {
        // Excel stores a phone typed without spaces as a number.
        if (is_float($value) && floor($value) === $value) {
            $value = (string) (int) $value;
        }

        $text = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $text === '' ? null : $text;
    }
}
