<?php

namespace App\Services\Pharmacy;

use App\Enums\SupplierCatalogFileKind;
use App\Models\SupplierCatalog;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * ADR-241 — la structure exacte d'un catalogue fournisseur, telle que le
 * fichier la porte : ses feuilles, ses colonnes et leur type, les valeurs
 * qu'une colonne prend, les lignes qui ouvrent une section. Rien n'est
 * ramené aux colonnes que RIVO sait importer : c'est le fournisseur qui fait
 * foi, et le prompt généré le dit.
 */
class SupplierCatalogStructure
{
    /** Au-delà, une feuille est assez lue pour en dire la forme. */
    private const MAX_ROWS = 5000;

    private const MAX_SHEETS = 10;

    private const MAX_COLUMNS = 60;

    private const SAMPLES = 5;

    /** Une colonne qui prend au plus autant de valeurs est une liste de choix. */
    private const MAX_OPTIONS = 15;

    private const MAX_SECTIONS = 40;

    /** @return array<string, mixed> */
    public function describe(SupplierCatalog $catalog): array
    {
        $catalog->loadMissing('supplier:id,name,code');

        $file = [
            'name' => $catalog->original_name,
            'kind' => $catalog->kind?->value,
            'kind_label' => $catalog->kind?->label(),
            'size' => $catalog->size,
            'catalog_date' => $catalog->catalog_date?->toDateString(),
            'supplier' => $catalog->supplier?->name,
        ];

        if ($catalog->kind !== SupplierCatalogFileKind::Excel) {
            return ['file' => $file, 'readable' => false, 'reason' => 'Un catalogue PDF n’est pas lu par RIVO : seule son identité est connue.', 'sheets' => []];
        }

        if (! Storage::disk('local')->exists($catalog->path)) {
            throw ValidationException::withMessages(['file' => 'Le fichier du catalogue est introuvable.']);
        }

        try {
            $path = Storage::disk('local')->path($catalog->path);
            $reader = IOFactory::createReaderForFile($path);
            $spreadsheet = $reader->load($path);
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => 'Le fichier du catalogue ne se lit pas comme un classeur Excel.']);
        }

        $sheets = [];

        foreach (array_slice($spreadsheet->getAllSheets(), 0, self::MAX_SHEETS) as $sheet) {
            $sheets[] = $this->sheet($sheet);
        }

        $count = $spreadsheet->getSheetCount();
        $spreadsheet->disconnectWorksheets();

        return ['file' => $file + ['sheet_count' => $count], 'readable' => true, 'reason' => null, 'sheets' => $sheets];
    }

    /** @return array<string, mixed> */
    private function sheet(Worksheet $sheet): array
    {
        $highestRow = min($sheet->getHighestDataRow(), self::MAX_ROWS + 10);
        $highestColumn = min(Coordinate::columnIndexFromString($sheet->getHighestDataColumn()), self::MAX_COLUMNS);

        /** @var array<int, array<int, array{value: mixed, date: bool, bold: bool}>> $rows */
        $rows = [];

        for ($row = 1; $row <= $highestRow; $row++) {
            $cells = [];

            for ($column = 1; $column <= $highestColumn; $column++) {
                $cell = $sheet->getCell([$column, $row]);
                $value = $cell->getCalculatedValue();

                if ($value === null || (is_string($value) && trim($value) === '')) {
                    continue;
                }

                $cells[$column] = [
                    'value' => $value,
                    'date' => is_numeric($value) && ExcelDate::isDateTime($cell),
                    'bold' => (bool) $cell->getStyle()->getFont()->getBold(),
                ];
            }

            if ($cells !== []) {
                $rows[$row] = $cells;
            }
        }

        $headerRow = $this->headerRow($rows);
        $merged = array_values(array_slice($sheet->getMergeCells(), 0, 20));

        if ($headerRow === null) {
            return [
                'name' => $sheet->getTitle(),
                'header_row' => null,
                'data_rows' => 0,
                'columns' => [],
                'sections' => [],
                'merged_ranges' => $merged,
                'note' => 'Aucune ligne d’en-têtes reconnue : la feuille est vide ou n’a qu’une colonne.',
            ];
        }

        $headers = [];

        foreach ($rows[$headerRow] as $column => $cell) {
            $headers[$column] = trim((string) $cell['value']);
        }

        $columns = [];
        $sections = [];
        $dataRows = 0;
        $values = array_fill_keys(array_keys($headers), []);
        $preamble = [];

        foreach ($rows as $row => $cells) {
            if ($row < $headerRow) {
                $preamble[] = $this->text(reset($cells)['value']);

                continue;
            }

            if ($row === $headerRow) {
                continue;
            }

            // Une ligne qui ne remplit qu'une cellule ouvre une section : une
            // famille, une marque, une rubrique du fournisseur.
            if (count($cells) === 1 && count($headers) > 1) {
                if (count($sections) < self::MAX_SECTIONS) {
                    $cell = reset($cells);
                    $sections[] = ['row' => $row, 'column' => Coordinate::stringFromColumnIndex(key($cells)), 'label' => $this->text($cell['value']), 'bold' => $cell['bold']];
                }

                continue;
            }

            $dataRows++;

            foreach ($cells as $column => $cell) {
                if (isset($headers[$column])) {
                    $values[$column][] = $cell;
                } else {
                    $headers[$column] = '';
                    $values[$column] = [$cell];
                }
            }
        }

        ksort($headers);

        foreach ($headers as $column => $header) {
            $columns[] = $this->column($column, $header, $values[$column] ?? [], $dataRows);
        }

        return [
            'name' => $sheet->getTitle(),
            'header_row' => $headerRow,
            'preamble' => array_values(array_filter($preamble)),
            'data_rows' => $dataRows,
            'truncated' => $sheet->getHighestDataRow() > self::MAX_ROWS + 10,
            'columns' => $columns,
            'sections' => $sections,
            'merged_ranges' => $merged,
            'note' => null,
        ];
    }

    /**
     * @param  array<int, array{value: mixed, date: bool, bold: bool}>  $cells
     * @return array<string, mixed>
     */
    private function column(int $index, string $header, array $cells, int $dataRows): array
    {
        $texts = array_map(fn (array $cell) => $this->text($cell['value'], $cell['date']), $cells);
        $distinct = array_values(array_unique($texts));
        $filled = count($cells);

        return [
            'letter' => Coordinate::stringFromColumnIndex($index),
            'header' => $header,
            'key' => Str::of($header)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString(),
            'type' => $this->type($cells, $texts),
            'filled' => $filled,
            'empty' => max(0, $dataRows - $filled),
            'required' => $dataRows > 0 && $filled === $dataRows,
            'distinct' => count($distinct),
            'unique' => $filled > 1 && count($distinct) === $filled,
            'max_length' => $texts === [] ? 0 : max(array_map('mb_strlen', $texts)),
            'samples' => array_slice($distinct, 0, self::SAMPLES),
            'options' => $filled >= 4 && count($distinct) <= self::MAX_OPTIONS && count($distinct) * 2 <= $filled ? $distinct : null,
            'range' => $this->range($cells),
        ];
    }

    /**
     * @param  array<int, array{value: mixed, date: bool, bold: bool}>  $cells
     * @param  array<int, string>  $texts
     */
    private function type(array $cells, array $texts): string
    {
        if ($cells === []) {
            return 'vide';
        }

        $count = count($cells);
        $tally = ['date' => 0, 'integer' => 0, 'decimal' => 0, 'boolean' => 0, 'money' => 0];

        foreach ($cells as $position => $cell) {
            $value = $cell['value'];
            $text = mb_strtolower($texts[$position]);

            if ($cell['date'] || preg_match('#^\d{1,2}[/.-]\d{1,2}[/.-]\d{2,4}$#', $text)) {
                $tally['date']++;
            } elseif (is_bool($value) || in_array($text, ['oui', 'non', 'yes', 'no', 'vrai', 'faux', 'x'], true)) {
                $tally['boolean']++;
            } elseif (is_int($value) || (is_float($value) && floor($value) == $value) || preg_match('/^-?\d+$/', $text)) {
                $tally['integer']++;
            } elseif (is_float($value) || preg_match('/^-?\d+[.,]\d+$/', $text)) {
                $tally['decimal']++;
            } elseif (preg_match('/^-?[\d\s.,]+\s*(ar|mga|ariary|€|eur|\$)$/u', $text)) {
                $tally['money']++;
            }
        }

        arsort($tally);
        $winner = array_key_first($tally);

        if ($tally[$winner] >= $count * 0.9) {
            return match ($winner) {
                'date' => 'date',
                'boolean' => 'oui/non',
                'integer' => 'nombre entier',
                'decimal' => 'nombre décimal',
                'money' => 'montant avec devise',
            };
        }

        return $count * 0.5 <= $tally['integer'] + $tally['decimal'] ? 'mixte (nombres et texte)' : 'texte';
    }

    /**
     * @param  array<int, array{value: mixed, date: bool, bold: bool}>  $cells
     * @return array{min: float, max: float}|null
     */
    private function range(array $cells): ?array
    {
        $numbers = array_values(array_filter(
            array_map(fn (array $cell) => ! $cell['date'] && is_numeric($cell['value']) ? (float) $cell['value'] : null, $cells),
            fn ($value) => $value !== null,
        ));

        return count($numbers) >= max(1, (int) floor(count($cells) * 0.9)) && $numbers !== []
            ? ['min' => min($numbers), 'max' => max($numbers)]
            : null;
    }

    /**
     * La ligne d'en-têtes : la première qui remplit au moins deux cellules,
     * parmi les vingt premières.
     *
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function headerRow(array $rows): ?int
    {
        foreach (array_slice($rows, 0, 20, true) as $row => $cells) {
            if (count($cells) >= 2) {
                return $row;
            }
        }

        return null;
    }

    private function text(mixed $value, bool $date = false): string
    {
        if ($date && is_numeric($value)) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('d/m/Y');
            } catch (Throwable) {
                return (string) $value;
            }
        }

        if (is_float($value)) {
            $value = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
        }

        if (is_bool($value)) {
            return $value ? 'VRAI' : 'FAUX';
        }

        return mb_substr(Str::squish((string) $value), 0, 120);
    }
}
