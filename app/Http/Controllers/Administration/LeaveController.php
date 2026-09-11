<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Administration\ApproveLeaveRequestAction;
use App\Actions\Administration\CancelLeaveRequestAction;
use App\Actions\Administration\CreateLeaveRequestAction;
use App\Actions\Administration\RejectLeaveRequestAction;
use App\Enums\HrReferenceType;
use App\Enums\LeaveRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\CancelLeaveRequest;
use App\Http\Requests\Administration\LeaveDecisionRequest;
use App\Http\Requests\Administration\PreviewLeaveRequest;
use App\Http\Requests\Administration\StoreLeaveRequest;
use App\Models\Employee;
use App\Models\HrReferenceValue;
use App\Models\LeaveRequest;
use App\Services\Administration\HrPresenter;
use App\Services\Administration\LeaveBalanceCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class LeaveController extends Controller
{
    public function __construct(private readonly HrPresenter $presenter) {}

    public function index(Request $request): Response
    {
        Gate::forUser($request->user())->authorize('viewAny', LeaveRequest::class);
        $status = in_array($request->query('status'), [...array_column(LeaveRequestStatus::cases(), 'value'), 'ALL'], true)
            ? $request->query('status') : LeaveRequestStatus::Pending->value;

        $leaves = LeaveRequest::query()
            ->when($status !== 'ALL', fn ($query) => $query->where('status', $status))
            ->with(['employee.department', 'employee.jobTitle', 'interimEmployee.department', 'interimEmployee.jobTitle', 'leaveType', 'decidedBy'])
            ->latest('requested_on')->paginate(20)->withQueryString()
            ->through(fn ($leave) => $this->presenter->leave($leave));

        return Inertia::render('Administration/Leave/Index', [
            'leaves' => $leaves,
            'filterStatus' => $status,
            'summary' => collect(LeaveRequestStatus::cases())->mapWithKeys(fn ($case) => [
                $case->value => LeaveRequest::query()->where('status', $case->value)->count(),
            ]),
            'statuses' => collect(LeaveRequestStatus::cases())->map(fn ($case) => [
                'value' => $case->value, 'label' => $case->label(),
            ]),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::forUser($request->user())->authorize('create', LeaveRequest::class);

        return Inertia::render('Administration/Leave/Create', [
            'employees' => $this->employees(),
            'selectedEmployeeUuid' => $request->query('employee'),
            'defaultRequestedOn' => now()->toDateString(),
            'leaveTypes' => HrReferenceValue::query()->ofType(HrReferenceType::LeaveType)
                ->where('active', true)->orderBy('position')->orderBy('label')->get()
                ->map(fn (HrReferenceValue $type) => [
                    'uuid' => $type->uuid,
                    'label' => $type->label,
                    'rules' => $type->metadata,
                ]),
        ]);
    }

    public function preview(PreviewLeaveRequest $request, LeaveBalanceCalculator $calculator): JsonResponse
    {
        $employee = Employee::query()->where('uuid', $request->validated('employee_uuid'))->firstOrFail();
        $leaveType = HrReferenceValue::query()->where('uuid', $request->validated('leave_type_uuid'))->firstOrFail();

        return response()->json(['preview' => $calculator->preview(
            $employee,
            $leaveType,
            CarbonImmutable::parse($request->validated('starts_on'))->startOfDay(),
            CarbonImmutable::parse($request->validated('returns_on'))->startOfDay(),
        )]);
    }

    public function store(StoreLeaveRequest $request, CreateLeaveRequestAction $action): RedirectResponse
    {
        $action->execute($request->validated(), $request->user());

        return to_route('administration.leave.index')->with('status', 'Demande de congé enregistrée.');
    }

    public function approve(LeaveDecisionRequest $request, LeaveRequest $leave, ApproveLeaveRequestAction $action): RedirectResponse
    {
        $action->execute($leave, $request->validated('reason'), $request->user());

        return back()->with('status', 'Demande de congé acceptée.');
    }

    public function reject(LeaveDecisionRequest $request, LeaveRequest $leave, RejectLeaveRequestAction $action): RedirectResponse
    {
        $action->execute($leave, $request->validated('reason'), $request->user());

        return back()->with('status', 'Demande de congé refusée.');
    }

    public function cancel(CancelLeaveRequest $request, LeaveRequest $leave, CancelLeaveRequestAction $action): RedirectResponse
    {
        $action->execute($leave, $request->validated('reason'), $request->user());

        return back()->with('status', 'Demande de congé annulée.');
    }

    public function print(Request $request, LeaveRequest $leave): Response
    {
        abort_unless($request->user()->can('leave.print'), 403);
        Gate::forUser($request->user())->authorize('view', $leave);
        $leave->load(['employee.department', 'employee.jobTitle', 'interimEmployee.department', 'interimEmployee.jobTitle', 'leaveType', 'decidedBy']);

        return Inertia::render('Administration/Leave/Print', [
            'leave' => $this->presenter->leave($leave),
        ]);
    }

    private function employees()
    {
        return Employee::query()->where('active', true)->with(['department', 'jobTitle'])
            ->orderBy('last_name')->get()->map(fn ($employee) => $this->presenter->employeeOption($employee));
    }
}
