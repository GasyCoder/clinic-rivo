<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-216, amendement du 2026-09-29 (ter) — les résultats d'une demande
 * s'adressent à un, plusieurs ou tous les médecins proposés. Une ligne par
 * destinataire ; `lab_requests.results_recipient_id` garde le premier, pour les
 * lectures qui n'en attendent qu'un. Les destinataires déjà enregistrés sont
 * repris, rien n'est deviné.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_request_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_request_id');
            $table->foreignId('user_id');
            $table->timestamp('addressed_at')->nullable();
            $table->foreignId('addressed_by')->nullable();
            $table->timestamps();

            $table->unique(['lab_request_id', 'user_id'], 'lab_req_recipients_unique');
            $table->index('user_id', 'lab_req_recipients_user_idx');
            $table->foreign('lab_request_id', 'lab_req_recipients_request_fk')->references('id')->on('lab_requests')->cascadeOnDelete();
            $table->foreign('user_id', 'lab_req_recipients_user_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('addressed_by', 'lab_req_recipients_by_fk')->references('id')->on('users')->nullOnDelete();
        });

        DB::table('lab_requests')->whereNotNull('results_recipient_id')->orderBy('id')
            ->select(['id', 'results_recipient_id', 'results_addressed_at', 'results_addressed_by'])
            ->each(fn ($row) => DB::table('lab_request_recipients')->insertOrIgnore([
                'lab_request_id' => $row->id,
                'user_id' => $row->results_recipient_id,
                'addressed_at' => $row->results_addressed_at,
                'addressed_by' => $row->results_addressed_by,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_request_recipients');
    }
};
