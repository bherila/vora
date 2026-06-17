<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Foundation for the unified privacy/audience model. Replaces the binary
 * `visibility` column on content with two orthogonal axes — `audience` (who may
 * access) and `discoverable` (listed vs. link-only) — adds the "specific people"
 * allowlist, and an append-only privacy audit trail.
 */
return new class extends Migration
{
    /**
     * Content tables that carry a privacy policy today.
     *
     * @var list<string>
     */
    private array $contentTables = ['media', 'stories'];

    public function up(): void
    {
        // Per-item "specific people" allowlist. Polymorphic so one table serves
        // every content type. A grant is removed when the granted user is
        // deleted (cascade) so an erased account never lingers on an allowlist.
        Schema::create('audience_members', function (Blueprint $table): void {
            $table->id();
            $table->morphs('privacyable');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['privacyable_type', 'privacyable_id', 'user_id'], 'audience_members_unique');
        });

        // Append-only audit trail of privacy changes. The actor is null-on-delete
        // so erasing a user drops the PII linkage while the record is retained.
        Schema::create('privacy_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->morphs('privacyable');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 20);
            $table->string('old_audience', 20)->nullable();
            $table->string('new_audience', 20);
            $table->boolean('old_discoverable')->nullable();
            $table->boolean('new_discoverable');
            $table->json('added_user_ids')->nullable();
            $table->json('removed_user_ids')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 1024)->nullable();
            $table->timestamps();
        });

        foreach ($this->contentTables as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('audience', 20)->default('everyone')->after('visibility');
                $table->boolean('discoverable')->default(true)->after('audience');
                $table->index(['audience', 'discoverable']);
            });

            // Backfill: every old value maps to the Everyone audience; only the
            // old "unlisted" value was hidden from discovery (link-only).
            DB::table($name)->where('visibility', 'unlisted')->update(['discoverable' => false]);
        }

        // The stories table indexed (visibility, status); drop it before the
        // column so SQLite can rebuild the table cleanly.
        Schema::table('stories', function (Blueprint $table): void {
            $table->dropIndex(['visibility', 'status']);
        });

        foreach ($this->contentTables as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('visibility');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->contentTables as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('visibility')->default('users')->after('id');
            });

            // The old binary model cannot express Followers/Mutuals/Specific.
            // Fail safe: anything not fully public (restricted audience OR not
            // discoverable) rolls back to link-only "unlisted" rather than
            // becoming public/listed under the legacy policy.
            DB::table($name)
                ->where(function ($query): void {
                    $query->where('discoverable', false)
                        ->orWhere('audience', '!=', 'everyone');
                })
                ->update(['visibility' => 'unlisted']);

            Schema::table($name, function (Blueprint $table): void {
                $table->dropIndex(['audience', 'discoverable']);
                $table->dropColumn(['audience', 'discoverable']);
            });
        }

        // Restore the stories index that paired visibility with status.
        Schema::table('stories', function (Blueprint $table): void {
            $table->index(['visibility', 'status']);
        });

        Schema::dropIfExists('privacy_audit_logs');
        Schema::dropIfExists('audience_members');
    }
};
