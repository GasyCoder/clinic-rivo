<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Bonus\ArchiveBonusCategoryAction;
use App\Actions\Bonus\SaveAdvantageArticleAction;
use App\Actions\Bonus\ValidateAdvantageAwardAction;
use App\Enums\AdvantageSource;
use App\Enums\CatalogItemType;
use App\Models\AdvantageArticle;
use App\Models\AdvantageAward;
use App\Models\CatalogItem;
use App\Models\PartnerOrganization;
use App\Services\Bonus\AdvantageBoard;
use App\Services\Payroll\AdvantageEntryDirectory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
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
    public function index(Request $request, BonusBoard $board, AdvantageBoard $advantages, AdvantageEntryDirectory $entries): Response
    {
        $month = $this->month($request->query('mois')) ?? now()->startOfMonth();
        $canManage = $request->user()->can('bonus_categories.view');

        return Inertia::render('Administration/Bonus/Index', [
            'month' => $month->format('Y-m'),
            'currentMonth' => now()->format('Y-m'),
            'board' => $board->month($month),
            // Onglet « Avantages à l'acte » : quantité × prix unitaire, comptés par RIVO.
            'tab' => match ($request->query('onglet')) {
                'avantages' => 'advantages',
                'saisis' => 'entries',
                default => 'bonus',
            },
            // ADR-227 — avantages saisis pour les médecins (montant, motif, mois de paie).
            'entries' => $request->user()->can('advantage_entries.view') ? $entries->month($month) : null,
            'advantages' => $advantages->month($month),
            'advantageSources' => AdvantageSource::options(),
            'advantageArticles' => $canManage ? AdvantageArticle::withTrashed()
                ->with(['catalogItems' => fn ($query) => $query->withTrashed()->orderBy('name')])
                ->orderBy('name')->get()
                ->map(fn (AdvantageArticle $article) => [
                    'uuid' => $article->uuid,
                    'name' => $article->name,
                    'source' => $article->source->value,
                    'source_label' => $article->source->label(),
                    'unit_price' => (string) $article->unit_price,
                    'description' => $article->description,
                    'archived' => $article->trashed(),
                    'delete_reason' => $article->delete_reason,
                    'items' => $article->catalogItems->map(fn (CatalogItem $item) => ['uuid' => $item->uuid, 'code' => $item->code, 'name' => $item->name])->values(),
                ])->values() : null,
            // Les actes du catalogue qu'un article peut regrouper.
            'catalogChoices' => $canManage ? CatalogItem::query()
                ->where('type', CatalogItemType::Service->value)
                ->orderBy('module')->orderBy('name')
                ->get(['uuid', 'code', 'name', 'module'])
                ->map(fn (CatalogItem $item) => ['uuid' => $item->uuid, 'code' => $item->code, 'name' => $item->name, 'module' => $item->module?->label()])
                ->values() : null,
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

    public function storeArticle(Request $request, SaveAdvantageArticleAction $action): RedirectResponse
    {
        $article = $action->execute(null, $this->articleData($request), $request->user());

        return $this->toAdvantages("Article « {$article->name} » créé.");
    }

    public function updateArticle(Request $request, AdvantageArticle $article, SaveAdvantageArticleAction $action): RedirectResponse
    {
        $article = $action->execute($article, $this->articleData($request), $request->user());

        return $this->toAdvantages("Article « {$article->name} » mis à jour.");
    }

    public function destroyArticle(Request $request, AdvantageArticle $article, SaveAdvantageArticleAction $action): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']], [], ['reason' => 'motif'])['reason'];
        $action->archive($article, str($reason)->squish()->toString(), $request->user());

        return $this->toAdvantages("Article « {$article->name} » archivé.");
    }

    public function restoreArticle(Request $request, AdvantageArticle $article, SaveAdvantageArticleAction $action): RedirectResponse
    {
        $action->restore($article, $request->user());

        return $this->toAdvantages("Article « {$article->name} » restauré.");
    }

    public function validateAdvantage(Request $request, ValidateAdvantageAwardAction $action): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['EMPLOYEE', 'PARTNER'])],
            'uuid' => ['required', 'uuid'],
            'mois' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);
        $beneficiary = $data['type'] === 'EMPLOYEE'
            ? Employee::withTrashed()->where('uuid', $data['uuid'])->firstOrFail()
            : PartnerOrganization::withTrashed()->where('uuid', $data['uuid'])->firstOrFail();

        $award = $action->execute($beneficiary, $this->month($data['mois']), $request->user());

        return $this->toAdvantages("Avantage de {$award->beneficiary_name} validé.");
    }

    public function payAdvantage(Request $request, AdvantageAward $award, ValidateAdvantageAwardAction $action): RedirectResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;
        $action->pay($award, filled($note) ? str($note)->squish()->toString() : null, $request->user());

        return $this->toAdvantages('Avantage marqué versé.');
    }

    public function cancelAdvantage(Request $request, AdvantageAward $award, ValidateAdvantageAwardAction $action): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']], [], ['reason' => 'motif'])['reason'];
        $action->cancel($award, str($reason)->squish()->toString(), $request->user());

        return $this->toAdvantages('Avantage annulé.');
    }

    /** @return array<string, mixed> */
    private function articleData(Request $request): array
    {
        if (is_string($request->input('unit_price'))) {
            $request->merge(['unit_price' => str_replace(',', '.', preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $request->input('unit_price')))]);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'source' => ['required', new Enum(AdvantageSource::class)],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
            'description' => ['nullable', 'string', 'max:1000'],
            'catalog_item_uuids' => ['required', 'array', 'min:1', 'max:200'],
            'catalog_item_uuids.*' => ['uuid'],
        ], [
            'catalog_item_uuids.required' => 'Choisissez au moins un acte du catalogue.',
            'catalog_item_uuids.min' => 'Choisissez au moins un acte du catalogue.',
        ], ['name' => 'nom', 'source' => 'ce qu’il compte', 'unit_price' => 'prix unitaire']);
    }

    private function toAdvantages(string $status): RedirectResponse
    {
        return back()->with('status', $status);
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
