<?php

use App\Models\Interest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_attachments', function (Blueprint $table): void {
            $table->unsignedSmallInteger('position')->default(0);
        });

        $positions = [];
        DB::table('post_attachments')->orderBy('post_id')->orderBy('id')->get(['id', 'post_id'])->each(
            function (object $row) use (&$positions): void {
                $position = $positions[$row->post_id] ?? 0;
                DB::table('post_attachments')->where('id', $row->id)->update(['position' => $position]);
                $positions[$row->post_id] = $position + 1;
            },
        );

        DB::table('post_attachments')
            ->where('attachable_type', (new Interest)->getMorphClass())
            ->delete();
    }

    public function down(): void
    {
        Schema::table('post_attachments', fn (Blueprint $table) => $table->dropColumn('position'));
    }
};
