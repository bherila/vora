<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $columns = [
        'notify_co_author_invite',
        'notify_co_author_invite_accepted',
    ];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('notify_co_author_invite')->default(true)->after('notify_follow_accepted');
            $table->boolean('notify_co_author_invite_accepted')->default(true)->after('notify_co_author_invite');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn($this->columns);
        });
    }
};
