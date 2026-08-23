<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_visits', function (Blueprint $table) {
            $table->string('professional_image_path')->nullable()->after('organization');
            $table->string('professional_image_original_name')->nullable()->after('professional_image_path');
            $table->string('professional_image_mime_type', 100)->nullable()->after('professional_image_original_name');
            $table->unsignedBigInteger('professional_image_size')->nullable()->after('professional_image_mime_type');
        });
    }

    public function down(): void
    {
        Schema::table('visitor_visits', function (Blueprint $table) {
            $table->dropColumn([
                'professional_image_path',
                'professional_image_original_name',
                'professional_image_mime_type',
                'professional_image_size',
            ]);
        });
    }
};
