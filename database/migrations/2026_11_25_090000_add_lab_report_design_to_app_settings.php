<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-223 — l'aspect du compte rendu d'analyses (PDF), réglé par site depuis le
 * portail. Toutes les colonnes sont vides : un site que personne n'a réglé imprime
 * le compte rendu d'avant. `lab_report_logo_path` : un logo propre au compte rendu
 * (vide = le logo du site).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table): void {
            foreach (['lab_report_accent_color', 'lab_report_text_color', 'lab_report_section_background', 'lab_report_patient_background', 'lab_report_abnormal_color'] as $column) {
                $table->string($column, 7)->nullable();
            }

            $table->string('lab_report_heading', 80)->nullable();
            $table->string('lab_report_subheading', 120)->nullable();
            $table->string('lab_report_title', 80)->nullable();
            $table->string('lab_report_lab_signatory', 60)->nullable();
            $table->string('lab_report_physician_signatory', 60)->nullable();
            $table->string('lab_report_footer_text', 120)->nullable();
            $table->string('lab_report_website', 120)->nullable();

            $table->string('lab_report_template', 16)->nullable();
            $table->string('lab_report_font', 16)->nullable();
            $table->string('lab_report_signatory', 16)->nullable();
            $table->unsignedSmallInteger('lab_report_font_size')->nullable();

            foreach ([
                'lab_report_show_logo', 'lab_report_show_contacts', 'lab_report_show_legal', 'lab_report_show_anteriority',
                'lab_report_zebra', 'lab_report_show_sent', 'lab_report_show_approval', 'lab_report_show_generated',
                'lab_report_show_closing_identity', 'lab_report_show_footer', 'lab_report_show_footer_patient',
                'lab_report_show_page_numbers', 'lab_report_show_qr',
            ] as $column) {
                $table->boolean($column)->nullable();
            }

            $table->string('lab_report_logo_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'lab_report_accent_color', 'lab_report_text_color', 'lab_report_section_background', 'lab_report_patient_background',
                'lab_report_abnormal_color', 'lab_report_heading', 'lab_report_subheading', 'lab_report_title', 'lab_report_lab_signatory',
                'lab_report_physician_signatory', 'lab_report_footer_text', 'lab_report_website',
                'lab_report_template', 'lab_report_font', 'lab_report_signatory', 'lab_report_font_size',
                'lab_report_show_logo', 'lab_report_show_contacts', 'lab_report_show_legal', 'lab_report_show_anteriority',
                'lab_report_zebra', 'lab_report_show_sent', 'lab_report_show_approval', 'lab_report_show_generated',
                'lab_report_show_closing_identity', 'lab_report_show_footer', 'lab_report_show_footer_patient',
                'lab_report_show_page_numbers', 'lab_report_show_qr', 'lab_report_logo_path',
            ]);
        });
    }
};
