<?php

namespace Tests\Feature\Laboratory;

use App\Services\Laboratory\LabResultReport;
use Tests\TestCase;

/**
 * Le compte rendu d'analyses ne laisse jamais le bloc final (qui a envoyé, qui a
 * validé, la signature) seul sur une page : il resserre le compte rendu pour le
 * faire tenir, ou fait descendre les dernières lignes avec lui.
 */
class LabResultPaginationTest extends TestCase
{
    public function test_a_short_report_keeps_the_ordinary_layout(): void
    {
        $page = $this->paginate($this->report([$this->section('HEMATOLOGIE', [$this->analysis('Bilan', 10)])]));

        $this->assertSame([], $page['layout']);
        $this->assertSame(1, $page['pages']);
    }

    public function test_a_report_slightly_too_long_is_tightened_onto_one_page(): void
    {
        $page = $this->paginate($this->report([$this->section('HEMATOLOGIE', [$this->analysis('Bilan', 32)])]));

        $this->assertTrue($page['layout']['compact'] ?? false);
        $this->assertSame(1, $page['pages']);
        $this->assertFalse($page['orphaned']);
    }

    public function test_the_closing_block_never_stands_alone_or_nearly_alone(): void
    {
        foreach (range(28, 40, 2) as $rows) {
            $page = $this->paginate($this->report([$this->section('HEMATOLOGIE', [$this->analysis('Bilan', $rows)])]));
            $withClosing = count(array_filter($page['rows'], fn (array $row) => $row['page'] === $page['closing']));

            $this->assertFalse($page['orphaned'], "{$rows} lignes : le bloc final est seul sur sa page");
            $this->assertTrue($page['pages'] === 1 || $withClosing >= 4, "{$rows} lignes : {$withClosing} ligne(s) avec le bloc final");
        }
    }

    public function test_the_last_rows_travel_with_the_closing_block_when_tightening_is_not_enough(): void
    {
        // Des blocs de texte longs : le resserrement n'y gagne presque rien.
        $text = implode("\n", array_map(fn (int $line) => "Ligne de compte rendu {$line} — texte libre du laboratoire", range(1, 14)));
        $report = $this->report([
            $this->section('BIOCHIMIE', [$this->analysis('Analyse 1', 5), $this->analysis('Analyse 2', 0, $text)]),
            $this->section('SEROLOGIE', [$this->analysis('Analyse 3', 5), $this->analysis('Analyse 4', 0, $text)]),
        ]);

        $page = $this->paginate($report);
        $withClosing = count(array_filter($page['rows'], fn (array $row) => $row['page'] === $page['closing']));

        $this->assertArrayHasKey('break_section', $page['layout'], 'la section SEROLOGIE descend entière, titre compris');
        $this->assertSame(2, $page['pages']);
        $this->assertFalse($page['orphaned']);
        $this->assertGreaterThanOrEqual(4, $withClosing);
    }

    public function test_a_break_inside_an_analysis_names_it_again_on_the_next_page(): void
    {
        $report = $this->report([$this->section('HEMATOLOGIE', [$this->analysis('Numération formule sanguine', 10)])]);

        $html = view('pdf.laboratory.results', ['report' => $report, 'layout' => ['break_before' => 6]])->render();

        $this->assertStringContainsString('Numération formule sanguine <span class="muted">(suite)</span>', $html);
        $this->assertSame(1, substr_count($html, 'page-break-before: always'));
    }

    public function test_the_real_pdf_is_still_a_pdf(): void
    {
        $pdf = app(LabResultReport::class)->render($this->report([$this->section('HEMATOLOGIE', [$this->analysis('Bilan', 32)])]));

        $this->assertStringStartsWith('%PDF', $pdf);
    }

    /** @return array<string, mixed> */
    private function paginate(array $report): array
    {
        return app(LabResultReport::class)->paginate($report);
    }

    /** @return array<string, mixed> */
    private function report(array $sections): array
    {
        return [
            'site' => ['brand' => 'Clinique Saint Georges', 'site' => 'Ambondromamy', 'logo' => null, 'color' => '#1d4ed8',
                'nif' => null, 'stat' => null, 'address' => null, 'phone' => null, 'email' => null],
            'patient' => ['name' => 'Mme RASOA Vola', 'number' => 'A-26-001', 'since' => '01/09/2026',
                'birth_date' => null, 'age' => 30, 'sex' => 'Féminin'],
            'request' => ['lab_number' => 'A-L26-00001', 'episode_number' => 'A-26-001-001', 'requested_at' => '28/09/2026',
                'prescriber' => 'Dr RABE', 'recipient' => 'Dr RABE', 'clinical_notes' => null],
            'sections' => $sections,
            'conclusion' => null,
            'conclusion_by' => null,
            'validation' => ['by' => ['Alisa Richards'], 'at' => '29/09/2026 14:17'],
            'approval' => ['by' => ['Dr RABE'], 'at' => '29/09/2026 18:25', 'awaiting' => 0],
            'provisional' => false,
            'generated_at' => '29/09/2026 18:30',
        ];
    }

    /** @return array<string, mixed> */
    private function section(string $title, array $items): array
    {
        return ['title' => $title, 'items' => $items];
    }

    /** @return array<string, mixed> */
    private function analysis(string $name, int $rows, ?string $text = null): array
    {
        return [
            'name' => $name,
            'title_row' => true,
            'rows' => $rows === 0 ? [] : array_map(fn (int $index) => [
                'kind' => 'result', 'depth' => 1, 'designation' => "Paramètre {$index}", 'bold' => false,
                'value' => '1,2 g/L', 'pathological' => false, 'critical' => false, 'flag' => null,
                'reference' => '1 - 2', 'anteriority' => '',
            ], range(1, $rows)),
            'text' => $text,
            'sent_out' => null,
            'provisional' => false,
            'in_correction' => null,
        ];
    }
}
