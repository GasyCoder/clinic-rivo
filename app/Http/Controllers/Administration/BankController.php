<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Administration\ArchiveBankAction;
use App\Actions\Administration\RestoreBankAction;
use App\Actions\Administration\SaveBankAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\ArchiveBankRequest;
use App\Http\Requests\Administration\BankRequest;
use App\Models\Bank;
use App\Services\Administration\HrPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-213 — le module Banques de l'espace RH : la liste des banques du site,
 * que la fiche d'un employé propose pour son compte bancaire. Mêmes droits
 * que les autres référentiels RH (`hr_settings.*`) ; servi aussi au portail
 * par `routes/hr.php` (ADR-187).
 */
class BankController extends Controller
{
    public function __construct(private readonly HrPresenter $presenter) {}

    public function index(Request $request): Response
    {
        Gate::forUser($request->user())->authorize('viewAny', Bank::class);

        $banks = Bank::withTrashed()
            // Un dossier archivé ne compte plus : la personne ne travaille plus ici.
            ->withCount(['employees as employees_count' => fn ($query) => $query->whereNull('deleted_at')])
            ->orderBy('position')->orderBy('code')
            ->get()
            ->map(fn (Bank $bank) => [
                ...$this->presenter->bank($bank),
                'bank_code' => $bank->bank_code,
                'swift_code' => $bank->swift_code,
                'phone' => $bank->phone,
                'address' => $bank->address,
                'notes' => $bank->notes,
                'position' => (int) $bank->position,
                'active' => (bool) $bank->active,
                'archived' => $bank->trashed(),
                'delete_reason' => $bank->delete_reason,
                'employees_count' => (int) $bank->employees_count,
            ]);

        return Inertia::render('Administration/Banks/Index', ['banks' => $banks]);
    }

    public function store(BankRequest $request, SaveBankAction $action): RedirectResponse
    {
        $bank = $action->execute($request->validated(), $request->user());

        return back()->with('status', "Banque « {$bank->code} » ajoutée.");
    }

    public function update(BankRequest $request, Bank $bank, SaveBankAction $action): RedirectResponse
    {
        $bank = $action->execute($request->validated(), $request->user(), $bank);

        return back()->with('status', "Banque « {$bank->code} » mise à jour.");
    }

    public function destroy(ArchiveBankRequest $request, Bank $bank, ArchiveBankAction $action): RedirectResponse
    {
        $action->execute($bank, $request->validated('reason'), $request->user());

        return back()->with('status', "Banque « {$bank->code} » archivée.");
    }

    public function restore(Request $request, Bank $bank, RestoreBankAction $action): RedirectResponse
    {
        $action->execute($bank, $request->user());

        return back()->with('status', "Banque « {$bank->code} » restaurée.");
    }
}
