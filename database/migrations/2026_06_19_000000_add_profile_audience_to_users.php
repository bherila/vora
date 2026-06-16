<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who may view a user's profile, reusing the shared Audience vocabulary. The
 * "specific people" tier reuses the polymorphic audience_members allowlist with
 * the User as the privacyable. There is no `discoverable` axis for profiles — a
 * profile always stays findable enough to receive a follow request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('profile_audience', 20)->default('everyone')->after('user_type_other');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('profile_audience');
        });
    }
};
