<?php

namespace App\Http\Controllers;

use App\Actions\Surgery\ValidatePreoperativeAssessmentAction;
use App\Models\SurgicalRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SurgicalPreoperativeController extends Controller
{
    public function validatePreoperative(Request $request, SurgicalRequest $surgicalRequest, ValidatePreoperativeAssessmentAction $action): RedirectResponse
    {
        $action->execute($surgicalRequest, $request->user());

        return back()->with('status', 'Bilan préopératoire validé.');
    }
}
