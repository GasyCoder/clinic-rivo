<?php

namespace Tests\Feature\Administration;

use App\Enums\AdvantageSource;
use App\Enums\BonusAwardStatus;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ReferralSource;
use App\Models\AdvantageArticle;
use App\Models\AdvantageAward;
use App\Models\CatalogItem;
use App\Models\Employee;
use App\Models\Episode;
use App\Models\PartnerOrganization;
use App\Models\Patient;
use App\Models\PatientReferral;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Avantages à l'acte (module Bonus) : RIVO compte les actes de chaque article —
 * réalisés par la personne, ou faits sur le passage d'un patient qu'elle a
 * référé —, le RH valide (quantité × prix unitaire figés) puis marque versé.
 */
class AdvantageTest extends TestCase
{
    use RefreshDatabase;

    private const HR = [
        'bonus_categories.view', 'bonus_categories.create', 'bonus_categories.update',
        'bonus_categories.archive', 'bonus_categories.restore',
        'bonus_awards.view', 'bonus_awards.validate', 'bonus_awards.pay', 'bonus_awards.cancel',
    ];

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-20 10:00:00');
        $this->hr = $this->user(self::HR);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_hr_creates_an_article_with_its_acts_source_and_unit_price(): void
    {
        $echo = $this->act('ECHO-ABD', CatalogModule::Imaging);

        $this->actingAs($this->hr)->post('/administration/bonus/avantages/articles', [
            'name' => 'ECHO', 'source' => 'PERFORMED', 'unit_price' => '5 000', 'catalog_item_uuids' => [$echo->uuid],
        ])->assertSessionHasNoErrors();

        $article = AdvantageArticle::query()->sole();
        $this->assertSame('5000.00', (string) $article->unit_price);
        $this->assertSame([$echo->id], $article->catalogItems()->pluck('catalog_items.id')->all());

        $this->actingAs($this->hr)->post('/administration/bonus/avantages/articles', [
            'name' => 'echo', 'source' => 'REFERRED', 'unit_price' => 1000, 'catalog_item_uuids' => [$echo->uuid],
        ])->assertSessionHasErrors('name');
        $this->actingAs($this->hr)->post('/administration/bonus/avantages/articles', [
            'name' => 'ECG', 'source' => 'PERFORMED', 'unit_price' => 1000, 'catalog_item_uuids' => [],
        ])->assertSessionHasErrors('catalog_item_uuids');
    }

    public function test_performed_acts_are_counted_for_a_person_whose_benefits_are_open_then_frozen_at_validation(): void
    {
        $echo = $this->act('ECHO-ABD', CatalogModule::Imaging);
        $article = $this->article('ECHO', AdvantageSource::Performed, 5000, [$echo]);
        $doctorAccount = User::factory()->create();
        $doctor = $this->employee('EMP-A1', $doctorAccount, benefits: true);
        $closed = $this->employee('EMP-A2', User::factory()->create(), benefits: false);

        $this->imagingResult($echo, $doctorAccount, '2026-09-05 09:00');
        $this->imagingResult($echo, $doctorAccount, '2026-09-06 09:00');
        $this->imagingResult($echo, $doctorAccount, '2026-08-30 09:00'); // un autre mois
        $this->imagingResult($echo, User::query()->find($closed->user_id), '2026-09-07 09:00');

        $this->actingAs($this->hr)->get('/administration/bonus?onglet=avantages&mois=2026-09')
            ->assertInertia(fn (Assert $page) => $page
                ->where('tab', 'advantages')
                ->has('advantages.people', 1, fn (Assert $row) => $row
                    ->where('uuid', $doctor->uuid)
                    ->where('total', '10000.00')
                    ->where('lines.0.quantity', 2)
                    ->etc()));

        $this->actingAs($this->hr)->post('/administration/bonus/avantages/awards', ['type' => 'EMPLOYEE', 'uuid' => $doctor->uuid, 'mois' => '2026-09'])
            ->assertSessionHasNoErrors();

        $award = AdvantageAward::query()->sole();
        $this->assertSame('10000.00', (string) $award->total_amount);

        // Le prix change ensuite : l'avantage validé garde le sien.
        $article->forceFill(['unit_price' => 9000])->save();
        $this->assertSame('5000.00', $award->refresh()->lines[0]['unit_price']);

        $this->actingAs($this->hr)->post('/administration/bonus/avantages/awards', ['type' => 'EMPLOYEE', 'uuid' => $doctor->uuid, 'mois' => '2026-09'])
            ->assertSessionHasErrors('period');
        $this->actingAs($this->hr)->post('/administration/bonus/avantages/awards', ['type' => 'EMPLOYEE', 'uuid' => $closed->uuid, 'mois' => '2026-09'])
            ->assertSessionHasErrors('period');

        $this->actingAs($this->hr)->post("/administration/bonus/avantages/awards/{$award->uuid}/pay", ['note' => 'Virement'])->assertSessionHasNoErrors();
        $this->assertSame(BonusAwardStatus::Paid, $award->refresh()->status);
        $this->actingAs($this->hr)->post("/administration/bonus/avantages/awards/{$award->uuid}/cancel", ['reason' => 'Erreur'])
            ->assertSessionHasErrors('award');
    }

    public function test_referred_patients_count_the_acts_of_their_arrival_passage_for_partners_too(): void
    {
        $chir = $this->act('CHIR-HERNIE', CatalogModule::Surgery);
        $this->article('CHIR', AdvantageSource::Referred, 20000, [$chir]);
        $partner = PartnerOrganization::query()->create(['category' => 'OTHER', 'name' => 'ISPSG Kenny', 'active' => true]);

        $patient = $this->patient('A-26-0100');
        $arrival = $this->episode($patient);
        PatientReferral::query()->create([
            'patient_id' => $patient->id, 'episode_id' => $arrival->id, 'source' => ReferralSource::Partner,
            'partner_organization_id' => $partner->id, 'referrer_name' => $partner->name, 'referred_at' => now(),
        ]);
        $this->billed($arrival, $chir, 1);
        $this->billed($this->episode($patient), $chir, 1); // un passage suivant ne compte pas

        $this->actingAs($this->hr)->get('/administration/bonus?onglet=avantages&mois=2026-09')
            ->assertInertia(fn (Assert $page) => $page->has('advantages.people', 1, fn (Assert $row) => $row
                ->where('type', 'PARTNER')->where('total', '20000.00')->where('lines.0.quantity', 1)->etc()));

        $this->actingAs($this->hr)->post('/administration/bonus/avantages/awards', ['type' => 'PARTNER', 'uuid' => $partner->uuid, 'mois' => '2026-09'])
            ->assertSessionHasNoErrors();
        $this->assertSame('ISPSG Kenny', AdvantageAward::query()->sole()->beneficiary_name);
    }

    public function test_the_benefits_box_decides_and_the_job_title_is_only_the_default(): void
    {
        $nurse = $this->employee('EMP-A3', null, benefits: null);
        $this->assertFalse($nurse->grantsBenefits(), 'sans case cochée ni fonction qui ouvre droit : fermé');

        $nurse->forceFill(['benefits_enabled' => true])->save();
        $this->assertTrue($nurse->refresh()->grantsBenefits());
    }

    public function test_each_gesture_needs_its_permission(): void
    {
        $reader = $this->user(['bonus_awards.view'], 'RECEPTION');
        $echo = $this->act('ECHO-X', CatalogModule::Imaging);

        $this->actingAs($reader)->post('/administration/bonus/avantages/articles', [
            'name' => 'ECHO', 'source' => 'PERFORMED', 'unit_price' => 1000, 'catalog_item_uuids' => [$echo->uuid],
        ])->assertForbidden();
        $this->actingAs($reader)->post('/administration/bonus/avantages/awards', ['type' => 'EMPLOYEE', 'uuid' => fake()->uuid(), 'mois' => '2026-09'])
            ->assertForbidden();
    }

    private function user(array $permissions, string $code = 'ADMINISTRATION'): User
    {
        $role = Role::query()->firstOrCreate(['code' => $code], ['name' => $code]);

        foreach ($permissions as $name) {
            $role->permissions()->syncWithoutDetaching([Permission::query()->firstOrCreate(['name' => $name])->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function employee(string $number, ?User $account, ?bool $benefits): Employee
    {
        return Employee::query()->create([
            'employee_number' => $number, 'first_name' => 'Doc', 'last_name' => $number, 'sex' => 'M',
            'active' => true, 'user_id' => $account?->id, 'benefits_enabled' => $benefits,
        ]);
    }

    private function act(string $code, CatalogModule $module): CatalogItem
    {
        return CatalogItem::query()->create([
            'code' => $code, 'name' => $code, 'type' => CatalogItemType::Service, 'module' => $module,
            'unit' => 'acte', 'billable' => true, 'stockable' => false, 'created_by' => $this->hr->id,
        ]);
    }

    /** @param list<CatalogItem> $items */
    private function article(string $name, AdvantageSource $source, int $price, array $items): AdvantageArticle
    {
        $article = AdvantageArticle::query()->create(['name' => $name, 'source' => $source, 'unit_price' => $price, 'active' => true]);
        $article->catalogItems()->sync(collect($items)->map->getKey()->all());

        return $article;
    }

    private function patient(string $number): Patient
    {
        return Patient::query()->create(['patient_number' => $number, 'first_name' => 'P', 'last_name' => $number, 'birth_date' => '1990-01-01', 'sex' => 'F']);
    }

    private function episode(Patient $patient): Episode
    {
        return Episode::query()->create([
            'patient_id' => $patient->id,
            'episode_number' => $patient->patient_number.'-'.fake()->unique()->numerify('##'),
            'started_at' => now()->subDay(),
        ]);
    }

    private function imagingResult(CatalogItem $item, User $by, string $at): void
    {
        $episode = $this->episode($this->patient('A-26-'.fake()->unique()->numerify('####')));
        $orientation = \App\Models\EpisodeOrientation::query()->create([
            'episode_id' => $episode->id, 'source_module' => 'MEDICINE', 'destination_module' => 'IMAGING',
            'status' => 'COMPLETED', 'oriented_at' => now(),
        ]);
        $request = DB::table('imaging_requests')->insertGetId([
            'uuid' => fake()->uuid(), 'episode_id' => $episode->id, 'source_orientation_id' => $orientation->id, 'requested_by' => $by->id, 'requested_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('imaging_request_items')->insert([
            'uuid' => fake()->uuid(), 'imaging_request_id' => $request, 'catalog_item_id' => $item->id,
            'catalog_item_code_snapshot' => $item->code, 'catalog_item_name_snapshot' => $item->name,
            'result_value' => '<p>RAS</p>', 'resulted_at' => Carbon::parse($at), 'resulted_by' => $by->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function billed(Episode $episode, CatalogItem $item, int $quantity): void
    {
        DB::table('billable_items')->insert([
            'uuid' => fake()->uuid(), 'episode_id' => $episode->id, 'source_module' => 'RECEPTION', 'catalog_item_id' => $item->id,
            'description' => $item->name, 'quantity' => $quantity, 'unit_price' => 0, 'total_amount' => 0,
            'currency' => 'MGA', 'status' => 'PENDING', 'created_by' => $this->hr->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
