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
        Schema::create('episode_orientations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('episode_id')->constrained('episodes')->restrictOnDelete();
            $table->string('source_module');
            $table->string('destination_module');
            $table->string('status')->default('PENDING');
            $table->string('active_key')->nullable()->unique();
            $table->text('reason')->nullable();
            $table->foreignId('oriented_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('oriented_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['destination_module', 'status', 'oriented_at'], 'episode_orientation_queue');
            $table->index(['episode_id', 'destination_module']);
        });

        // Legacy open passages predate explicit queues. They are brought
        // into the stricter workflow without pretending that an old generic
        // "ORIENTED" status proves a normal patient completed nursing care.
        DB::table('episodes')
            ->where('status', 'OPEN')
            ->orderBy('id')
            ->each(function (object $episode): void {
                $now = now();
                $careStatus = $episode->administrative_status === 'IN_CARE'
                    ? 'IN_PROGRESS'
                    : 'PENDING';

                DB::table('episode_orientations')->insert([
                    'uuid' => (string) Str::uuid(),
                    'episode_id' => $episode->id,
                    'source_module' => 'RECEPTION',
                    'destination_module' => 'CARE',
                    'status' => $careStatus,
                    'active_key' => $episode->id.':CARE',
                    'oriented_by' => $episode->created_by,
                    'oriented_at' => $episode->started_at,
                    'accepted_at' => $careStatus === 'IN_PROGRESS' ? $episode->started_at : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                if ($episode->priority !== 'EMERGENCY') {
                    return;
                }

                DB::table('episode_orientations')->insert([
                    'uuid' => (string) Str::uuid(),
                    'episode_id' => $episode->id,
                    'source_module' => 'RECEPTION',
                    'destination_module' => 'MEDICINE',
                    'status' => 'PENDING',
                    'active_key' => $episode->id.':MEDICINE',
                    'oriented_by' => $episode->created_by,
                    'oriented_at' => $episode->started_at,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('episode_orientations');
    }
};
