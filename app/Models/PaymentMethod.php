<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\ProtectsFinancialRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'active', 'affects_cash_balance'])]
class PaymentMethod extends Model
{
    use Auditable, HasUuid, ProtectsFinancialRecord;

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'affects_cash_balance' => 'boolean',
        ];
    }

    protected function auditModule(): ?string
    {
        return 'cash';
    }
}
