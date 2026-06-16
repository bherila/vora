<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('notify_follow_request')->default(true)->after('notify_post_comment');
            $table->dropColumn(['email_follow_request_received', 'email_follow_request_accepted']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('email_follow_request_received')->default(false)->after('preferred_genders');
            $table->boolean('email_follow_request_accepted')->default(false)->after('email_follow_request_received');
            $table->dropColumn('notify_follow_request');
        });
    }
};
