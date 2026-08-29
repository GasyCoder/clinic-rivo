<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            // Explicit, defaulted false: prescribability by a clinician is
            // never deduced from a Service/CARE item's name or code, and the
            // Reception-facing catalogue (reception_selectable/routing_mode)
            // is a separate concern from what a doctor may order in
            // consultation.
            $table->boolean('clinician_orderable')
                ->default(false)
                ->after('care_recommends_vitals');
        });

        Schema::create('care_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('episode_id')->constrained('episodes')->restrictOnDelete();
            $table->foreignId('consultation_id')->constrained('consultations')->restrictOnDelete();
            // The Médecine orientation the order was issued from, and the
            // Soins orientation created/reused to carry it out — kept apart
            // from Consultation/EpisodeOrientation themselves so completing
            // this order never has to alter either of those two models.
            $table->foreignId('source_orientation_id')->constrained('episode_orientations')->restrictOnDelete();
            $table->foreignId('care_orientation_id')->constrained('episode_orientations')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->text('instructions')->nullable();
            $table->boolean('requires_return_to_medicine');
            $table->string('status')->default('PENDING');
            $table->timestamp('ordered_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['care_orientation_id', 'status']);
        });

        Schema::create('care_order_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('care_order_id')->constrained('care_orders')->restrictOnDelete();
            $table->foreignId('catalog_item_id')->constrained('catalog_items')->restrictOnDelete();
            // Snapshot: a later catalogue edit must never rewrite what the
            // doctor actually ordered, mirroring EpisodeServiceRequest's own
            // designation/catalog_code snapshot convention.
            $table->string('catalog_item_code_snapshot', 60);
            $table->string('catalog_item_name_snapshot');
            $table->decimal('quantity', 12, 2)->default(1);
            $table->text('instructions')->nullable();
            // Explicit decline, distinct from "not yet done": realized
            // quantity is derived from linked CareRecordProcedure rows, so
            // an item can only be either partially/fully realized or marked
            // not performed — never both silently.
            $table->timestamp('not_performed_at')->nullable();
            $table->text('not_performed_reason')->nullable();
            $table->foreignId('not_performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('care_record_procedures', function (Blueprint $table) {
            // Nullable: a free/complementary act performed on the spot has
            // no CareOrderItem to point to. When set, it is the explicit,
            // backend-trustworthy link this domain uses to compute realized
            // vs remaining quantity — never a match by name/code.
            $table->foreignId('care_order_item_id')
                ->nullable()
                ->after('catalog_item_uuid')
                ->constrained('care_order_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('care_record_procedures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('care_order_item_id');
        });

        Schema::dropIfExists('care_order_items');
        Schema::dropIfExists('care_orders');

        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropColumn('clinician_orderable');
        });
    }
};
