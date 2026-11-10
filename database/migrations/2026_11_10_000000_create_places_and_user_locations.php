<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\Schema;

/**
 * A compact local copy of the GeoNames city gazetteer (loaded by
 * `places:import`, never shipped with releases).
 *
 * Storage choices, aimed at MariaDB/MySQL:
 * - ids, codes and search keys are ASCII with binary collation: one byte per
 *   character, and exact byte comparison for the indexed prefix search (keys
 *   are lower-cased by the importer, so case-insensitivity is not needed);
 * - display names are utf8mb4 with a Unicode collation, since GeoNames names
 *   carry diacritics and non-Latin scripts;
 * - coordinates are integers in units of 1/10,000 degree (about 11 m) in
 *   3-byte MEDIUMINT columns rather than 8-byte doubles;
 * - country and region names live once in small lookup tables, referenced by
 *   a 2-byte code / 2-byte id from each city row.
 *
 * Charset and collation are applied only on MySQL/MariaDB; SQLite stores the
 * same values in its own dynamic types.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mysql = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
        $ascii = static fn (ColumnDefinition $column): ColumnDefinition => $mysql
            ? $column->charset('ascii')->collation('ascii_bin')
            : $column;
        $text = static fn (ColumnDefinition $column): ColumnDefinition => $mysql
            ? $column->charset('utf8mb4')->collation('utf8mb4_unicode_520_ci')
            : $column;

        Schema::create('place_countries', function (Blueprint $table) use ($ascii, $text): void {
            $ascii($table->char('code', 2))->primary();
            $text($table->string('name', 100));
            $ascii($table->string('search_name', 100));
        });

        Schema::create('place_regions', function (Blueprint $table) use ($ascii, $text): void {
            $table->smallIncrements('id');
            $ascii($table->char('country_code', 2));
            $ascii($table->string('code', 20));
            $text($table->string('name', 200));
            $ascii($table->string('search_name', 200));
            $table->unique(['country_code', 'code']);
        });

        Schema::create('places', function (Blueprint $table) use ($ascii, $text): void {
            // The GeoNames id, so reimports upsert in place and a user's chosen
            // place survives them.
            $table->unsignedInteger('id')->primary();
            $text($table->string('name', 200));
            $ascii($table->string('search_name', 200))->index();
            $table->unsignedSmallInteger('region_id')->nullable();
            $ascii($table->char('country_code', 2));
            $table->mediumInteger('lat_e4')->index();
            $table->mediumInteger('lng_e4');
            $table->unsignedInteger('population')->default(0);
            // Unix time of the `places:import` run that last saw this row; rows
            // an import no longer contains are pruned unless someone uses them.
            $table->unsignedInteger('import_batch')->default(0);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('place_id')->nullable()->index();
            $table->foreign('place_id')->references('id')->on('places')->nullOnDelete();
            $table->string('location_country_audience', 16)->default('nobody');
            $table->string('location_city_audience', 16)->default('nobody');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['place_id']);
            $table->dropIndex(['place_id']);
            $table->dropColumn(['place_id', 'location_country_audience', 'location_city_audience']);
        });

        Schema::dropIfExists('places');
        Schema::dropIfExists('place_regions');
        Schema::dropIfExists('place_countries');
    }
};
