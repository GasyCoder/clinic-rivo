<?php

namespace App\Http\Controllers\Reception;

use App\Actions\Maternity\CreateNewbornPatientAction;
use App\Http\Controllers\Controller;
use App\Models\MaternityRecord;
use App\Models\Patient;
use App\Support\Reception\MotherNewborns;
use App\Support\Reception\PatientSearchPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-146 — « accouchement chez nous » : la Réception retrouve le bébé dans l'arborescence de sa mère.
 *
 * Le bébé n'est pas patient à l'accouchement : il vit dans le dossier de sa mère. Ces deux routes servent
 * l'écran d'arrivée — lister les bébés d'une mère, puis en faire un patient d'un clic, au moment où la
 * Réception l'accueille.
 */
class ReceptionNewbornController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('episodes.create'), 403);

        $data = $request->validate(['mother' => ['required', 'uuid']]);
        $mother = Patient::query()->where('uuid', $data['mother'])->firstOrFail();

        return response()->json([
            'mother' => PatientSearchPayload::make($mother),
            'data' => MotherNewborns::for($mother),
        ]);
    }

    public function store(
        Request $request,
        MaternityRecord $maternityRecord,
        string $newbornUuid,
        CreateNewbornPatientAction $action,
    ): JsonResponse {
        abort_unless($request->user()?->can('episodes.create'), 403);

        $data = $request->validate([
            'last_name' => ['nullable', 'string', 'max:100'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'sex' => ['nullable', 'in:M,F'],
        ]);

        $link = $action->execute($maternityRecord, $newbornUuid, $data, $request->user());

        return response()->json([
            'patient' => PatientSearchPayload::make($link->patient),
            'message' => "Dossier du nouveau-né {$link->patient->patient_number} ouvert.",
        ]);
    }
}
