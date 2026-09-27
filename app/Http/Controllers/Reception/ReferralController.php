<?php

namespace App\Http\Controllers\Reception;

use App\Actions\Reception\MarkReferralGiftGivenAction;
use App\Enums\ReferralSource;
use App\Http\Controllers\Controller;
use App\Models\PatientReferral;
use App\Support\Authorization\RemoteActorAttribution;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-212 — les recommandations : qui a recommandé la clinique à chaque nouveau
 * patient, et le cadeau qui lui a été remis. La liste lit, elle ne décide rien ;
 * le seul geste est « cadeau remis ».
 */
class ReferralController extends Controller
{
    private const GIFT_VIEWS = ['a-remettre', 'remis', 'tous'];

    public function index(Request $request): Response
    {
        $month = $this->month($request->query('mois'));
        $gift = in_array($request->query('cadeau'), self::GIFT_VIEWS, true) ? $request->query('cadeau') : 'tous';
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        // « % » et « _ » se cherchent tels quels.
        $like = '%'.addcslashes($search, '%_\\').'%';
        $canViewPatients = $request->user()->can('patients.view');

        $base = PatientReferral::query()
            ->when($month, fn ($query) => $query->whereBetween('referred_at', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()]))
            ->when($search !== '', fn ($query) => $query->where(fn ($nested) => $nested
                ->where('referrer_name', 'like', $like)
                ->orWhereHas('patient', fn ($patient) => $patient
                    ->where('patient_number', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('first_name', 'like', $like))));

        $counts = [
            'tous' => (clone $base)->count(),
            'a-remettre' => (clone $base)->whereNull('gift_given_at')->count(),
            'remis' => (clone $base)->whereNotNull('gift_given_at')->count(),
        ];

        $referrals = $base
            ->when($gift === 'a-remettre', fn ($query) => $query->whereNull('gift_given_at'))
            ->when($gift === 'remis', fn ($query) => $query->whereNotNull('gift_given_at'))
            ->with([
                'patient:id,uuid,patient_number,first_name,last_name,deleted_at',
                'employee:id,uuid,employee_number,deleted_at',
                'partner:id,uuid,category,deleted_at',
                'recorder:id,name',
                'giftGiver:id,name',
            ])
            ->latest('referred_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (PatientReferral $referral) => [
                'uuid' => $referral->uuid,
                'source' => $referral->source->value,
                'source_label' => $referral->source->label(),
                'referrer_name' => $referral->referrer_name,
                'referrer_phone' => $referral->referrer_phone,
                'referrer_detail' => match ($referral->source) {
                    ReferralSource::Employee => $referral->employee?->employee_number,
                    ReferralSource::Partner => $referral->partner?->category?->label(),
                    ReferralSource::Other => null,
                },
                'patient' => $referral->patient ? [
                    'uuid' => $canViewPatients ? $referral->patient->uuid : null,
                    'patient_number' => $referral->patient->patient_number,
                    'name' => trim("{$referral->patient->last_name} {$referral->patient->first_name}"),
                ] : null,
                'referred_at' => $referral->referred_at?->toIso8601String(),
                'recorded_by' => RemoteActorAttribution::name($referral->recorder?->name, $referral->external_recorded_by_name),
                'gift_given_at' => $referral->gift_given_at?->toIso8601String(),
                'gift_given_by' => RemoteActorAttribution::name($referral->giftGiver?->name, $referral->external_gift_given_by_name),
                'gift_note' => $referral->gift_note,
            ]);

        return Inertia::render('Reception/Referrals/Index', [
            'referrals' => $referrals,
            'counts' => $counts,
            'filters' => ['mois' => $month?->format('Y-m'), 'cadeau' => $gift, 'q' => $search],
            'currentMonth' => now()->format('Y-m'),
        ]);
    }

    public function gift(Request $request, PatientReferral $referral, MarkReferralGiftGivenAction $action): RedirectResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;
        $action->execute($referral, filled($note) ? str($note)->squish()->toString() : null, $request->user());

        return back()->with('status', "Cadeau de {$referral->referrer_name} marqué remis.");
    }

    /** « 2026-09 » ; toute autre valeur montre toutes les recommandations. */
    private function month(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d', "{$value}-01")->startOfDay();
    }
}
