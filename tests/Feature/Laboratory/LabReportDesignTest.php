<?php

namespace Tests\Feature\Laboratory;

use App\Models\AppSetting;
use App\Services\Laboratory\LabResultReport;
use App\Services\Settings\AppSettings;
use App\Support\Laboratory\LabReportDesign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Laboratory\Concerns\BuildsLabBench;
use Tests\TestCase;

/**
 * ADR-223 — l'aspect du compte rendu d'analyses, réglé par site : un site que
 * personne n'a réglé imprime le compte rendu d'origine ; chaque réglage change
 * l'aspect, jamais les résultats.
 */
class LabReportDesignTest extends TestCase
{
    use BuildsLabBench, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake(AppSettings::DISK);
        config(['rivo.brand' => 'Clinique Saint Georges', 'rivo.site.name' => 'Ambondromamy']);
    }

    /** Le site porte ces réglages ; le service est relu, comme à une nouvelle requête. */
    private function configure(array $values): void
    {
        AppSetting::query()->create($values);
        app()->forgetScopedInstances();
    }

    private function html(?array $draft = null): string
    {
        $report = app(LabResultReport::class)->sample($draft);

        return view('pdf.laboratory.results', ['report' => $report, 'layout' => []])->render();
    }

    public function test_an_unset_site_prints_the_original_report(): void
    {
        $design = app(LabReportDesign::class)->resolve();

        $this->assertSame('CLASSIC', $design['template']);
        $this->assertSame('SANS', $design['font']);
        $this->assertSame(100, $design['font_size']);
        $this->assertSame(LabReportDesign::DEFAULT_ACCENT, $design['accent']);
        $this->assertSame('Clinique Saint Georges', $design['heading']);
        $this->assertSame('Laboratoire d’analyses médicales — Ambondromamy', $design['subheading']);
        $this->assertSame('Clinique Saint Georges — Ambondromamy', $design['footer_text']);
        $this->assertNull($design['title']);
        $this->assertSame('LAB', $design['signatory']);
        $this->assertFalse($design['show_qr']);
        $this->assertTrue($design['show_anteriority']);
        $this->assertTrue($design['show_sent'] && $design['show_approval'] && $design['show_generated']);

        $html = $this->html();
        $this->assertStringContainsString('Antériorité', $html);
        $this->assertStringContainsString('Le responsable du laboratoire', $html);
        $this->assertStringNotContainsString('<td class="qr">', $html);
    }

    public function test_the_accent_follows_the_site_colour_until_it_is_set(): void
    {
        $this->configure(['primary_color' => '#0F766E']);
        $this->assertSame('#0F766E', app(LabReportDesign::class)->resolve()['accent']);

        AppSetting::query()->first()->update(['lab_report_accent_color' => '#7c3aed']);
        app()->forgetScopedInstances();
        $this->assertSame('#7C3AED', app(LabReportDesign::class)->resolve()['accent']);
    }

    public function test_age_and_sex_are_printed_on_one_line(): void
    {
        $html = $this->html();

        $this->assertMatchesRegularExpression('/Âge :<\/span> 28 ans<span class="pipe"> \| <\/span><span class="label">Sexe :<\/span> Féminin/u', $html);
    }

    public function test_each_line_above_the_signature_can_be_hidden(): void
    {
        $this->assertStringContainsString('Résultats envoyés au médecin par', $this->html());

        $html = $this->html(['lab_report_show_sent' => false, 'lab_report_show_generated' => false, 'lab_report_show_closing_identity' => false]);

        $this->assertStringNotContainsString('Résultats envoyés au médecin par', $html);
        $this->assertStringNotContainsString('Édité le', $html);
        $this->assertStringNotContainsString('class="identity"', $html);
        $this->assertStringContainsString('Résultats validés par', $html, 'ce qui n’est pas masqué reste');
    }

    public function test_who_signs_is_written_once(): void
    {
        $design = LabReportDesign::build([], 'Clinique', 'A', null);
        $with = fn (string $signatory) => LabResultReport::signatories([...$design, 'signatory' => $signatory], 'Dr Prescripteur', 'Dr Validateur');

        $this->assertSame([['label' => 'Le responsable du laboratoire', 'name' => null]], $with('LAB'));
        $this->assertSame([['label' => 'Le médecin', 'name' => 'Dr Validateur']], $with('PHYSICIAN'));
        $this->assertCount(2, $with('BOTH'));
        $this->assertSame([['label' => 'Le médecin', 'name' => 'Dr Prescripteur']], $with('AUTO'));
        // Sans médecin prescripteur (demande de l'accueil) : le laboratoire.
        $this->assertSame([['label' => 'Le responsable du laboratoire', 'name' => null]], LabResultReport::signatories([...$design, 'signatory' => 'AUTO'], null, 'Dr Validateur'));
    }

    public function test_automatic_signs_with_the_physician_who_requested_the_analysis(): void
    {
        $this->seedRoles();
        $this->configure(['lab_report_signatory' => 'AUTO', 'lab_report_physician_signatory' => 'Médecin prescripteur']);

        $doctor = $this->userWithRole('MEDICINE');
        $technician = $this->userWithRole('LABORATORY');
        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        $request = $this->labRequest($episode, $orientation, $doctor);
        $item = $this->requestItem($request, $this->prestation($technician, 'LAB-GLY', 'Glycémie'));

        $report = app(LabResultReport::class)->compose($request->fresh(), collect([$item->fresh()]));

        $this->assertSame([['label' => 'Médecin prescripteur', 'name' => $doctor->name]], $report['signatories']);
    }

    public function test_the_qr_code_carries_the_laboratory_number_when_enabled(): void
    {
        $this->assertNull(app(LabResultReport::class)->sample()['qr'], 'le QR est masqué d’origine');

        $report = app(LabResultReport::class)->sample(['lab_report_show_qr' => true]);
        $this->assertStringStartsWith('data:image/png;base64,', (string) $report['qr']);

        $html = view('pdf.laboratory.results', ['report' => $report, 'layout' => []])->render();
        $this->assertStringContainsString('<td class="qr">', $html);
        $this->assertStringContainsString('EX-L26-00001', $html);
    }

    public function test_the_anteriority_column_can_be_removed(): void
    {
        $html = $this->html(['lab_report_show_anteriority' => false]);

        $this->assertStringNotContainsString('Antériorité', $html);
        $this->assertStringContainsString('colspan="3"', $html);
    }

    public function test_the_footer_carries_its_own_text_and_website(): void
    {
        $html = $this->html([
            'lab_report_footer_text' => 'Laboratoire Saint Georges',
            'lab_report_website' => 'www.exemple.mg',
            'lab_report_show_footer_patient' => false,
        ]);

        $this->assertMatchesRegularExpression('/<div id="footer">Laboratoire Saint Georges · www\.exemple\.mg<\/div>/u', $html);
        $this->assertStringNotContainsString('id="footer">Laboratoire Saint Georges · Mme', $html);

        $this->assertStringNotContainsString('id="footer"', $this->html(['lab_report_show_footer' => false]));
    }

    public function test_the_header_texts_are_those_that_were_set(): void
    {
        $html = $this->html([
            'lab_report_heading' => 'Laboratoire de la Clinique',
            'lab_report_subheading' => 'Biologie médicale',
            'lab_report_title' => 'Compte rendu d’analyses',
            'lab_report_show_contacts' => false,
        ]);

        $this->assertStringContainsString('Laboratoire de la Clinique', $html);
        $this->assertStringContainsString('Biologie médicale', $html);
        $this->assertStringContainsString('class="doc-title accent">Compte rendu d’analyses</div>', $html);
    }

    public function test_every_template_renders_a_pdf(): void
    {
        $report = app(LabResultReport::class);

        foreach (LabReportDesign::CHOICES['lab_report_template'] as $template) {
            $pdf = $report->render($report->sample([
                'lab_report_template' => $template,
                'lab_report_font' => 'SERIF',
                'lab_report_font_size' => 110,
                'lab_report_section_background' => '#1E3A8A',
                'lab_report_zebra' => true,
                'lab_report_signatory' => 'BOTH',
                'lab_report_show_qr' => true,
            ]));

            $this->assertStringStartsWith('%PDF', $pdf, $template);
        }
    }

    public function test_readable_ink_on_a_background(): void
    {
        $this->assertSame('#FFFFFF', LabReportDesign::inkOn('#1E3A8A'));
        $this->assertSame(LabReportDesign::DEFAULT_TEXT, LabReportDesign::inkOn('#FEF3C7'));
    }
}
