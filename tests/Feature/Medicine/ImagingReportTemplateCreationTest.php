<?php

namespace Tests\Feature\Medicine;

use App\Models\AuditLog;
use App\Models\ImagingReportTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Medicine\ImagingReportTemplateCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-108 — les médecins d'un site ajoutent leurs propres feuilles de compte
 * rendu depuis la fenêtre de saisie.
 *
 * Ce que ces tests protègent : le droit dédié, le refus d'une feuille vide ou
 * en double (y compris avec une feuille papier de la clinique), l'assainissement
 * du texte, et le retrait par archivage plutôt que par suppression.
 */
class ImagingReportTemplateCreationTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/medicine/imaging-report-templates';

    public function test_a_doctor_saves_the_current_content_as_a_new_sheet(): void
    {
        $doctor = $this->doctor(['imaging_templates.create']);

        $this->actingAs($doctor)
            ->post(self::URL, [
                'name' => 'Échographie thyroïdienne',
                'description' => 'Lobes et isthme',
                'body_html' => '<p><strong>LOBE DROIT</strong></p><ul><li>Volume :</li></ul><hr><p><strong>LOBE GAUCHE</strong></p><script>alert(1)</script>',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $template = ImagingReportTemplate::query()->firstOrFail();

        $this->assertSame('Échographie thyroïdienne', $template->name);
        $this->assertSame('Lobes et isthme', $template->description);
        // Les colonnes sont conservées, le script ne l'est pas.
        $this->assertStringContainsString('<hr>', $template->body_html);
        $this->assertStringNotContainsString('script', $template->body_html);
        $this->assertSame($doctor->getKey(), $template->created_by);

        $this->assertTrue(
            AuditLog::query()->where('action', 'imaging.template.create')->exists(),
            'La création d’une feuille doit être auditée.',
        );
    }

    public function test_the_new_sheet_is_offered_next_to_the_clinic_sheets(): void
    {
        $doctor = $this->doctor(['imaging_templates.create']);

        $this->actingAs($doctor)->post(self::URL, [
            'name' => 'Échographie thyroïdienne',
            'body_html' => '<p>LOBE DROIT</p>',
        ]);

        $catalog = $this->app->make(ImagingReportTemplateCatalog::class)->all();
        $custom = collect($catalog)->firstWhere('custom', true);

        $this->assertNotNull($custom);
        $this->assertSame('Échographie thyroïdienne', $custom['label']);
        $this->assertStringStartsWith('custom-', $custom['key']);
        $this->assertNotNull($custom['uuid']);

        // Les feuilles de la clinique sont toujours là, marquées comme telles.
        $this->assertFalse(collect($catalog)->firstWhere('key', 'ECHO_PELVIENNE')['custom']);
    }

    public function test_creating_a_sheet_needs_its_own_permission(): void
    {
        $this->actingAs($this->doctor([]))
            ->post(self::URL, ['name' => 'X', 'body_html' => '<p>Contenu</p>'])
            ->assertForbidden();

        $this->assertSame(0, ImagingReportTemplate::query()->count());
    }

    public function test_an_empty_sheet_is_refused(): void
    {
        $doctor = $this->doctor(['imaging_templates.create']);

        foreach (['', '<p><br></p>', '<hr>', '<p><br></p><hr><p><br></p>'] as $empty) {
            $this->actingAs($doctor)
                ->post(self::URL, ['name' => 'Vide', 'body_html' => $empty])
                ->assertSessionHasErrors('body_html');
        }

        $this->assertSame(0, ImagingReportTemplate::query()->count());
    }

    public function test_a_name_is_required_and_cannot_repeat_a_clinic_sheet_or_another_one(): void
    {
        $doctor = $this->doctor(['imaging_templates.create']);

        $this->actingAs($doctor)
            ->post(self::URL, ['name' => '  ', 'body_html' => '<p>Contenu</p>'])
            ->assertSessionHasErrors('name');

        // Une feuille de la clinique, sans tenir compte de la casse.
        $this->actingAs($doctor)
            ->post(self::URL, ['name' => 'échographie PELVIENNE', 'body_html' => '<p>Contenu</p>'])
            ->assertSessionHasErrors('name');

        $this->actingAs($doctor)->post(self::URL, ['name' => 'Ma feuille', 'body_html' => '<p>Contenu</p>']);
        $this->actingAs($doctor)
            ->post(self::URL, ['name' => 'ma feuille', 'body_html' => '<p>Autre</p>'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, ImagingReportTemplate::query()->count());
    }

    public function test_a_sheet_is_archived_never_deleted(): void
    {
        $doctor = $this->doctor(['imaging_templates.create', 'imaging_templates.archive']);
        $this->actingAs($doctor)->post(self::URL, ['name' => 'Ma feuille', 'body_html' => '<p>Contenu</p>']);
        $template = ImagingReportTemplate::query()->firstOrFail();

        $this->actingAs($doctor)
            ->delete(self::URL.'/'.$template->uuid)
            ->assertSessionHasNoErrors();

        $archived = ImagingReportTemplate::withTrashed()->findOrFail($template->getKey());
        $this->assertNotNull($archived->deleted_at);
        $this->assertNotNull($archived->delete_reason);
        $this->assertSame($doctor->getKey(), $archived->deleted_by);

        // Plus proposée, mais toujours en base.
        $offered = collect($this->app->make(ImagingReportTemplateCatalog::class)->all())->where('custom', true);
        $this->assertCount(0, $offered);

        // Une feuille archivée libère son nom.
        $this->actingAs($doctor)
            ->post(self::URL, ['name' => 'Ma feuille', 'body_html' => '<p>Contenu</p>'])
            ->assertSessionHasNoErrors();
    }

    public function test_archiving_needs_its_own_permission(): void
    {
        $author = $this->doctor(['imaging_templates.create']);
        $this->actingAs($author)->post(self::URL, ['name' => 'Ma feuille', 'body_html' => '<p>Contenu</p>']);
        $template = ImagingReportTemplate::query()->firstOrFail();

        // Créer et retirer ne sont pas la même autorité.
        $this->actingAs($author)->delete(self::URL.'/'.$template->uuid)->assertForbidden();

        $this->assertNull($template->fresh()->deleted_at);
    }

    public function test_a_sheet_is_renamed_and_its_content_replaced(): void
    {
        $doctor = $this->doctor(['imaging_templates.create', 'imaging_templates.update']);
        $this->actingAs($doctor)->post(self::URL, ['name' => 'Ma feuille', 'body_html' => '<p>Ancien</p>']);
        $template = ImagingReportTemplate::query()->firstOrFail();

        // Renommer seul : le contenu ne bouge pas.
        $this->actingAs($doctor)
            ->put(self::URL.'/'.$template->uuid, ['name' => 'Ma feuille v2', 'description' => 'Précision'])
            ->assertSessionHasNoErrors();

        $template = $template->fresh();
        $this->assertSame('Ma feuille v2', $template->name);
        $this->assertSame('Précision', $template->description);
        $this->assertStringContainsString('Ancien', $template->body_html);

        // Remplacer le contenu.
        $this->actingAs($doctor)
            ->put(self::URL.'/'.$template->uuid, ['name' => 'Ma feuille v2', 'body_html' => '<p>Nouveau</p><script>x()</script>'])
            ->assertSessionHasNoErrors();

        $this->assertSame('<p>Nouveau</p>', $template->fresh()->body_html);
        $this->assertTrue(AuditLog::query()->where('action', 'imaging.template.update')->exists());
    }

    public function test_an_update_refuses_a_duplicate_name_an_empty_body_and_no_change(): void
    {
        $doctor = $this->doctor(['imaging_templates.create', 'imaging_templates.update']);
        $this->actingAs($doctor)->post(self::URL, ['name' => 'Première', 'body_html' => '<p>A</p>']);
        $this->actingAs($doctor)->post(self::URL, ['name' => 'Seconde', 'body_html' => '<p>B</p>']);
        $second = ImagingReportTemplate::query()->where('name', 'Seconde')->firstOrFail();
        $url = self::URL.'/'.$second->uuid;

        $this->actingAs($doctor)->put($url, ['name' => 'première'])->assertSessionHasErrors('name');
        $this->actingAs($doctor)->put($url, ['name' => 'Échographie pelvienne'])->assertSessionHasErrors('name');
        $this->actingAs($doctor)->put($url, ['name' => 'Seconde', 'body_html' => '<p><br></p><hr>'])->assertSessionHasErrors('body_html');
        // Garder son propre nom n'est pas un doublon, mais sans rien changer il n'y a rien à enregistrer.
        $this->actingAs($doctor)->put($url, ['name' => 'Seconde'])->assertSessionHasErrors('name');

        $this->assertSame('Seconde', $second->fresh()->name);
    }

    public function test_updating_needs_its_own_permission(): void
    {
        $author = $this->doctor(['imaging_templates.create']);
        $this->actingAs($author)->post(self::URL, ['name' => 'Ma feuille', 'body_html' => '<p>A</p>']);
        $template = ImagingReportTemplate::query()->firstOrFail();

        $this->actingAs($author)->put(self::URL.'/'.$template->uuid, ['name' => 'Autre'])->assertForbidden();

        $this->assertSame('Ma feuille', $template->fresh()->name);
    }

    public function test_the_rights_are_reported_to_the_screen(): void
    {
        $catalog = $this->app->make(ImagingReportTemplateCatalog::class);

        $this->assertSame(['create' => true, 'update' => false, 'archive' => false], $catalog->rightsFor($this->doctor(['imaging_templates.create'])));
        $this->assertSame(['create' => false, 'update' => false, 'archive' => false], $catalog->rightsFor($this->doctor([])));
    }

    /** @param array<int, string> $permissions */
    private function doctor(array $permissions): User
    {
        $role = Role::query()->create(['code' => 'DOC'.uniqid(), 'name' => 'Médecin']);

        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }
}
