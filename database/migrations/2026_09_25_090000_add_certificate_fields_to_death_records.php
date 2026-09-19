<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-107 (amendement) — l'acte suit le « Certificat médical de constatation
 * de décès » de la clinique.
 *
 * La feuille papier demande ce que le dossier ne porte pas encore — la
 * filiation, la délivrance de la CNI, le lieu de signature — et ce qu'il porte
 * mais qui doit être figé au jour de l'acte : lieu de naissance, adresse,
 * numéro de CNI. Ces valeurs vivent sur l'acte, jamais sur le dossier patient :
 * un certificat signé ne change pas quand le dossier est corrigé ensuite.
 *
 * Toutes nullables : les actes déjà établis restent tels qu'ils ont été
 * signés, rien n'est rétro-rempli.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('death_records', function (Blueprint $table) {
            $table->string('birth_place', 255)->nullable()->after('medical_discharge_id');
            $table->string('address', 500)->nullable()->after('birth_place');
            $table->string('father_name', 255)->nullable()->after('address');
            $table->string('mother_name', 255)->nullable()->after('father_name');
            $table->string('identity_document_number', 100)->nullable()->after('mother_name');
            $table->date('identity_document_issued_on')->nullable()->after('identity_document_number');
            $table->string('identity_document_issued_place', 255)->nullable()->after('identity_document_issued_on');
            $table->string('signed_place', 255)->nullable()->after('observations');
        });
    }

    public function down(): void
    {
        Schema::table('death_records', function (Blueprint $table) {
            $table->dropColumn([
                'birth_place', 'address', 'father_name', 'mother_name',
                'identity_document_number', 'identity_document_issued_on',
                'identity_document_issued_place', 'signed_place',
            ]);
        });
    }
};
