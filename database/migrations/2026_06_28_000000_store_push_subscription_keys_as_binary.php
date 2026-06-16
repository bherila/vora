<?php

use App\Support\WebPushKeyMaterial;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = (string) config('webpush.table_name');

        Schema::table($tableName, function (Blueprint $table): void {
            $table->binary('public_key_bytes')->nullable()->after('endpoint');
            $table->binary('auth_token_bytes')->nullable()->after('public_key_bytes');
        });

        DB::table($tableName)
            ->select(['id', 'public_key', 'auth_token'])
            ->orderBy('id')
            ->each(function (object $row) use ($tableName): void {
                DB::table($tableName)
                    ->where('id', $row->id)
                    ->update([
                        'public_key_bytes' => WebPushKeyMaterial::base64UrlToBinary($row->public_key),
                        'auth_token_bytes' => WebPushKeyMaterial::base64UrlToBinary($row->auth_token),
                    ]);
            });

        Schema::table($tableName, function (Blueprint $table): void {
            $table->dropColumn(['public_key', 'auth_token']);
        });
    }

    public function down(): void
    {
        $tableName = (string) config('webpush.table_name');

        Schema::table($tableName, function (Blueprint $table): void {
            $table->string('public_key')->nullable()->after('endpoint');
            $table->string('auth_token')->nullable()->after('public_key');
        });

        DB::table($tableName)
            ->select(['id', 'public_key_bytes', 'auth_token_bytes'])
            ->orderBy('id')
            ->each(function (object $row) use ($tableName): void {
                DB::table($tableName)
                    ->where('id', $row->id)
                    ->update([
                        'public_key' => WebPushKeyMaterial::binaryToBase64Url($row->public_key_bytes),
                        'auth_token' => WebPushKeyMaterial::binaryToBase64Url($row->auth_token_bytes),
                    ]);
            });

        Schema::table($tableName, function (Blueprint $table): void {
            $table->dropColumn(['public_key_bytes', 'auth_token_bytes']);
        });
    }
};
