<?php

namespace Database\Seeders;

use App\Models\CashRegister;
use Database\Seeders\Concerns\LocalOnly;
use Illuminate\Database\Seeder;
use LogicException;

/**
 * Two named cash desks, so concurrent sessions (ADR-058) can be tested right
 * after a fresh database. No payment method restriction: an empty list means
 * the desk accepts every active method.
 */
class DevelopmentCashRegisterSeeder extends Seeder
{
    use LocalOnly;

    private const REGISTERS = ['Caisse 1', 'Caisse 2'];

    public function run(): void
    {
        $this->ensureLocal();

        if (config('rivo.site.type') !== 'clinic') {
            throw new LogicException('Les caisses existent uniquement sur un site opérationnel.');
        }

        foreach (self::REGISTERS as $name) {
            CashRegister::withTrashed()->firstOrCreate(
                ['normalized_name' => CashRegister::normalize($name)],
                ['name' => $name, 'active' => true],
            );
        }
    }
}
