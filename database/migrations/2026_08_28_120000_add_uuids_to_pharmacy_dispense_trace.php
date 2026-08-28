<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $this->addUuid('pharmacy_dispense_lines');
        $this->addUuid('pharmacy_dispense_allocations');
    }

    public function down(): void
    {
        $this->dropUuid('pharmacy_dispense_allocations');
        $this->dropUuid('pharmacy_dispense_lines');
    }

    private function addUuid(string $tableName): void
    {
        if (! Schema::hasColumn($tableName, 'uuid')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->uuid('uuid')->nullable()->unique()->after('id');
            });
        }

        DB::table($tableName)
            ->select('id')
            ->whereNull('uuid')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($tableName): void {
                foreach ($rows as $row) {
                    DB::table($tableName)
                        ->where('id', $row->id)
                        ->update(['uuid' => (string) Str::uuid()]);
                }
            });
    }

    private function dropUuid(string $tableName): void
    {
        if (! Schema::hasColumn($tableName, 'uuid')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            $table->dropUnique("{$tableName}_uuid_unique");
            $table->dropColumn('uuid');
        });
    }
};
