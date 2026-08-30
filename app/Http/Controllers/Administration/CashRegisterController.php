<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\ArchiveCashRegisterRequest;
use App\Http\Requests\Administration\StoreCashRegisterRequest;
use App\Http\Requests\Administration\UpdateCashRegisterRequest;
use App\Models\CashRegister;
use App\Services\Cash\CashRegisterManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CashRegisterController extends Controller
{
    public function index(Request $request): Response
    {
        $status = in_array($request->query('status'), ['active', 'archived', 'all'], true)
            ? $request->query('status')
            : 'active';

        $registers = CashRegister::query()
            ->withCount('sessions')
            ->when($status === 'archived', fn ($query) => $query->onlyTrashed())
            ->when($status === 'all', fn ($query) => $query->withTrashed())
            ->orderBy('name')
            ->get()
            ->map(fn (CashRegister $register) => $this->serialize($register));

        return Inertia::render('Administration/CashRegisters/Index', [
            'registers' => $registers,
            'filters' => ['status' => $status],
            'summary' => [
                'active' => CashRegister::query()->count(),
                'archived' => CashRegister::onlyTrashed()->count(),
            ],
        ]);
    }

    public function store(StoreCashRegisterRequest $request, CashRegisterManager $manager): RedirectResponse
    {
        $register = $manager->create($request->validated('name'));

        return back()->with('status', "Caisse « {$register->name} » créée.");
    }

    public function update(
        UpdateCashRegisterRequest $request,
        CashRegister $cashRegister,
        CashRegisterManager $manager,
    ): RedirectResponse {
        $manager->update($cashRegister, $request->validated('name'));

        return back()->with('status', "Caisse « {$cashRegister->name} » mise à jour.");
    }

    public function activate(Request $request, CashRegister $cashRegister, CashRegisterManager $manager): RedirectResponse
    {
        $manager->activate($cashRegister);

        return back()->with('status', "Caisse « {$cashRegister->name} » activée.");
    }

    public function deactivate(Request $request, CashRegister $cashRegister, CashRegisterManager $manager): RedirectResponse
    {
        $manager->deactivate($cashRegister);

        return back()->with('status', "Caisse « {$cashRegister->name} » désactivée.");
    }

    public function destroy(
        ArchiveCashRegisterRequest $request,
        CashRegister $cashRegister,
        CashRegisterManager $manager,
    ): RedirectResponse {
        $manager->archive($cashRegister, $request->validated('reason'));

        return back()->with('status', "Caisse « {$cashRegister->name} » archivée.");
    }

    public function restore(Request $request, string $cashRegister, CashRegisterManager $manager): RedirectResponse
    {
        $register = CashRegister::onlyTrashed()->where('uuid', $cashRegister)->firstOrFail();
        $manager->restore($register);

        return back()->with('status', "Caisse « {$register->name} » restaurée.");
    }

    /** @return array<string, mixed> */
    private function serialize(CashRegister $register): array
    {
        return [
            'uuid' => $register->uuid,
            'name' => $register->name,
            'active' => $register->active,
            'archived' => $register->trashed(),
            'archived_at' => $register->deleted_at,
            'archive_reason' => $register->delete_reason,
            'sessions_count' => $register->sessions_count,
        ];
    }
}
