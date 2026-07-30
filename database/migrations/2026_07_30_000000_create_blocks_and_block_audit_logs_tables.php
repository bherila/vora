<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('blocker_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blocked_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blocked_character_id')
                ->nullable()
                ->constrained('characters')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('blocked_scope_id')
                ->virtualAs('COALESCE(blocked_character_id, 0)');
            $table->timestamps();

            $table->unique(
                ['blocker_id', 'blocked_user_id', 'blocked_scope_id'],
                'blocks_unique_identity',
            );
            $table->index(
                ['blocked_user_id', 'blocker_id'],
                'blocks_denial_lookup',
            );
        });

        Schema::create('block_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('block_id')->nullable()->constrained('blocks')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('blocker_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('blocked_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('blocked_character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->string('action', 30);
            $table->timestamps();

            $table->index(['blocker_id', 'blocked_user_id'], 'block_audit_parties');
            $table->index(['blocked_user_id', 'action'], 'block_audit_target_action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('block_audit_logs');
        Schema::dropIfExists('blocks');
    }
};
