<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->string('reviewed_object_key')->nullable()->after('object_key');
            $table->string('reviewed_thumbnail_key')->nullable()->after('thumbnail_key');
            $table->string('multipart_upload_id')->nullable()->after('upload_status');
            $table->unsignedBigInteger('multipart_part_size_bytes')->nullable()->after('multipart_upload_id');
            $table->unsignedBigInteger('multipart_expected_size_bytes')->nullable()->after('multipart_part_size_bytes');
            $table->unsignedInteger('multipart_max_part_number')->nullable()->after('multipart_expected_size_bytes');
            $table->timestamp('multipart_initiated_at')->nullable()->after('multipart_max_part_number');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropColumn([
                'reviewed_object_key',
                'reviewed_thumbnail_key',
                'multipart_upload_id',
                'multipart_part_size_bytes',
                'multipart_expected_size_bytes',
                'multipart_max_part_number',
                'multipart_initiated_at',
            ]);
        });
    }
};
