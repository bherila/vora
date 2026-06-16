<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-type in-app notification preferences. Default on (opt-out), unlike the
 * e-mail follow prefs which are opt-in.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $columns = [
        'notify_new_post',
        'notify_post_reaction',
        'notify_post_comment',
        'notify_follow_accepted',
    ];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            foreach ($this->columns as $column) {
                $table->boolean($column)->default(true)->after('email_follow_request_accepted');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn($this->columns);
        });
    }
};
