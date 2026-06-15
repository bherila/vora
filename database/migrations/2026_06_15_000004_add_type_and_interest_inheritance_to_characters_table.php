<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->string('user_type', 20)->nullable()->after('gender_other');
            $table->string('user_type_other', 100)->nullable()->after('user_type');
            // When true (the default), the character has no interest overrides of
            // its own and falls back to the owning user's profile interests.
            $table->boolean('inherit_interests')->default(true)->after('preferred_genders');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->dropColumn(['user_type', 'user_type_other', 'inherit_interests']);
        });
    }
};
