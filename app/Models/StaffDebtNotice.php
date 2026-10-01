<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** ADR-228 — portail : une demande de dette d'un site n'est signalée qu'une fois au DG. */
#[Fillable(['site_code', 'debt_uuid', 'noticed_at'])]
class StaffDebtNotice extends Model
{
    protected function casts(): array
    {
        return ['noticed_at' => 'datetime'];
    }
}
