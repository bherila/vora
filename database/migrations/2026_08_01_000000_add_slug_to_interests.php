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
        Schema::table('interests', function (Blueprint $table): void {
            $table->string('slug')->nullable()->after('name');
        });

        $used = [];
        DB::table('interests')->orderBy('id')->get(['id', 'name'])->each(function (object $interest) use (&$used): void {
            $base = Str::slug((string) $interest->name) ?: 'interest';
            $base = rtrim(Str::limit($base, 255, ''), '-_') ?: 'interest';
            $slug = $base;
            $number = 2;
            while (isset($used[$slug])) {
                $suffix = '-'.$number++;
                $stem = rtrim(Str::limit($base, 255 - strlen($suffix), ''), '-_');
                $slug = ($stem === '' ? 'interest' : $stem).$suffix;
            }
            $used[$slug] = true;
            DB::table('interests')->where('id', $interest->id)->update(['slug' => $slug]);
        });

        Schema::table('interests', function (Blueprint $table): void {
            $table->string('slug')->nullable(false)->change();
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('interests', function (Blueprint $table): void {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
