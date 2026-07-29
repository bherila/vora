<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RecentProfileVisitMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function migration_resumes_after_the_table_was_created_without_its_indexes(): void
    {
        Schema::drop('recent_profile_visits');
        Schema::create('recent_profile_visits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('viewer_user_id');
            $table->string('target_type', 20);
            $table->unsignedBigInteger('target_id');
            $table->timestamp('visited_at', 6);
            $table->timestamps();
        });

        $migration = require __DIR__.'/../../database/migrations/'.
            '2026_07_29_040000_create_recent_profile_visits_table.php';
        $migration->up();

        $this->assertTrue(Schema::hasIndex(
            'recent_profile_visits',
            'recent_profile_visits_target_unique',
        ));
        $this->assertTrue(Schema::hasIndex(
            'recent_profile_visits',
            'recent_profile_visits_recent_index',
        ));

        // A retry after both indexes exist is a no-op rather than a duplicate
        // index failure.
        $migration->up();
    }
}
