<?php

namespace Tests\Feature\Administration;

use App\Enums\HrReferenceType;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\HrReferenceValue;
use App\Models\Role;
use App\Models\User;
use App\Services\Settings\AppSettings;
use App\Support\Hr\BadgeDesign;
use Database\Seeders\HrReferenceSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * ADR-209 — le badge du personnel : un seul modèle pour tout le personnel et les
 * stagiaires, lu dans le dossier, dont l'apparence se règle par site.
 */
class EmployeeBadgeTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/v1/super-admin/app-settings';

    private User $administration;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(AppSettings::DISK);
        config([
            'rivo.site.type' => 'clinic',
            'rivo.site.code' => 'A',
            'rivo.site.name' => 'Ambondromamy',
            'rivo.site_api.token' => 'clinic-test-token',
        ]);

        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, HrReferenceSeeder::class]);
        $this->administration = User::factory()->create(['role_id' => Role::query()->where('code', 'ADMINISTRATION')->value('id')]);
    }

    public function test_a_badge_is_read_from_the_file_with_the_clinic_design_by_default(): void
    {
        $employee = $this->employee('EMP-0001', 'Rakotoarisoa', 'Hanitra', 'Médecine', 'Médecin', ['badge' => 'B-17']);

        $this->actingAs($this->administration)->get("/administration/employees/{$employee->uuid}/badge")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Administration/Employees/Badges')
                ->has('badges', 1)
                ->where('badges.0.first_name', 'Hanitra')
                ->where('badges.0.last_name', 'Rakotoarisoa')
                ->where('badges.0.department', 'Médecine')
                ->where('badges.0.job_title', 'Médecin')
                // Le code stable choisit l'icône, jamais le libellé.
                ->where('badges.0.department_code', 'MEDICINE')
                ->where('badges.0.job_title_code', 'DOCTOR')
                ->where('badges.0.badge_number', 'B-17')
                ->where('badges.0.is_intern', false)
                ->where('design.primary', BadgeDesign::DEFAULT_PRIMARY)
                ->where('design.accent', BadgeDesign::DEFAULT_ACCENT)
                ->where('design.tagline', BadgeDesign::DEFAULT_TAGLINE)
                ->where('design.emblem_url', BadgeDesign::DEFAULT_EMBLEM_URL)
                ->where('design.seal.top', 'CLINIQUE')
                // Jamais réglé : le badge d'avant, en planche A4 portrait, et un badge seul suit ce papier.
                ->where('design.orientation', 'PORTRAIT')
                ->where('design.show_site', false)
                ->where('design.print.paper', 'A4')
                ->where('design.print.orientation', 'PORTRAIT')
                ->where('design.print.margin', 8)
                ->where('design.print.cut_marks', true)
                ->where('layout', null));

        // La fiche montre le même badge.
        $this->actingAs($this->administration)->get("/administration/employees/{$employee->uuid}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('badge.person.uuid', $employee->uuid)
                ->where('badge.design.primary', BadgeDesign::DEFAULT_PRIMARY));
    }

    public function test_an_archived_file_has_no_badge_and_printing_needs_the_right(): void
    {
        $employee = $this->employee('EMP-0001', 'Rabe');
        $employee->delete();

        $this->actingAs($this->administration)->get("/administration/employees/{$employee->uuid}/badge")->assertNotFound();
        $this->actingAs($this->administration)->get("/administration/employees/{$employee->uuid}")
            ->assertInertia(fn (Assert $page) => $page->where('badge', null));

        $active = $this->employee('EMP-0002', 'Rakoto');
        $reception = User::factory()->create(['role_id' => Role::query()->where('code', 'RECEPTION')->value('id')]);
        $this->actingAs($reception)->get("/administration/employees/{$active->uuid}/badge")->assertForbidden();
        $this->actingAs($reception)->get('/administration/employees/badges')->assertForbidden();
    }

    public function test_the_sheet_follows_the_list_filters_on_every_page_or_the_checked_files(): void
    {
        $rabe = $this->employee('EMP-0001', 'Rabe');
        $rakoto = $this->employee('EMP-0002', 'Rakoto');
        $away = $this->employee('EMP-0003', 'Randria', active: false);
        $archived = $this->employee('EMP-0004', 'Razafy');
        $archived->delete();
        $intern = $this->employee('EMP-0005', 'Tojo');
        $this->internship($intern, 'Sage-femme');

        // Tous les actifs, stagiaires exclus : ce que la liste « Employés » affiche.
        $this->actingAs($this->administration)->get('/administration/employees/badges?status=active')
            ->assertInertia(fn (Assert $page) => $page
                ->where('source.kind', 'employees')
                ->where('badges', fn ($badges) => collect($badges)->pluck('uuid')->sort()->values()->all() === collect([$rabe->uuid, $rakoto->uuid])->sort()->values()->all()));

        // La recherche suit.
        $this->actingAs($this->administration)->get('/administration/employees/badges?status=all&q=Rakoto')
            ->assertInertia(fn (Assert $page) => $page->where('badges', fn ($badges) => collect($badges)->pluck('uuid')->all() === [$rakoto->uuid]));

        // « Tous » garde les inactifs, jamais un dossier archivé.
        $this->actingAs($this->administration)->get('/administration/employees/badges?status=all')
            ->assertInertia(fn (Assert $page) => $page->where('badges', fn ($badges) => collect($badges)->pluck('uuid')->contains($away->uuid)
                && ! collect($badges)->pluck('uuid')->contains($archived->uuid)));
        $this->actingAs($this->administration)->get('/administration/employees/badges?status=archived')
            ->assertInertia(fn (Assert $page) => $page->has('badges', 0));

        // Les dossiers cochés, et eux seuls.
        $this->actingAs($this->administration)->get('/administration/employees/badges?'.http_build_query(['uuids' => [$rabe->uuid, $archived->uuid]]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('source.label', 'selection')
                ->where('badges', fn ($badges) => collect($badges)->pluck('uuid')->all() === [$rabe->uuid]));
    }

    public function test_an_intern_badge_says_intern_with_the_field_and_the_end_of_the_internship(): void
    {
        $intern = $this->employee('EMP-0005', 'Andrianasolo', 'Tojo', 'Maternité');
        $this->internship($intern, 'Sage-femme');
        $this->employee('EMP-0001', 'Rabe');

        $this->actingAs($this->administration)->get('/administration/internships/badges?status=current')
            ->assertInertia(fn (Assert $page) => $page
                ->where('source.kind', 'interns')
                ->has('badges', 1)
                ->where('badges.0.uuid', $intern->uuid)
                ->where('badges.0.is_intern', true)
                ->where('badges.0.internship.field', 'Sage-femme')
                ->where('badges.0.internship.field_code', 'MIDWIFERY')
                ->where('badges.0.internship.ends_on', now()->addMonth()->toDateString()));
    }

    public function test_the_design_is_set_per_site_and_the_emblem_is_served_to_the_hr(): void
    {
        $this->withHeaders($this->headers(['settings.update', 'settings.view']))
            ->putJson(self::API, [
                ...$this->validSettings(),
                'badge_primary_color' => '#0a5c36',
                'badge_accent_color' => '#E11D48',
                'badge_tagline' => 'Au service de tous',
                'badge_logo_style' => 'LOGO',
                'badge_show_number' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.values.badge_primary_color', '#0A5C36')
            ->assertJsonPath('data.values.badge_logo_style', 'LOGO')
            ->assertJsonPath('data.values.badge_show_number', false)
            ->assertJsonPath('data.values.badge_show_tagline', true);

        $this->withHeaders($this->headers(['settings.update']))
            ->putJson(self::API, [...$this->validSettings(), 'badge_primary_color' => 'bleu', 'badge_logo_style' => 'NEON'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['badge_primary_color', 'badge_logo_style']);

        // Rien de déposé : aucun emblème à servir, le badge prend l'image de la clinique.
        $this->actingAs($this->administration)->get('/administration/badges/emblem')->assertNotFound();

        $this->withHeaders($this->headers(['settings.update', 'settings.view']))
            ->post(self::API.'/assets/badge', ['file' => UploadedFile::fake()->image('embleme.png', 400, 400)])
            ->assertOk()
            ->assertJsonPath('data.assets.badge.present', true);
        app(AppSettings::class)->forget();

        $employee = $this->employee('EMP-0001', 'Rabe');
        $this->actingAs($this->administration)->get("/administration/employees/{$employee->uuid}/badge")
            ->assertInertia(fn (Assert $page) => $page
                ->where('design.primary', '#0A5C36')
                ->where('design.accent', '#E11D48')
                ->where('design.tagline', 'Au service de tous')
                ->where('design.logo_style', 'LOGO')
                ->where('design.show_number', false)
                ->where('design.emblem_url', fn ($url) => str_contains($url, '/administration/badges/emblem')));

        $this->actingAs($this->administration)->get('/administration/badges/emblem')->assertOk();
        // Le fichier n'est pas public : il se lit avec un droit RH.
        auth()->logout();
        $this->get('/administration/badges/emblem')->assertRedirect();
    }

    public function test_every_part_of_the_badge_is_set_per_site_and_reaches_the_print(): void
    {
        $this->withHeaders($this->headers(['settings.update', 'settings.view']))
            ->putJson(self::API, [
                ...$this->validSettings(),
                'badge_text_color' => '#112233',
                'badge_background_color' => '#fafafa',
                'badge_seal_top' => 'Rivo',
                'badge_seal_bottom' => 'Santé',
                'badge_intern_label' => 'Élève',
                'badge_number_label' => 'Réf.',
                'badge_footer_text' => 'Accès réservé au personnel',
                'badge_logo_style' => 'NONE',
                'badge_icon' => 'HOSPITAL',
                'badge_font' => 'SERIF',
                'badge_tagline_font' => 'SANS',
                'badge_name_case' => 'AS_IS',
                'badge_name_order' => 'LAST_FIRST',
                'badge_text_case' => 'AS_IS',
                'badge_orientation' => 'LANDSCAPE',
                'badge_card_size' => 'LARGE',
                'badge_photo_shape' => 'ROUNDED',
                'badge_corners' => 'SQUARE',
                'badge_paper' => 'A4',
                'badge_paper_orientation' => 'LANDSCAPE',
                'badge_name_size' => 120,
                'badge_text_size' => 90,
                'badge_tagline_size' => 110,
                'badge_page_margin' => 5,
                'badge_gap' => 2,
                'badge_show_site' => true,
                'badge_show_department' => false,
                'badge_show_watermark' => false,
                'badge_cut_marks' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.values.badge_text_color', '#112233')
            ->assertJsonPath('data.values.badge_background_color', '#FAFAFA')
            ->assertJsonPath('data.values.badge_orientation', 'LANDSCAPE')
            ->assertJsonPath('data.values.badge_name_size', 120)
            ->assertJsonPath('data.values.badge_show_site', true)
            // Jamais envoyé : la valeur de la clinique.
            ->assertJsonPath('data.values.badge_show_photo', true);

        $employee = $this->employee('EMP-0001', 'Rabe');
        $this->actingAs($this->administration)->get("/administration/employees/{$employee->uuid}/badge")
            ->assertInertia(fn (Assert $page) => $page
                ->where('design.text', '#112233')
                ->where('design.background', '#FAFAFA')
                ->where('design.seal', ['top' => 'RIVO', 'bottom' => 'SANTÉ'])
                ->where('design.intern_label', 'Élève')
                ->where('design.number_label', 'Réf.')
                ->where('design.footer_text', 'Accès réservé au personnel')
                ->where('design.logo_style', 'NONE')
                ->where('design.icon', 'HOSPITAL')
                ->where('design.font', 'SERIF')
                ->where('design.name_order', 'LAST_FIRST')
                ->where('design.orientation', 'LANDSCAPE')
                ->where('design.card_size', 'LARGE')
                ->where('design.photo_shape', 'ROUNDED')
                ->where('design.corners', 'SQUARE')
                ->where('design.site', 'Ambondromamy')
                ->where('design.show_site', true)
                ->where('design.show_department', false)
                ->where('design.show_watermark', false)
                ->where('design.print', ['paper' => 'A4', 'orientation' => 'LANDSCAPE', 'margin' => 5, 'gap' => 2, 'cut_marks' => false]));
    }

    public function test_a_badge_setting_outside_its_choices_or_a_card_that_does_not_fit_is_refused(): void
    {
        $this->withHeaders($this->headers(['settings.update']))
            ->putJson(self::API, [
                ...$this->validSettings(),
                'badge_paper' => 'A0',
                'badge_icon' => 'LICORNE',
                'badge_name_size' => 400,
                'badge_gap' => -1,
                'badge_footer_text' => str_repeat('x', 61),
                'badge_text_color' => 'noir',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['badge_paper', 'badge_icon', 'badge_name_size', 'badge_gap', 'badge_footer_text', 'badge_text_color']);

        // Une très grande carte couchée ne tient pas sur un A5 avec 25 mm de marge.
        $this->withHeaders($this->headers(['settings.update']))
            ->putJson(self::API, [
                ...$this->validSettings(),
                'badge_paper' => 'A5',
                'badge_orientation' => 'LANDSCAPE',
                'badge_card_size' => 'XLARGE',
                'badge_page_margin' => 25,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['badge_page_margin']);

        // Une carte par page tient toujours.
        $this->withHeaders($this->headers(['settings.update']))
            ->putJson(self::API, [
                ...$this->validSettings(),
                'badge_paper' => 'CARD',
                'badge_orientation' => 'LANDSCAPE',
                'badge_card_size' => 'XLARGE',
                'badge_page_margin' => 25,
            ])
            ->assertOk();
    }

    public function test_the_sheet_holds_as_many_cards_as_the_screen_says(): void
    {
        $this->assertSame(9, BadgeDesign::perPage('A4', 'PORTRAIT', 'STANDARD', 'PORTRAIT', 8, 4));
        $this->assertSame(8, BadgeDesign::perPage('A4', 'LANDSCAPE', 'STANDARD', 'PORTRAIT', 8, 4));
        $this->assertSame(8, BadgeDesign::perPage('A4', 'PORTRAIT', 'STANDARD', 'LANDSCAPE', 8, 4));
        $this->assertSame(0, BadgeDesign::perPage('A5', 'PORTRAIT', 'XLARGE', 'LANDSCAPE', 25, 4));
        $this->assertNull(BadgeDesign::perPage('CARD', 'PORTRAIT', 'STANDARD', 'PORTRAIT', 8, 4));
        $this->assertSame(['width' => 62.1, 'height' => 98.4], BadgeDesign::cardMm('LARGE', 'PORTRAIT'));
        $this->assertSame(['width' => 111.3, 'height' => 70.2], BadgeDesign::cardMm('XLARGE', 'LANDSCAPE'));
    }

    private function employee(string $number, string $lastName, ?string $firstName = null, ?string $department = null, ?string $jobTitle = null, array $extra = [], bool $active = true): Employee
    {
        return Employee::query()->create([
            'employee_number' => $number,
            'last_name' => $lastName,
            'first_name' => $firstName,
            'sex' => 'F',
            'active' => $active,
            'department_id' => $department ? $this->ref(HrReferenceType::Department, $department)->id : null,
            'job_title_id' => $jobTitle ? $this->ref(HrReferenceType::JobTitle, $jobTitle)->id : null,
            ...$extra,
        ]);
    }

    private function internship(Employee $employee, string $field): EmploymentContract
    {
        return EmploymentContract::query()->create([
            'employee_id' => $employee->id,
            'contract_type_id' => $this->ref(HrReferenceType::ContractType, 'Stagiaire')->id,
            'internship_field_id' => $this->ref(HrReferenceType::InternshipField, $field)->id,
            'starts_on' => now()->subWeek()->toDateString(),
            'ends_on' => now()->addMonth()->toDateString(),
        ]);
    }

    private function ref(HrReferenceType $type, string $label): HrReferenceValue
    {
        return HrReferenceValue::query()->where('type', $type->value)->where('label', $label)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function validSettings(): array
    {
        return ['currency_label' => 'Ar', 'currency_position' => 'after', 'currency_decimals' => 0, 'baby_max_age' => 1, 'child_max_age' => 15];
    }

    /** @return array<string, string> */
    private function headers(array $permissions): array
    {
        return [
            'Authorization' => 'Bearer clinic-test-token',
            'X-Request-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-Name' => 'Direction centrale',
            'X-Rivo-Actor-Permissions' => implode(',', $permissions),
            'Idempotency-Key' => (string) Str::uuid(),
        ];
    }
}
