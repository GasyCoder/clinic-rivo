<?php

namespace Tests\Feature\Http;

use App\Models\AuditLog;
use App\Models\CashSession;
use App\Models\Episode;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\PatientVipSetting;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Patient\PatientVipClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * ADR-133 — patients normaux / VIP, tri A → Z, initiale du nom et export Excel.
 *
 * Ce que ces tests protègent : un VIP remplit les DEUX conditions (passages ET
 * argent réellement encaissé) sur la fenêtre ; sans réglage personne n'est VIP ;
 * et l'export écrit exactement ce que l'écran liste, sous son propre droit.
 */
class PatientDirectoryVipAndExportTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-19 12:00'));
    }

    public function test_a_vip_meets_both_thresholds_and_neither_alone_is_enough(): void
    {
        $this->settings(minEpisodes: 3, minAmount: '100000', months: 12);

        $vip = $this->patientWith(episodes: 3, paid: '150000');
        $frequentButPoor = $this->patientWith(episodes: 3, paid: '50000');
        $richButRare = $this->patientWith(episodes: 1, paid: '900000');

        $classifier = new PatientVipClassifier;

        $this->assertTrue($classifier->isVip($vip->id));
        $this->assertFalse($classifier->isVip($frequentButPoor->id));
        $this->assertFalse($classifier->isVip($richButRare->id));
    }

    public function test_only_the_window_counts_and_only_real_money_and_real_visits(): void
    {
        $this->settings(minEpisodes: 2, minAmount: '100000', months: 6);

        // Tout est hors fenêtre : ni les passages, ni l'argent ne comptent.
        $old = $this->patientWith(episodes: 2, paid: '200000', at: '2026-01-10 09:00');

        // Passages annulés : ils ne sont pas des passages.
        $cancelled = $this->patientWith(episodes: 2, paid: '200000', episodeStatus: 'CANCELLED');

        // Paiement annulé : ce n'est pas de l'argent entré.
        $refunded = $this->patientWith(episodes: 2, paid: '200000', paymentStatus: 'CANCELLED');

        // Deux paiements de la fenêtre s'additionnent.
        $split = $this->patientWith(episodes: 2, paid: '60000');
        $this->payment($this->invoiceFor($split), '60000');

        $classifier = new PatientVipClassifier;

        $this->assertFalse($classifier->isVip($old->id));
        $this->assertFalse($classifier->isVip($cancelled->id));
        $this->assertFalse($classifier->isVip($refunded->id));
        $this->assertTrue($classifier->isVip($split->id));
    }

    public function test_nobody_is_vip_without_settings_or_when_disabled(): void
    {
        $patient = $this->patientWith(episodes: 5, paid: '9000000');

        $this->assertFalse((new PatientVipClassifier)->isConfigured());
        $this->assertSame([], (new PatientVipClassifier)->vipIds());

        $this->settings(minEpisodes: 1, minAmount: '1', months: 12, enabled: false);

        $this->assertFalse((new PatientVipClassifier)->isVip($patient->id));

        $this->settings(minEpisodes: 1, minAmount: '1', months: 12, enabled: true);

        $this->assertTrue((new PatientVipClassifier)->isVip($patient->id));
    }

    public function test_the_directory_separates_normal_and_vip_patients_with_their_counts(): void
    {
        $this->settings(minEpisodes: 2, minAmount: '100000', months: 12);
        $vip = $this->patientWith(episodes: 2, paid: '200000', last: 'Andria');
        $normal = $this->patientWith(episodes: 1, paid: '1000', last: 'Rabe');

        $viewer = $this->viewer(['patients.view']);

        $all = $this->props($viewer);
        $this->assertSame(['all' => 2, 'vip' => 1, 'normal' => 1], $all['segments']['category']);
        $this->assertTrue($all['vip']['configured']);
        $this->assertStringContainsString('au moins 2 passages', $all['vip']['rule']);

        $rows = collect($all['patients']['data'])->keyBy('uuid');
        $this->assertTrue($rows[$vip->uuid]['is_vip']);
        $this->assertFalse($rows[$normal->uuid]['is_vip']);

        $onlyVip = $this->props($viewer, '?segment=vip');
        $this->assertSame(1, $onlyVip['patients']['total']);
        $this->assertSame($vip->uuid, $onlyVip['patients']['data'][0]['uuid']);
        $this->assertSame('vip', $onlyVip['filters']['segment']);

        $onlyNormal = $this->props($viewer, '?segment=normal');
        $this->assertSame(1, $onlyNormal['patients']['total']);
        $this->assertSame($normal->uuid, $onlyNormal['patients']['data'][0]['uuid']);

        // Une valeur inconnue ne filtre rien.
        $unknown = $this->props($viewer, '?segment=platinum');
        $this->assertSame(2, $unknown['patients']['total']);
        $this->assertSame('all', $unknown['filters']['segment']);
    }

    public function test_the_category_counts_follow_the_other_filters(): void
    {
        $this->settings(minEpisodes: 2, minAmount: '100000', months: 12);
        $this->patientWith(episodes: 2, paid: '200000', last: 'Andria');
        $this->patientWith(episodes: 2, paid: '200000', last: 'Rabe');
        $this->patientWith(episodes: 1, paid: '1000', last: 'Rakoto');

        $counts = $this->props($this->viewer(['patients.view']), '?letter=R')['segments']['category'];

        // Chaque case annonce ce que donnerait un clic, la lettre restant appliquée.
        $this->assertSame(['all' => 2, 'vip' => 1, 'normal' => 1], $counts);
    }

    public function test_without_settings_the_directory_says_so_and_lists_everyone_as_normal(): void
    {
        $this->patientWith(episodes: 4, paid: '9000000');

        $props = $this->props($this->viewer(['patients.view']));

        $this->assertFalse($props['vip']['configured']);
        $this->assertNull($props['vip']['rule']);
        $this->assertSame(0, $props['segments']['category']['vip']);
        $this->assertSame(1, $props['segments']['category']['normal']);
    }

    public function test_patients_sort_alphabetically_by_last_name_then_first_name(): void
    {
        foreach ([['Rakoto', 'Zo'], ['Andria', 'Bao'], ['Rakoto', 'Aina'], ['Zafy', 'Lala']] as [$last, $first]) {
            $this->patient($last, $first);
        }

        $viewer = $this->viewer(['patients.view']);
        $order = fn (string $sort) => collect($this->props($viewer, '?sort='.$sort)['patients']['data'])
            ->map(fn ($p) => $p['last_name'].' '.$p['first_name'])->all();

        $this->assertSame(['Andria Bao', 'Rakoto Aina', 'Rakoto Zo', 'Zafy Lala'], $order('name_asc'));
        $this->assertSame(['Zafy Lala', 'Rakoto Zo', 'Rakoto Aina', 'Andria Bao'], $order('name_desc'));

        // Sans ordre demandé, les plus récents d'abord — le comportement d'origine.
        $this->assertSame('Zafy Lala', $order('recent')[0]);
        $this->assertSame('Zafy Lala', $order('nonsense')[0]);
        $this->assertSame('recent', $this->props($viewer, '?sort=nonsense')['filters']['sort']);
    }

    public function test_the_initial_filters_names_and_a_pattern_is_never_interpreted(): void
    {
        $this->patient('Rakoto', 'A');
        $this->patient('Rabe', 'B');
        $this->patient('Andria', 'C');

        $viewer = $this->viewer(['patients.view']);

        $byLetter = $this->props($viewer, '?letter=r');
        $this->assertSame(2, $byLetter['patients']['total']);
        $this->assertSame('R', $byLetter['filters']['letter']);

        // « % » ou « _ » ne sont pas des initiales : le filtre est ignoré, il ne
        // devient pas un joker.
        foreach (['%', '_', 'RA', '1', ''] as $bad) {
            $props = $this->props($viewer, '?letter='.urlencode($bad));
            $this->assertSame(3, $props['patients']['total'], "letter={$bad}");
            $this->assertNull($props['filters']['letter']);
        }
    }

    public function test_the_export_needs_its_own_permission(): void
    {
        $this->patient();

        $this->actingAs($this->viewer(['patients.view']))->get('/patients/export')->assertForbidden();
        $this->actingAs($this->viewer(['patients.export'], 'OTHER'))->get('/patients/export')->assertForbidden();
    }

    public function test_the_export_writes_exactly_what_the_screen_lists_and_is_audited(): void
    {
        $this->settings(minEpisodes: 2, minAmount: '100000', months: 12);
        $vip = $this->patientWith(episodes: 2, paid: '200000', last: 'Andria', first: 'Bao', phone: '032 00 000 01');
        $this->patientWith(episodes: 1, paid: '1000', last: 'Rabe', first: 'Lala');
        $this->patientWith(episodes: 0, paid: null, last: 'Zafy', first: 'Mina');

        $user = $this->viewer(['patients.view', 'patients.export']);

        $rows = $this->readExport($this->actingAs($user)->get('/patients/export?sort=name_asc'));

        $this->assertSame(
            ['N° patient', 'Nom', 'Prénom', 'Sexe', 'Date de naissance', 'Âge', 'Téléphone', 'Adresse', 'Catégorie', 'Passages', 'Dernier passage'],
            $rows[0],
        );
        $this->assertSame(['Andria', 'Rabe', 'Zafy'], array_column(array_slice($rows, 1), 1));

        $first = $rows[1];
        $this->assertSame($vip->patient_number, $first[0]);
        $this->assertSame('032 00 000 01', $first[6]);
        $this->assertSame('VIP', $first[8]);
        $this->assertSame('2', (string) $first[9]);
        $this->assertSame('Normal', $rows[2][8]);

        // Les filtres de l'écran s'appliquent tous à l'export, catégorie comprise.
        $vipOnly = $this->readExport($this->actingAs($user)->get('/patients/export?segment=vip'));
        $this->assertCount(2, $vipOnly);
        $this->assertSame('Andria', $vipOnly[1][1]);

        $byLetter = $this->readExport($this->actingAs($user)->get('/patients/export?letter=R'));
        $this->assertSame(['Rabe'], array_column(array_slice($byLetter, 1), 1));

        $log = AuditLog::query()->where('action', 'patient.export')->latest('id')->firstOrFail();
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame(1, $log->new_values['rows']);
        $this->assertSame('R', $log->new_values['filters']['letter']);
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string, mixed> */
    private function props(User $user, string $query = ''): array
    {
        return $this->actingAs($user)->get('/patients'.$query)->assertOk()->viewData('page')['props'];
    }

    /** @return array<int, array<int, string|null>> */
    private function readExport($response): array
    {
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'patients').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, true, true, false);
        @unlink($path);

        return $rows;
    }

    private function settings(int $minEpisodes, string $minAmount, int $months, bool $enabled = true): void
    {
        PatientVipSetting::query()->delete();
        PatientVipSetting::query()->create([
            'enabled' => $enabled,
            'min_episodes' => $minEpisodes,
            'min_amount' => $minAmount,
            'window_months' => $months,
        ]);
    }

    private function viewer(array $permissions, string $roleCode = 'RECEPTION'): User
    {
        $role = Role::query()->firstOrCreate(['code' => $roleCode], ['name' => $roleCode]);

        foreach ($permissions as $name) {
            $role->permissions()->syncWithoutDetaching([Permission::query()->firstOrCreate(['name' => $name])->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function patient(string $last = 'Rakoto', string $first = 'Jean', ?string $phone = null): Patient
    {
        $this->sequence++;

        return Patient::create([
            'patient_number' => sprintf('A-26-%04d', $this->sequence),
            'first_name' => $first,
            'last_name' => $last,
            'birth_date' => '1990-05-12',
            'sex' => 'M',
            'phone' => $phone,
        ]);
    }

    private function patientWith(
        int $episodes,
        ?string $paid,
        string $last = 'Rakoto',
        string $first = 'Jean',
        string $at = '2026-08-01 09:00',
        string $episodeStatus = 'OPEN',
        string $paymentStatus = 'COMPLETED',
        ?string $phone = null,
    ): Patient {
        $patient = $this->patient($last, $first, $phone);

        foreach (range(1, $episodes) as $index) {
            $this->sequence++;
            Episode::create([
                'patient_id' => $patient->id,
                'visit_sequence' => $index,
                'episode_number' => sprintf('%s-%02d', $patient->patient_number, $index),
                'status' => $episodeStatus,
                'priority' => 'NORMAL',
                'administrative_status' => 'IN_CARE',
                'started_at' => CarbonImmutable::parse($at)->addDays($index),
            ]);
        }

        if ($paid !== null) {
            $this->payment($this->invoiceFor($patient), $paid, $at, $paymentStatus);
        }

        return $patient;
    }

    private function invoiceFor(Patient $patient): Invoice
    {
        $this->sequence++;

        return Invoice::create([
            'patient_id' => $patient->id,
            'invoice_number' => sprintf('AF-%06d', $this->sequence),
            'status' => 'VALIDATED',
            'currency' => 'MGA',
            'financial_mode' => 'SELF',
            'subtotal_amount' => '0.00',
            'discount_amount' => '0.00',
            'coverage_amount' => '0.00',
            'staff_covered_amount' => '0.00',
            'staff_block_credit_used' => '0.00',
            'total_amount' => '0.00',
            'paid_amount' => '0.00',
            'balance_amount' => '0.00',
            'created_by' => $this->author()->id,
        ]);
    }

    private function payment(Invoice $invoice, string $amount, string $paidAt = '2026-08-02 09:00', string $status = 'COMPLETED'): Payment
    {
        $this->sequence++;
        $method = PaymentMethod::query()->firstOrCreate(
            ['code' => 'CASH'],
            ['name' => 'Espèces', 'category' => 'CASH', 'active' => true],
        );
        $session = CashSession::query()->firstOrCreate(
            ['session_number' => 'C-26-0001'],
            [
                'active_key' => 'SINGLE_OPEN_CASH',
                'status' => 'OPEN',
                'opening_amount' => 0,
                'opened_by' => $this->author()->id,
                'opened_at' => CarbonImmutable::parse('2026-01-01 07:00'),
            ],
        );

        return Payment::query()->create([
            'invoice_id' => $invoice->id,
            'payment_method_id' => $method->id,
            'payment_number' => sprintf('P-26-%04d', $this->sequence),
            'amount' => $amount,
            'currency' => 'MGA',
            'status' => $status,
            'paid_at' => CarbonImmutable::parse($paidAt),
            'cash_session_id' => $session->id,
            'received_by' => $this->author()->id,
        ]);
    }

    private ?User $author = null;

    private function author(): User
    {
        return $this->author ??= User::factory()->create([
            'role_id' => Role::query()->firstOrCreate(['code' => 'AUTHOR'], ['name' => 'Auteur'])->id,
        ]);
    }
}
