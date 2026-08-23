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
        Schema::create('visitor_visit_attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('visitor_visit_id')->constrained('visitor_visits')->restrictOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->timestamps();

            $table->index(['visitor_visit_id', 'created_at']);
        });

        DB::table('visitor_visits')
            ->whereNotNull('professional_image_path')
            ->orderBy('id')
            ->chunkById(100, function ($visits) {
                $now = now();
                $attachments = $visits->map(fn ($visit) => [
                    'uuid' => (string) Str::uuid(),
                    'visitor_visit_id' => $visit->id,
                    'path' => $visit->professional_image_path,
                    'original_name' => $visit->professional_image_original_name ?? basename($visit->professional_image_path),
                    'mime_type' => $visit->professional_image_mime_type ?? 'application/octet-stream',
                    'size' => $visit->professional_image_size ?? 0,
                    'created_at' => $visit->created_at ?? $now,
                    'updated_at' => $visit->updated_at ?? $now,
                ])->all();

                DB::table('visitor_visit_attachments')->insert($attachments);
            });

        Schema::table('visitor_visits', function (Blueprint $table) {
            $table->dropColumn([
                'professional_image_path',
                'professional_image_original_name',
                'professional_image_mime_type',
                'professional_image_size',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('visitor_visits', function (Blueprint $table) {
            $table->string('professional_image_path')->nullable()->after('organization');
            $table->string('professional_image_original_name')->nullable()->after('professional_image_path');
            $table->string('professional_image_mime_type', 100)->nullable()->after('professional_image_original_name');
            $table->unsignedBigInteger('professional_image_size')->nullable()->after('professional_image_mime_type');
        });

        DB::table('visitor_visit_attachments')
            ->orderBy('id')
            ->chunkById(100, function ($attachments) {
                foreach ($attachments as $attachment) {
                    DB::table('visitor_visits')
                        ->where('id', $attachment->visitor_visit_id)
                        ->whereNull('professional_image_path')
                        ->update([
                            'professional_image_path' => $attachment->path,
                            'professional_image_original_name' => $attachment->original_name,
                            'professional_image_mime_type' => $attachment->mime_type,
                            'professional_image_size' => $attachment->size,
                        ]);
                }
            });

        Schema::dropIfExists('visitor_visit_attachments');
    }
};
