<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Free label, not an enum: a new document category (e.g. a
            // decision letter type nobody anticipated) must never require a
            // deploy. document_data_context below stays a real enum because
            // it governs which resolver code actually runs.
            $table->string('document_type', 80);
            $table->string('data_context', 40);
            $table->string('name');
            $table->text('description')->nullable();
            // `content`: TipTap JSON document, reloaded into the editor for
            // further editing. `content_html`: that same document serialized
            // to HTML at save time (editor.getHTML()) — the only form the
            // server ever reads, for placeholder extraction and for
            // generation (no server-side ProseMirror dependency needed).
            $table->json('content');
            $table->longText('content_html');
            $table->json('variables_used')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('external_created_by_uuid')->nullable()->index();
            $table->string('external_created_by_name', 150)->nullable();
            $table->uuid('external_updated_by_uuid')->nullable()->index();
            $table->string('external_updated_by_name', 150)->nullable();
            $table->timestamps();
            $table->softDeletesWithReason();

            $table->index(['document_type', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_templates');
    }
};
