<?php

namespace App\Actions\Reception;

use App\Models\VisitorVisit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CloseVisitorVisitAction
{
    public function execute(VisitorVisit $visitorVisit): VisitorVisit
    {
        return DB::transaction(function () use ($visitorVisit) {
            $visitorVisit = VisitorVisit::query()->lockForUpdate()->findOrFail($visitorVisit->id);

            if (! $visitorVisit->isPresent()) {
                throw ValidationException::withMessages([
                    'visitor' => 'La sortie de ce visiteur est déjà enregistrée.',
                ]);
            }

            $visitorVisit->update([
                'checked_out_at' => now(),
                'closed_by' => Auth::id(),
            ]);

            return $visitorVisit;
        });
    }
}
