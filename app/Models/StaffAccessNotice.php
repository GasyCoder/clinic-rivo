<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-197 — portail : un employé d'un site déjà signalé au Super Admin. Le
 * portail ne reçoit rien des sites ; il lit leur API et compare avec ces lignes,
 * pour ne notifier qu'une fois chaque ajout.
 */
#[Fillable(['site_code', 'employee_uuid', 'noticed_at'])]
class StaffAccessNotice extends Model
{
    protected function casts(): array
    {
        return ['noticed_at' => 'datetime'];
    }
}
