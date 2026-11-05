<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('local_identity_migrations')) {
            return;
        }

        Schema::create('local_identity_migrations', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 64);
            $table->string('subject', 255);
            $table->string('client_id', 255);
            $table->string('target_fingerprint', 64);
            $table->string('source_fingerprint', 64);
            $table->unsignedInteger('passkeys_copied');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Retain the ownership proof while provider accounts and bindings exist.
    }
};
