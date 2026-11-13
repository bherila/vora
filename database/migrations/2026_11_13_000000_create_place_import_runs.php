<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per GeoNames import (see `PlaceImportRuns`), replacing the single
 * settings row that served as queue, lock and status. Each state change is
 * a conditional update on the row, so queueing, claiming and cancelling are
 * safe against one another.
 *
 * Also widens region ids from SMALLINT to INT: Replace imports that drop and
 * later re-add regions spend new AUTO_INCREMENT values, and the 65,535 limit
 * is a lifetime budget, not a live count.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mysql = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

        Schema::create('place_import_runs', function (Blueprint $table) use ($mysql): void {
            $id = $table->char('id', 36)->primary();
            if ($mysql) {
                $id->charset('ascii')->collation('ascii_bin');
            }
            // pending, running, succeeded, failed, cancelled, superseded, interrupted
            $table->string('state', 12);
            // admin, command, schedule
            $table->string('source', 12);
            $table->string('dataset', 16);
            $table->string('mode', 8);
            $table->boolean('rehome')->default(false);
            $table->boolean('dry_run')->default(false);
            // The run whose directory holds the input files: this run for an
            // upload or a downloaded preview, the preview when applying one.
            $files = $table->char('files_run_id', 36)->nullable();
            $preview = $table->char('preview_of', 36)->nullable();
            if ($mysql) {
                $files->charset('ascii')->collation('ascii_bin');
                $preview->charset('ascii')->collation('ascii_bin');
            }
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->text('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('requested_at', 6)->nullable();
            $table->timestamp('started_at', 6)->nullable();
            $table->timestamp('heartbeat_at', 6)->nullable();
            $table->timestamp('finished_at', 6)->nullable();
            // Microsecond timestamps order runs that finish within one second.
            $table->index(['state', 'requested_at']);
        });

        // SQLite already stores these as 64-bit integers.
        if ($mysql) {
            DB::statement('ALTER TABLE place_regions MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT');
            DB::statement('ALTER TABLE places MODIFY region_id INT UNSIGNED NULL');
        }

        // The settings-row queue and status this table replaces.
        DB::table('settings')->whereIn('key', ['places_import_request', 'places_import_status'])->delete();
    }

    public function down(): void
    {
        Schema::dropIfExists('place_import_runs');
    }
};
