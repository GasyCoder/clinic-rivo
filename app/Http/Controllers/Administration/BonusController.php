<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Bonus\ArchiveBonusCategoryAction;
use App\Actions\Bonus\SaveBonusCategoryAction;
use App\Actions\Bonus\SettleBonusAwardAction;
use App\Actions\Bonus\ValidateBonusAwardAction;
use App\Enums\BonusMeasure;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bonus\BonusCategoryRequest;
use App\Models\BonusAward;
use App\Models\BonusCategory;
use App\Models\Employee;
use App\Services\Bonus\BonusBoard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-212 — les bonus du personnel, une rubrique RH (servie aussi au portail,
 * ADR-187). Le tableau du mois lit ; chaque geste passe par son action, qui
 * revérifie son droit. Le versement se fait hors RIVO : on le trace.
 */
class BonusController extends Controller
{
    public function index(Request $request, BonusBoard $board): Response
    {
        $month = $this->month($request->query('mois')) ?? now()->startOfMonth();
        $canManage = $request->user()->can('bonus_categories.view');

        return Inertia::render('Administration/Bonus/Index', [
            'month' => $month->format('Y-m'),
            'currentMonth' => now()->format('Y-m'),
            'board' => $board->month($month),
            'measures' => BonusMeasure::options(),
            'categories' => $canManage ? BonusCategory::withTrashed()
                ->withCount('awards')
                ->with(['employees' => fn ($query) => $query->withTrashed()->orderBy('last_name')])
                ->orderBy('name')
                ->get()
                ->map(fn (BonusCategory $category) => [
                    'uuid' => $category->uuid,
                    'name' => $category->name,
                    'measure' => $category->measure->value,
                    'measure_label' => $category->measure->label(),
                    'threshold' => $category->threshold,
                    'amount' => (string) $category->amount,
                    'description' => $category->description,
                    'archived' => $category->trashed(),
                    'delete_reason' => $category->delete_reason,
                    'awards_count' => $category->awards_count,
                    'employees' => $category->employees->map(fn (Employee $employee) => [
                        'uuid' => $employee->uuid,
                        'name' => Str::squish("{$employee->last_name} {$employee->first_name}"),
                        'in_post' => $employee->active && ! $employee->trashed(),
                    ])->values(),
                ])->values() : null,
            // Le personnel à choisir dans une catégorie : en poste, avec sa fonction.
            'staff' => $canManage ? Employee::query()
                ->with('jobTitle:id,label')
                ->where('active', true)
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get()
                ->map(fn (Employee $employee) => [
                    'uuid' => $employee->uuid,
                    'name' => Str::squish("{$employee->last_name} {$employee->first_name}"),
                    'employee_number' => $employee->employee_number,
                    'job_title' => $employee->jobTitle?->label ?? $employee->profession,
                    'has_account' => $employee->user_id !== null,
                ])->values() : null,
        ]);
    }

    public function storeCategory(BonusCategoryRequest $request, SaveBonusCategoryAction $action): RedirectResponse
    {
        $category = $action->execute(null, $request->validated(), $request->user());

        return back()->with('status', "Catégorie « {$category->name} » créée.");
    }

    public function updateCategory(BonusCategoryRequest $request, BonusCategory $category, SaveBonusCategoryAction $action): RedirectResponse
    {
        $category = $action->execute($category, $request->validated(), $request->user());

        return back()->with('status', "Catégorie « {$category->name} » mise à jour.");
    }

    public function destroyCategory(Request $request, BonusCategory $category, ArchiveBonusCategoryAction $action): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']], [], ['reason' => 'motif'])['reason'];
        $action->archive($category, str($reason)->squish()->toString(), $request->user());

        return back()->with('status', "Catégorie « {$category->name} » archivée.");
    }

    public function restoreCategory(Request $request, BonusCategory $category, ArchiveBonusCategoryAction $action): RedirectResponse
    {
        $action->restore($category, $request->user());

        return back()->with('status', "Catégorie « {$category->name} » restaurée.");
    }

    public function validateAward(Request $request, ValidateBonusAwardAction $action): RedirectResponse
    {
        $data = $request->validate([
            'category_uuid' => ['required', 'uuid'],
            'employee_uuid' => ['required', 'uuid'],
            'mois' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);
        $category = BonusCategory::query()->where('uuid', $data['category_uuid'])->firstOrFail();
        $employee = Employee::withTrashed()->where('uuid', $data['employee_uuid'])->firstOrFail();

        $award = $action->execute($category, $employee, $this->month($data['mois']), $request->user());

        $name = Str::squish("{$employee->last_name} {$employee->first_name}");

        return back()->with('status', "Bonus de {$name} validé : {$award->category_name}.");
    }

    public function payAward(Request $request, BonusAward $award, SettleBonusAwardAction $action): RedirectResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;
        $action->pay($award, filled($note) ? str($note)->squish()->toString() : null, $request->user());

        return back()->with('status', 'Bonus marqué versé.');
    }

    public function cancelAward(Request $request, BonusAward $award, SettleBonusAwardAction $action): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']], [], ['reason' => 'motif'])['reason'];
        $action->cancel($award, str($reason)->squish()->toString(), $request->user());

        return back()->with('status', 'Bonus annulé.');
    }

    /** « 2026-09 » ; toute autre valeur est ignorée. */
    private function month(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d', "{$value}-01")->startOfDay();
    }
}
