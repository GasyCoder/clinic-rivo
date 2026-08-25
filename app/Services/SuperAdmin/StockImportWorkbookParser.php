<?php

namespace App\Services\SuperAdmin;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

class StockImportWorkbookParser
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, int|string|null>>
     */
    public function parse(array $rows): array
    {
        if (count($rows) > 500) {
            throw ValidationException::withMessages(['file' => 'Un import de stock est limité à 500 lignes.']);
        }

        $parsed = collect($rows)->map(function (array $row, int $index): ?array {
            $line = $index + 2;
            $code = str((string) ($row['code_medicament'] ?? ''))->squish()->upper()->toString();
            $lot = str((string) ($row['numero_de_lot'] ?? $row['numero_lot'] ?? ''))->squish()->toString();
            $operation = $this->operation((string) ($row['operation'] ?? $row['type_mouvement'] ?? ''));
            $quantity = filter_var($row['quantite'] ?? $row['quantite_mouvement'] ?? null, FILTER_VALIDATE_INT);
            $reason = str((string) ($row['motif'] ?? ''))->squish()->toString();

            if ($code === '' && $lot === '' && $operation === null && blank($row['quantite'] ?? null)) {
                return null;
            }

            $errors = [];

            if ($code === '') {
                $errors[] = 'code médicament manquant';
            }

            if ($lot === '') {
                $errors[] = 'numéro de lot manquant';
            }

            if ($operation === null) {
                $errors[] = 'opération attendue : STOCK_INITIAL ou ENTREE';
            }

            if ($quantity === false || $quantity < 1 || $quantity > 1_000_000_000) {
                $errors[] = 'quantité entière attendue entre 1 et 1 000 000 000';
            }

            if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
                $errors[] = 'motif attendu entre 3 et 500 caractères';
            }

            $receivedAt = $this->date($row['date_reception'] ?? null, $line, 'date de réception', false);
            $expiresAt = $this->date($row['date_peremption'] ?? null, $line, 'date de péremption', true);

            if ($expiresAt !== null && CarbonImmutable::parse($expiresAt)->isBefore(CarbonImmutable::today())) {
                $errors[] = 'un lot déjà périmé ne peut pas entrer en stock';
            }

            if ($errors !== []) {
                throw ValidationException::withMessages([
                    'file' => sprintf('Ligne %d : %s.', $line, implode(' ; ', $errors)),
                ]);
            }

            return [
                'code_medicament' => $code,
                'numero_lot' => $lot,
                'operation' => $operation,
                'quantite' => (int) $quantity,
                'date_reception' => $receivedAt,
                'date_peremption' => $expiresAt,
                'motif' => $reason,
            ];
        })->filter()->values();

        if ($parsed->isEmpty()) {
            throw ValidationException::withMessages([
                'file' => 'Le fichier Excel ne contient aucune ligne de stock à importer.',
            ]);
        }

        $duplicate = $parsed->groupBy(fn (array $row) => $row['code_medicament'].'|'.mb_strtolower($row['numero_lot']))
            ->first(fn ($group) => $group->count() > 1);

        if ($duplicate !== null) {
            throw ValidationException::withMessages([
                'file' => 'Un même médicament et numéro de lot ne doit apparaître qu’une fois par fichier.',
            ]);
        }

        return $parsed->all();
    }

    private function operation(string $value): ?string
    {
        return match (str($value)->ascii()->squish()->upper()->replace(' ', '_')->toString()) {
            'STOCK_INITIAL', 'INITIAL', 'OUVERTURE', 'OPENING' => 'STOCK_INITIAL',
            'ENTREE', 'ENTRY' => 'ENTREE',
            default => null,
        };
    }

    private function date(mixed $value, int $line, string $label, bool $required): ?string
    {
        if (blank($value)) {
            if ($required) {
                throw ValidationException::withMessages(['file' => sprintf('Ligne %d : %s manquante.', $line, $label)]);
            }

            return null;
        }

        if (is_numeric($value)) {
            try {
                return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
            } catch (Throwable) {
                throw ValidationException::withMessages([
                    'file' => sprintf('Ligne %d : %s invalide, format attendu JJ/MM/AAAA.', $line, $label),
                ]);
            }
        }

        $raw = trim((string) $value);

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $raw);

                if ($date !== false && $date->format($format) === $raw) {
                    return $date->toDateString();
                }
            } catch (Throwable) {
                continue;
            }
        }

        throw ValidationException::withMessages([
            'file' => sprintf('Ligne %d : %s invalide, format attendu JJ/MM/AAAA.', $line, $label),
        ]);
    }
}
