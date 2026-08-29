<?php

namespace App\Http\Controllers\Reception;

use App\Actions\Administration\LinkPatientToEmployeeAction;
use App\Actions\Episode\SetEpisodeFinancialContextAction;
use App\Enums\EpisodeFinancialMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\SetReceptionEpisodeFinancialContextRequest;
use App\Models\Employee;
use App\Models\Episode;
use App\Services\Reception\ReceptionFinancialPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class EpisodeFinancialContextController extends Controller
{
    public function store(
        SetReceptionEpisodeFinancialContextRequest $request,
        Episode $episode,
        SetEpisodeFinancialContextAction $setFinancialContext,
        LinkPatientToEmployeeAction $linkPatientToEmployee,
        ReceptionFinancialPreviewService $previews,
    ): JsonResponse {
        $mode = EpisodeFinancialMode::from($request->validated('financial_mode'));
        $context = match ($mode) {
            EpisodeFinancialMode::Self => [],
            EpisodeFinancialMode::Mutual => $request->safe()->only([
                'mutual_organization_uuid', 'employer_name',
                'beneficiary_type', 'membership_number',
            ]),
            EpisodeFinancialMode::Staff => $request->safe()->only(['employee_uuid']),
            EpisodeFinancialMode::Partner => $request->safe()->only(['partner_organization_uuid']),
        };

        $episode = DB::transaction(function () use (
            $episode,
            $mode,
            $context,
            $request,
            $linkPatientToEmployee,
            $setFinancialContext,
        ): Episode {
            $episode->loadMissing('patient');

            if ($mode === EpisodeFinancialMode::Staff) {
                $employee = Employee::query()
                    ->where('uuid', $context['employee_uuid'])
                    ->where('active', true)
                    ->lockForUpdate()
                    ->firstOrFail();

                // The permanent identity link is distinct from the Episode
                // coverage. This existing action is idempotent for the same
                // pair and rejects any Patient/Employee already linked to a
                // different active record.
                $linkPatientToEmployee->execute($episode->patient, $employee, $request->user());
            }

            return $setFinancialContext->execute(
                $episode,
                $mode,
                $context,
                $request->user(),
            );
        });

        $episode->load([
            'patient:id,uuid,patient_number,first_name,last_name',
            'mutualCoverage',
            'staffCoverage.employee:id,uuid,employee_number,first_name,last_name,profession,active',
            'partnerCoverage',
        ]);

        return response()->json([
            'episode' => [
                'uuid' => $episode->uuid,
                'episode_number' => $episode->episode_number,
                'financial_mode' => $episode->financial_mode->value,
                'financial_mode_label' => $episode->financial_mode->label(),
            ],
            'preview' => $request->validated('lines') === []
                ? null
                : $previews->preview($episode, $request->validated('lines')),
        ]);
    }
}
