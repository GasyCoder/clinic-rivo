<?php

namespace App\Services\Spreadsheet;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExcelWorkbook
{
    /**
     * @param  array<int, string>  $headers
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    public function download(string $filename, string $sheetTitle, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($sheetTitle, $headers, $rows): void {
            $spreadsheet = new Spreadsheet;
            $spreadsheet->getProperties()
                ->setCreator(config('rivo.brand'))
                ->setCompany(config('rivo.brand'))
                ->setTitle($sheetTitle);
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle(Str::limit($sheetTitle, 31, ''));

            foreach ($headers as $column => $header) {
                $sheet->setCellValueExplicit([$column + 1, 1], $header, DataType::TYPE_STRING);
            }

            $rowNumber = 2;

            foreach ($rows as $row) {
                foreach (array_values($row) as $column => $value) {
                    $coordinate = [$column + 1, $rowNumber];

                    if (is_int($value) || is_float($value)) {
                        $sheet->setCellValue($coordinate, $value);
                    } else {
                        $sheet->setCellValueExplicit($coordinate, (string) ($value ?? ''), DataType::TYPE_STRING);
                    }
                }

                $rowNumber++;
            }

            $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
            $lastRow = max(1, $rowNumber - 1);
            $headerRange = "A1:{$lastColumn}1";
            $dataRange = "A1:{$lastColumn}{$lastRow}";

            $sheet->getStyle($headerRange)->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '334155']],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getStyle($dataRange)->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_HAIR)
                ->getColor()->setRGB('DCE3EC');
            $sheet->getRowDimension(1)->setRowHeight(24);
            $sheet->freezePane('A2');
            $sheet->setAutoFilter($headerRange);

            foreach (range(1, count($headers)) as $column) {
                $letter = Coordinate::stringFromColumnIndex($column);
                $sheet->getColumnDimension($letter)->setAutoSize(true);
            }

            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, Str::finish($filename, '.xlsx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0, must-revalidate',
        ]);
    }

    /**
     * Read the first sheet and return rows keyed by normalized header names.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(UploadedFile $file): array
    {
        $reader = IOFactory::createReaderForFile($file->getRealPath());
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($file->getRealPath());
        $values = $spreadsheet->getActiveSheet()->toArray('', false, false, false);
        $spreadsheet->disconnectWorksheets();

        if ($values === [] || ! isset($values[0])) {
            return [];
        }

        $headers = collect($values[0])->map(fn ($header) => $this->normalizeHeader((string) $header))->all();

        return collect(array_slice($values, 1))
            ->map(function (array $row) use ($headers): array {
                $result = [];

                foreach ($headers as $index => $header) {
                    if ($header !== '') {
                        $result[$header] = $row[$index] ?? null;
                    }
                }

                return $result;
            })
            ->filter(fn (array $row) => collect($row)->contains(fn ($value) => trim((string) $value) !== ''))
            ->values()
            ->all();
    }

    public function normalizeHeader(string $header): string
    {
        return Str::of($header)
            ->replace("\xEF\xBB\xBF", '')
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->toString();
    }
}
