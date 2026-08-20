<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CDC GitHub §15/16: surgery.report.create/update/validate — the
     * "compte rendu" operatoire. §11's example "acte chirurgical validé"
     * (force_delete must be refused) has no permission to enforce it
     * against here: §15/16 lists no surgery.report.delete/restore/
     * force_delete. No SoftDeletable/deletion capability is added — see
     * the model's own doc comment and the surgical_requests migration for
     * the same flagged conflict.
     */
    public function up(): void
    {
        Schema::create('surgical_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('surgical_request_id')->unique()->constrained('surgical_requests')->restrictOnDelete();
            $table->foreignId('authored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('content');
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgical_reports');
    }
};
