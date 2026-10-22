<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('library_enabled')->default(false);
        });

        // The media row also anchors shared stored bytes. Account erasure must
        // not cascade-delete files retained by another account's Library.
        Schema::table('media', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('library_collections', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('related_character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->boolean('related_persona_linked')->default(false);
            $table->timestamps();
        });

        Schema::create('library_items', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->nullable()->constrained('media')->cascadeOnDelete();
            $table->string('source');
            $table->string('title')->nullable();
            $table->mediumText('body')->nullable();
            $table->ulid('source_item_ulid')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'media_id']);
            $table->unique(['user_id', 'source_item_ulid']);
        });

        Schema::create('library_collection_items', function (Blueprint $table): void {
            $table->foreignId('library_collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('library_item_id')->constrained()->cascadeOnDelete();
            $table->primary(['library_collection_id', 'library_item_id']);
            $table->index('library_item_id');
        });

        foreach (['library_collection_members' => 'library_collection_id', 'library_item_members' => 'library_item_id'] as $name => $foreign) {
            Schema::create($name, function (Blueprint $table) use ($foreign): void {
                $table->foreignId($foreign)->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->primary([$foreign, 'user_id']);
                $table->index('user_id');
            });
        }
    }

    public function down(): void
    {
        if (DB::table('media')->whereNull('user_id')->exists()) {
            throw new RuntimeException('Retained Library files have no uploader. Migrate forward instead of discarding them.');
        }

        Schema::dropIfExists('library_item_members');
        Schema::dropIfExists('library_collection_members');
        Schema::dropIfExists('library_collection_items');
        Schema::dropIfExists('library_items');
        Schema::dropIfExists('library_collections');
        Schema::table('media', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('library_enabled');
        });
    }
};
