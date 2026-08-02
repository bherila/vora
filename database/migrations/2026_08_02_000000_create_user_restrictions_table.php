<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historical, per-capability account restrictions. Subject deletion cascades
 * because the sanction has no meaning without the account; deleting an admin
 * only nulls their actor references so the restriction history survives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_restrictions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('capability', 40);
            $table->foreignId('restricted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('lifted_at')->nullable();
            $table->foreignId('lifted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'capability']);
            $table->index(['user_id', 'lifted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_restrictions');
    }
};
