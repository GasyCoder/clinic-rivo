<?php

namespace App\Models;

use App\Enums\PaymentMethodCategory;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\ProtectsFinancialRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'category', 'active', 'affects_cash_balance', 'requires_reference'])]
class PaymentMethod extends Model
{
    use Auditable, HasUuid, ProtectsFinancialRecord;

    protected $attributes = [
        'category' => PaymentMethodCategory::Other->value,
    ];

    protected function casts(): array
    {
        return [
            'category' => PaymentMethodCategory::class,
            'active' => 'boolean',
            'affects_cash_balance' => 'boolean',
            'requires_reference' => 'boolean',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    protected function auditModule(): ?string
    {
        return 'cash';
    }
}
