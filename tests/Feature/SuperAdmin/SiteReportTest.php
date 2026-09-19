<?php

namespace Tests\Feature\SuperAdmin;

use App\Enums\EpisodeOrientationStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PatientSex;
use App\Enums\PaymentStatus;
use App\Models\CashSession;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Dashboard\SiteReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Le rapport consolidé d'un site (ADR-102).
 *
 * Ce que ces tests tiennent : une section qu'on n'a pas le droit de lire ne
 * revient pas à zéro, et aucun total n'inclut ce qui a été annulé.
 */
class SiteReportTest extends TestCase
{
    use RefreshDatabase;

    private ?User $author = null;

    private ?CashSession $session = null;

    /**
     * La distinction qui porte tout l'écran : « je n'ai pas pu compter » ne
     * s'écrit pas « 0 ». Un tableau de bord qui annonce zéro recette là où
     * il manque une permission fait prendre une décision sur un chiffre faux.
     */
    public function test_a_section_without_its_permission_is_unavailable_not_zero(): void
    {
        $actor = $this->accountWith(['episodes.view']);

        $report = app(SiteReportService::class)->overview($actor, 30);

        $this->assertTrue($report['sections']['activity']['available']);
        foreach (['finance', 'pharmacy', 'people'] as $section) {
            $this->assertFalse($report['sections'][$section]['available'], $section);
            $this->assertArrayNotHasKey('totals', $report['sections'][$section]);
            $this->assertNotEmpty($report['sections'][$section]['reason']);
        }
    }

    /** Une facture annulée n'est pas un chiffre d'affaires (ADR-090). */
    public function test_cancelled_invoices_and_payments_never_enter_the_totals(): void
    {
        $actor = $this->accountWith(['billing.view', 'payments.view']);
        $patient = $this->patient();

        $valid = $this->invoice($patient, 50000, InvoiceStatus::Validated, 50000, 0);
        $this->invoice($patient, 90000, InvoiceStatus::Cancelled, 0, 90000);

        $method = PaymentMethod::query()->create(['code' => 'CASH', 'name' => 'Espèces', 'category' => 'CASH', 'active' => true]);
        $this->payment($valid, $method, 50000, PaymentStatus::Completed);
        $this->payment($valid, $method, 20000, PaymentStatus::Cancelled);

        $totals = app(SiteReportService::class)->overview($actor, 30)['sections']['finance']['totals'];

        $this->assertSame(50000.0, $totals['invoiced']);
        $this->assertSame(50000.0, $totals['collected']);
        // Le reste dû se lit sur les soldes, jamais sur facturé − encaissé :
        // une prise en charge à 100 % solde sans le moindre paiement.
        $this->assertSame(0.0, $totals['outstanding']);
        $this->assertSame(1, $totals['invoices']);
    }

    /** Une orientation retirée a quitté la file : elle n'est plus du travail (ADR-079). */
    public function test_cancelled_orientations_leave_the_queues(): void
    {
        $actor = $this->accountWith(['episodes.view']);
        $episode = $this->episode($this->patient());

        $this->orientation($episode, 'CARE', EpisodeOrientationStatus::Pending);
        $this->orientation($episode, 'LABORATORY', EpisodeOrientationStatus::Cancelled);

        $destinations = collect(app(SiteReportService::class)->overview($actor, 30)['sections']['clinical']['destinations']);

        $this->assertSame(1, $destinations->firstWhere('key', 'CARE')['waiting']);
        $this->assertNull($destinations->firstWhere('key', 'LABORATORY'));
    }

    /** La fenêtre est bornée par le service : une valeur absurde ne casse rien. */
    public function test_the_window_is_clamped(): void
    {
        $actor = $this->accountWith(['episodes.view']);
        $service = app(SiteReportService::class);

        $this->assertSame(SiteReportService::MAX_DAYS, $service->overview($actor, 5000)['range']['days']);
        $this->assertSame(SiteReportService::MIN_DAYS, $service->overview($actor, 1)['range']['days']);
        $this->assertCount(SiteReportService::MIN_DAYS, $service->overview($actor, 1)['sections']['activity']['trend']['dates']);
    }

    /** Le portail lit ce rapport par l'API du site, jamais par sa base. */
    public function test_the_endpoint_requires_the_portal_permission(): void
    {
        config(['rivo.site.type' => 'clinic', 'rivo.site.code' => 'A', 'rivo.site_api.token' => 'clinic-test-token']);

        $this->withHeaders($this->headers(['episodes.view']))
            ->getJson('/api/v1/super-admin/reports/overview')
            ->assertForbidden();

        $this->withHeaders($this->headers(['super_admin.portal.view', 'episodes.view']))
            ->getJson('/api/v1/super-admin/reports/overview?days=30')
            ->assertOk()
            ->assertJsonPath('data.range.days', 30)
            ->assertJsonPath('data.sections.activity.available', true)
            ->assertJsonPath('data.sections.finance.available', false);
    }

    public function test_the_endpoint_refuses_a_window_outside_its_bounds(): void
    {
        config(['rivo.site.type' => 'clinic', 'rivo.site.code' => 'A', 'rivo.site_api.token' => 'clinic-test-token']);

        $this->withHeaders($this->headers(['super_admin.portal.view']))
            ->getJson('/api/v1/super-admin/reports/overview?days=400')
            ->assertStatus(422)
            ->assertJsonValidationErrors('days');
    }

    /** @param array<int, string> $permissions */
    private function accountWith(array $permissions): User
    {
        $role = Role::query()->create(['code' => 'TEST_'.fake()->unique()->numerify('####'), 'name' => 'Rôle de test']);

        foreach ($permissions as $name) {
            $role->permissions()->syncWithoutDetaching([Permission::query()->firstOrCreate(['name' => $name], ['label' => $name])->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    /** @param array<int, string> $permissions */
    private function headers(array $permissions): array
    {
        return [
            'Authorization' => 'Bearer clinic-test-token',
            'X-Request-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-Name' => 'Direction centrale',
            'X-Rivo-Actor-Permissions' => implode(',', $permissions),
        ];
    }

    private function cashSession(): CashSession
    {
        return $this->session ??= CashSession::query()->create([
            'session_number' => 'C-26-0001',
            'active_key' => 'SINGLE_OPEN_CASH',
            'status' => 'OPEN',
            'opening_amount' => 0,
            'opened_by' => $this->author()->id,
            'opened_at' => now(),
        ]);
    }

    private function author(): User
    {
        return $this->author ??= User::factory()->create([
            'role_id' => Role::query()->firstOrCreate(['code' => 'AUTHOR'], ['name' => 'Auteur'])->id,
        ]);
    }

    private function patient(): Patient
    {
        return Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('A-26-####'),
            'first_name' => 'Soa',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-01-01',
            'sex' => PatientSex::Female->value,
        ]);
    }

    private function episode(Patient $patient): Episode
    {
        return Episode::query()->create([
            'patient_id' => $patient->id,
            'episode_number' => $patient->patient_number.'-01',
            'status' => 'OPEN',
            'administrative_status' => 'PENDING_ORIENTATION',
            'priority' => 'NORMAL',
            'started_at' => now(),
        ]);
    }

    private function orientation(Episode $episode, string $destination, EpisodeOrientationStatus $status): EpisodeOrientation
    {
        return EpisodeOrientation::query()->create([
            'episode_id' => $episode->id,
            'source_module' => 'RECEPTION',
            'destination_module' => $destination,
            'status' => $status->value,
            'active_key' => $status === EpisodeOrientationStatus::Cancelled ? null : Str::uuid()->toString(),
            'oriented_at' => now(),
        ]);
    }

    private function invoice(Patient $patient, float $total, InvoiceStatus $status, float $paid, float $balance): Invoice
    {
        return Invoice::query()->create([
            'patient_id' => $patient->id,
            'invoice_number' => fake()->unique()->numerify('F-26-####'),
            'status' => $status->value,
            'currency' => 'MGA',
            'subtotal_amount' => $total,
            'total_amount' => $total,
            'paid_amount' => $paid,
            'balance_amount' => $status === InvoiceStatus::Cancelled ? 0 : $balance,
            'created_by' => $this->author()->id,
        ]);
    }

    private function payment(Invoice $invoice, PaymentMethod $method, float $amount, PaymentStatus $status): Payment
    {
        return Payment::query()->create([
            'invoice_id' => $invoice->id,
            'payment_method_id' => $method->id,
            'payment_number' => fake()->unique()->numerify('P-26-####'),
            'amount' => $amount,
            'currency' => 'MGA',
            'status' => $status->value,
            'paid_at' => now(),
            'cash_session_id' => $this->cashSession()->id,
            'received_by' => $this->author()->id,
        ]);
    }
}
