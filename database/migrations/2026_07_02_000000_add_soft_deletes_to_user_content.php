<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->softDeletes();
            $table->index('deleted_at');
        });

        Schema::table('stories', function (Blueprint $table): void {
            $table->softDeletes();
            $table->index('deleted_at');
        });

        Schema::table('characters', function (Blueprint $table): void {
            $table->softDeletes();
            $table->index('deleted_at');
        });

        Schema::table('posts', function (Blueprint $table): void {
            $table->softDeletes();
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->dropIndex(['deleted_at']);
            $table->dropSoftDeletes();
        });

        Schema::table('characters', function (Blueprint $table): void {
            $table->dropIndex(['deleted_at']);
            $table->dropSoftDeletes();
        });

        Schema::table('stories', function (Blueprint $table): void {
            $table->dropIndex(['deleted_at']);
            $table->dropSoftDeletes();
        });

        Schema::table('media', function (Blueprint $table): void {
            $table->dropIndex(['deleted_at']);
            $table->dropSoftDeletes();
        });
    }
};
