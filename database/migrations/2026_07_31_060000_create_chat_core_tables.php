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
        Schema::table('users', function (Blueprint $table): void {
            $table->ulid('public_ulid')->nullable()->after('id');
            $table->unsignedBigInteger('chat_sync_version')->default(0)->after('public_ulid');
        });

        DB::table('users')
            ->whereNull('public_ulid')
            ->orderBy('id')
            ->eachById(function (object $user): void {
                DB::table('users')
                    ->where('id', $user->id)
                    ->update(['public_ulid' => (string) Str::ulid()]);
            });

        Schema::table('users', function (Blueprint $table): void {
            $table->unique('public_ulid', 'users_public_ulid_unique');
        });

        Schema::create('chat_conversations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('lower_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('higher_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['lower_user_id', 'higher_user_id'], 'chat_conversations_pair_unique');
            $table->index(['last_message_at', 'id'], 'chat_conversations_activity_index');
        });

        Schema::create('chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
            $table->ulid('client_message_id');
            $table->text('body');
            $table->timestamps();

            $table->unique(['sender_user_id', 'client_message_id'], 'chat_messages_sender_client_unique');
            $table->index(['conversation_id', 'created_at', 'id'], 'chat_messages_cursor_index');
        });

        Schema::create('chat_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('last_read_message_id')->nullable()
                ->constrained('chat_messages')->nullOnDelete();
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id'], 'chat_participants_conversation_user_unique');
            $table->index(['user_id', 'last_activity_at', 'id'], 'chat_participants_inbox_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_participants');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_conversations');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_public_ulid_unique');
            $table->dropColumn(['public_ulid', 'chat_sync_version']);
        });
    }
};
