<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follow_requests', function (Blueprint $table): void {
            $table->dropUnique(['requester_id', 'recipient_id']);
            $table->foreignId('recipient_character_id')
                ->nullable()
                ->after('recipient_id')
                ->constrained('characters')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('recipient_scope_id')
                ->virtualAs('COALESCE(recipient_character_id, 0)');

            $table->unique(
                ['requester_id', 'recipient_id', 'recipient_scope_id'],
                'follow_requests_unique_recipient_scope',
            );
            $table->index(
                ['recipient_character_id', 'status'],
                'follow_requests_character_status_index',
            );
        });
    }

    public function down(): void
    {
        // Account-only schema cannot represent persona-scoped edges. Discard
        // them deliberately before restoring its pairwise unique constraint.
        DB::table('follow_requests')->whereNotNull('recipient_character_id')->delete();

        Schema::table('follow_requests', function (Blueprint $table): void {
            $table->dropUnique('follow_requests_unique_recipient_scope');
            $table->dropIndex('follow_requests_character_status_index');
            $table->dropColumn('recipient_scope_id');
            $table->dropConstrainedForeignId('recipient_character_id');
            $table->unique(['requester_id', 'recipient_id']);
        });
    }
};
