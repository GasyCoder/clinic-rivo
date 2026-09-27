<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Mobile money" is a category now, not a tender: the cashier picks MVola,
 * Orange Money or Airtel Money, each reconciled on its own operator account.
 * The old generic row would sit next to its own category and mean nothing.
 *
 * It is removed only where no payment ever referenced it. A row that was
 * actually used stays — deactivated — because a recorded payment must keep
 * pointing at the tender it was collected with (ADR-010).
 */
return new class extends Migration
{
    public function up(): void
    {
        $generic = DB::table('payment_methods')->where('code', 'MOBILE_MONEY')->first();

        if (! $generic) {
            return;
        }

        $used = DB::table('payments')->where('payment_method_id', $generic->id)->exists();

        if ($used) {
            DB::table('payment_methods')->where('id', $generic->id)->update(['active' => false]);

            return;
        }

        DB::table('payment_methods')->where('id', $generic->id)->delete();
    }

    public function down(): void
    {
        // Recreating a generic tender that no payment references would put
        // back exactly the confusion this migration removed.
    }
};
