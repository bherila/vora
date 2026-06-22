<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Abuse reports filed by viewers. Polymorphic so one queue can hold media,
        // stories, and posts. Reviewed by admins, who can act on the item or the
        // owning account. Visibility was enforced when the report was filed — a
        // report never exposes content the reporter could not already reach.
        Schema::create('reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reporter_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reportable_type');
            $table->unsignedBigInteger('reportable_id');
            $table->string('reason');
            $table->text('details')->nullable();
            $table->string('status')->default('open');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            // Free-text record of what the reviewing admin did (the action taken).
            $table->string('resolution')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['reportable_type', 'reportable_id']);
            // One open report per reporter per item: re-reporting an item you have
            // an open report on is a no-op rather than a duplicate row.
            $table->unique(['reporter_user_id', 'reportable_type', 'reportable_id', 'status'], 'reports_reporter_target_status_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
