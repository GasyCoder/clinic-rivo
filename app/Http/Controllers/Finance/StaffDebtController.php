<?php

namespace App\Http\Controllers\Finance;

use App\Actions\StaffDebts\DecideStaffDebtAction;
use App\Actions\StaffDebts\DisburseStaffDebtAction;
use App\Actions\StaffDebts\SettleStaffDebtDepartureAction;
use App\Actions\StaffDebts\UpdateStaffDebtSettingsAction;
use App\Enums\SalaryPaymentMode;
use App\Enums\StaffDebtRepaymentMode;
use App\Http\Controllers\Controller;
use App\Models\StaffDebt;
use App\Models\StaffDebtPenalty;
use App\Services\Audit\Auditor;
use App\Services\Spreadsheet\ExcelWorkbook;
use App\Services\StaffDebts\StaffDebtDirectory;
use App\Services\StaffDebts\StaffDebtDocuments;
use App\Services\StaffDebts\StaffDebtNotifier;
use App\Services\StaffDebts\StaffDebtPenalties;
use App\Services\StaffDebts\StaffDebtReminder;
use App\Services\StaffDebts\StaffDebtRules;
use App\Support\Authorization\RemoteActorAttribution;
use App\Support\StaffDebts\StaffDebtInterest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ADR-229 — les dettes du personnel d'un site, dans Finance au portail. Le site sert ces
 * écrans par son API seulement (routes/staff_debts.php) ; le Super Admin — le DG — les
 * décide, les ajuste, les marque versées, remet un reste, relance un retard, règle les
 * limites et les intérêts du site et exporte la liste. Chaque geste garde son droit,
 * revérifié par l'action ; tout est signé de son nom (ADR-187).
 */
class StaffDebtController extends Controller
{
    private const TERMS = [
        'amount' => ['nullable', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
        'installment_amount' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
        'first_period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        // Des conditions hors des limites du site : une dérogation, à confirmer.
        'accept_derogations' => ['sometimes', 'boolean'],
    ];

    private const NAMES = [
        'amount' => 'montant', 'installment_amount' => 'mensualité', 'first_period' => 'premier mois',
        'repayment_mode' => 'mode de remboursement', 'reason' => 'motif', 'note' => 'note',
    ];

    public function index(Request $request, StaffDebtDirectory $directory, StaffDebtRules $rules): Response
    {
        $user = $request->user();
        $present = $rules->present();

        return Inertia::render('Finance/StaffDebts/Index', [
            'listing' => $directory->listing($request->query('vue'), $request->query('q')),
            'rules' => [
                'configured' => $present['configured'],
                'requests_open' => $present['requests_open'],
                'has_interest' => $present['interest_tiers'] !== [],
            ],
            'can' => [
                'decide' => $user->can('staff_debts.decide'),
                'disburse' => $user->can('staff_debts.disburse'),
                'export' => $user->can('staff_debts.export'),
                'settings' => $user->can('staff_debts.settings'),
            ],
        ]);
    }

    public function show(Request $request, StaffDebt $staffDebt, StaffDebtDirectory $directory): Response
    {
        return Inertia::render('Finance/StaffDebts/Show', [
            'debt' => $directory->detail($staffDebt, $request->user()),
            'repaymentModes' => collect(StaffDebtRepaymentMode::cases())->map(fn ($mode) => ['value' => $mode->value, 'label' => $mode->label()])->all(),
            'disbursementModes' => collect(SalaryPaymentMode::cases())->map(fn ($mode) => ['value' => $mode->value, 'label' => $mode->label()])->all(),
            'currentMonth' => now()->format('Y-m'),
            'today' => now()->toDateString(),
        ]);
    }

    public function settings(StaffDebtRules $rules): Response
    {
        $setting = $rules->setting();

        return Inertia::render('Finance/StaffDebts/Settings', [
            'settings' => $rules->present(),
            'updated' => $setting === null ? null : [
                'at' => $setting->updated_at?->toIso8601String(),
                'by' => RemoteActorAttribution::name($setting->updater?->name, $setting->external_updated_by_name),
            ],
            'limits' => ['max_tiers' => StaffDebtInterest::MAX_TIERS],
        ]);
    }

    public function updateSettings(Request $request, UpdateStaffDebtSettingsAction $action): RedirectResponse
    {
        $data = $request->validate([
            'requests_open' => ['required', 'boolean'],
            'closed_message' => ['nullable', 'string', 'max:500'],
            'min_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
            'max_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
            'max_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'max_salary_share' => ['nullable', 'integer', 'min:1', 'max:100'],
            'max_open_debts' => ['nullable', 'integer', 'min:1', 'max:20'],
            'min_seniority_months' => ['nullable', 'integer', 'min:1', 'max:240'],
            'exclude_interns' => ['required', 'boolean'],
            'interest_tiers' => ['nullable', 'array', 'max:'.StaffDebtInterest::MAX_TIERS],
            'interest_tiers.*' => ['array'],
            'penalty_rate' => ['nullable', 'numeric', 'min:0', 'max:10', 'decimal:0,2'],
            'penalty_grace_days' => ['nullable', 'integer', 'min:0', 'max:60'],
            'penalty_cap_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
        ], [], [
            'closed_message' => 'message', 'min_amount' => 'montant minimum', 'max_amount' => 'montant maximum',
            'max_months' => 'durée maximale', 'max_salary_share' => 'part du salaire', 'max_open_debts' => 'dettes en cours',
            'min_seniority_months' => 'ancienneté minimale', 'interest_tiers' => 'tranches d’intérêt',
            'penalty_rate' => 'taux de pénalité', 'penalty_grace_days' => 'délai de grâce', 'penalty_cap_rate' => 'plafond des pénalités',
        ]);

        foreach (['min_amount', 'max_amount', 'penalty_rate', 'penalty_cap_rate'] as $key) {
            $data[$key] = isset($data[$key]) ? (string) $data[$key] : null;
        }

        $action->execute($data, $request->user());

        return back()->with('status', 'Réglages des dettes du personnel enregistrés : ils valent pour les demandes et décisions à venir.');
    }

    public function export(Request $request, StaffDebtDirectory $directory, ExcelWorkbook $workbook, Auditor $auditor): StreamedResponse
    {
        $export = $directory->export($request->query('vue'), $request->query('q'));

        $auditor->record(
            'staff_debt.export',
            newValues: ['view' => $export['view'], 'search' => $export['search'], 'rows' => count($export['rows']), 'debts' => $export['numbers']],
            module: 'finance',
            actor: $request->user(),
        );

        return $workbook->download(
            'dettes-du-personnel-'.mb_strtolower((string) config('rivo.site.code')).'-'.now()->format('Y-m-d'),
            'Dettes du personnel',
            $export['headers'],
            $export['rows'],
        );
    }

    public function approve(Request $request, StaffDebt $staffDebt, DecideStaffDebtAction $action): RedirectResponse
    {
        $data = $request->validate([
            ...self::TERMS,
            'amount' => ['required', ...array_slice(self::TERMS['amount'], 1)],
            'repayment_mode' => ['required', Rule::enum(StaffDebtRepaymentMode::class)],
            'note' => ['nullable', 'string', 'max:1000'],
            'waive_interest' => ['sometimes', 'boolean'],
            'waive_penalty' => ['sometimes', 'boolean'],
        ], [], self::NAMES);

        $debt = $action->approve($staffDebt, $this->strings($data), $request->user());

        return back()->with('status', "Dette {$debt->number} accordée à {$debt->employee_name} : elle est à verser.");
    }

    public function refuse(Request $request, StaffDebt $staffDebt, DecideStaffDebtAction $action): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']], [], self::NAMES);
        $debt = $action->refuse($staffDebt, $data['reason'], $request->user());

        return back()->with('status', "Demande {$debt->number} refusée.");
    }

    public function adjust(Request $request, StaffDebt $staffDebt, DecideStaffDebtAction $action): RedirectResponse
    {
        $data = $request->validate([
            ...self::TERMS,
            'repayment_mode' => ['required', Rule::enum(StaffDebtRepaymentMode::class)],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ], [], self::NAMES);

        $debt = $action->adjust($staffDebt, $this->strings($data), $request->user());

        return back()->with('status', "Dette {$debt->number} ajustée.");
    }

    public function cancel(Request $request, StaffDebt $staffDebt, DecideStaffDebtAction $action): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']], [], self::NAMES);
        $debt = $action->cancel($staffDebt, $data['reason'], $request->user());

        return back()->with('status', "Accord de la dette {$debt->number} annulé.");
    }

    public function writeOff(Request $request, StaffDebt $staffDebt, DecideStaffDebtAction $action): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']], [], self::NAMES);
        $debt = $action->writeOff($staffDebt, $data['reason'], $request->user());

        return back()->with('status', "Reste de la dette {$debt->number} remis.");
    }

    public function disburse(Request $request, StaffDebt $staffDebt, DisburseStaffDebtAction $action): RedirectResponse
    {
        $data = $request->validate([
            'disbursed_on' => ['required', 'date_format:Y-m-d'],
            'disbursement_mode' => ['required', Rule::enum(SalaryPaymentMode::class)],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], ['disbursed_on' => 'date du versement', 'disbursement_mode' => 'moyen', 'reference' => 'référence', 'note' => 'note']);

        $debt = $action->execute($staffDebt, $data, $request->user());

        return back()->with('status', "Dette {$debt->number} marquée versée : les remboursements commencent en ".$debt->first_period->translatedFormat('F Y').'.');
    }

    public function remind(Request $request, StaffDebt $staffDebt, StaffDebtReminder $reminder): RedirectResponse
    {
        $amount = $reminder->remind($staffDebt, $request->user());

        return back()->with('status', 'Relance envoyée à '.$staffDebt->employee_name.' et au RH du site : '.StaffDebtNotifier::money($amount).' en retard.');
    }

    /** ADR-230 — le DG remet une pénalité de retard, avec un motif. */
    public function waivePenalty(Request $request, StaffDebt $staffDebt, StaffDebtPenalty $penalty, StaffDebtPenalties $penalties): RedirectResponse
    {
        abort_unless($penalty->staff_debt_id === $staffDebt->getKey(), 404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']], [], self::NAMES);
        $penalty = $penalties->waive($staffDebt, $penalty, $data['reason'], $request->user());

        return back()->with('status', 'Pénalité de '.StaffDebtNotifier::money($penalty->amount).' remise à '.$staffDebt->employee_name.'.');
    }

    /** ADR-230 — le règlement au départ, négocié avec la personne. */
    public function settleDeparture(Request $request, StaffDebt $staffDebt, SettleStaffDebtDepartureAction $action): RedirectResponse
    {
        $data = $request->validate([
            'retained_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
            'retained_on' => ['nullable', 'date_format:Y-m-d'],
            'write_off_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
            'waive_penalties' => ['sometimes', 'boolean'],
            'installment_amount' => ['nullable', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'first_period' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'keep_penalties' => ['sometimes', 'boolean'],
            'note' => ['required', 'string', 'min:5', 'max:2000'],
        ], [], [
            ...self::NAMES, 'retained_amount' => 'retenue sur le solde de tout compte', 'retained_on' => 'date du solde de tout compte',
            'write_off_amount' => 'remise', 'note' => 'accord convenu',
        ]);

        $debt = $action->execute($staffDebt, $this->strings($data, ['retained_amount', 'write_off_amount', 'installment_amount']), $request->user());

        return back()->with('status', "Départ réglé pour la dette {$debt->number} : le protocole d’accord est prêt à imprimer.");
    }

    /** ADR-230 — la reconnaissance de dette, à signer par la personne ; jamais obligatoire. */
    public function acknowledgement(StaffDebt $staffDebt, StaffDebtDocuments $documents): Response
    {
        return Inertia::render('Finance/StaffDebts/Document', ['document' => $documents->acknowledgement($staffDebt)]);
    }

    /** ADR-230 — le protocole d'accord du règlement au départ. */
    public function departureAgreement(StaffDebt $staffDebt, StaffDebtDocuments $documents): Response
    {
        return Inertia::render('Finance/StaffDebts/Document', ['document' => $documents->departureAgreement($staffDebt)]);
    }

    /** Les montants arrivent parfois en nombres (JSON) : l'argent se lit toujours en texte. @param array<string, mixed> $data */
    private function strings(array $data, array $keys = ['amount', 'installment_amount']): array
    {
        foreach ($keys as $key) {
            if (isset($data[$key])) {
                $data[$key] = (string) $data[$key];
            }
        }

        return $data;
    }
}
