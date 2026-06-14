<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('user_type', 20)->nullable()->after('gender_other');
            $table->string('user_type_other', 100)->nullable()->after('user_type');
            $table->json('preferred_user_types')->nullable()->after('user_type_other');
            $table->json('preferred_genders')->nullable()->after('preferred_user_types');
        });

        DB::table('users')->where('gender', 'm')->update(['gender' => 'male']);
        DB::table('users')->where('gender', 'f')->update(['gender' => 'female']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('users')->where('gender', 'male')->update(['gender' => 'm']);
        DB::table('users')->where('gender', 'female')->update(['gender' => 'f']);

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'user_type',
                'user_type_other',
                'preferred_user_types',
                'preferred_genders',
            ]);
        });
    }
};
