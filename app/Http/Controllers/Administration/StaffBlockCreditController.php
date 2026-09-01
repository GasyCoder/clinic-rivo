<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Administration\AllocateStaffBlockCreditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\AllocateStaffBlockCreditRequest;
use App\Models\Employee;
use App\Models\StaffBlockCreditMovement;
use App\Services\Finance\StaffBlockCreditLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StaffBlockCreditController extends Controller
{
    public function index(Request $request, StaffBlockCreditLedger $ledger): Response
    {
        $search = trim((string) $request->query('q', ''));
        $selectedUuid = trim((string) $request->query('employee', ''));
        $employees = Employee::withTrashed()
            ->with('jobTitle')
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search) {
                $nested->where('employee_number', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            }))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(20, ['*'], 'employees_page')
            ->withQueryString()
            ->through(fn (Employee $employee) => [
                'uuid' => $employee->uuid,
                'employee_number' => $employee->employee_number,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'profession' => $employee->profession,
                'job_title' => $employee->jobTitle?->label ?? $employee->profession,
                'active' => $employee->active && ! $employee->trashed(),
                'credit' => $ledger->summary($employee),
            ]);

        $selected = $selectedUuid !== ''
            ? Employee::withTrashed()->with('jobTitle')->where('uuid', $selectedUuid)->first()
            : null;
        $movements = $selected
            ? StaffBlockCreditMovement::query()
                ->where('employee_id', $selected->getKey())
                ->with(['episode:id,uuid,episode_number', 'billableItem:id,uuid,description', 'creator:id,name'])
                ->latest('id')
                ->paginate(50, ['*'], 'movements_page')
                ->withQueryString()
                ->through(fn (StaffBlockCreditMovement $movement) => [
                    'uuid' => $movement->uuid,
                    'movement_type' => $movement->movement_type->value,
                    'movement_type_label' => $movement->movement_type->label(),
                    'amount' => $movement->amount,
                    'balance_before' => $movement->balance_before,
                    'balance_after' => $movement->balance_after,
                    'reason' => $movement->reason,
                    'episode_number' => $movement->episode?->episode_number,
                    'billable_item_uuid' => $movement->billableItem?->uuid,
                    'billable_item_description' => $movement->billableItem?->description,
                    'created_by' => $movement->creator?->name,
                    'created_at' => $movement->created_at?->toIso8601String(),
                ])
            : null;

        return Inertia::render('Administration/StaffBlockCredits/Index', [
            'employees' => $employees,
            'selectedEmployee' => $selected ? [
                'uuid' => $selected->uuid,
                'employee_number' => $selected->employee_number,
                'first_name' => $selected->first_name,
                'last_name' => $selected->last_name,
                'profession' => $selected->profession,
                'job_title' => $selected->jobTitle?->label ?? $selected->profession,
                'active' => $selected->active && ! $selected->trashed(),
                'credit' => $ledger->summary($selected),
            ] : null,
            'movements' => $movements,
            'filters' => ['q' => $search],
        ]);
    }

    public function store(
        AllocateStaffBlockCreditRequest $request,
        Employee $employee,
        AllocateStaffBlockCreditAction $action,
    ): RedirectResponse {
        $movement = $action->execute(
            $employee,
            $request->validated('amount'),
            $request->validated('idempotency_key'),
            $request->validated('reason'),
            $request->user(),
        );

        return back()->with('status', "Crédit Bloc alloué. Nouveau solde : {$movement->balance_after} Ar.");
    }
}
