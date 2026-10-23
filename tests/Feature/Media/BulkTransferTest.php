<?php

namespace Tests\Feature\Media;

use App\Enums\Audience;
use App\Enums\MediaPurpose;
use App\Enums\RestrictionCapability;
use App\Http\Controllers\BulkTransferController;
use App\Models\Event;
use App\Models\Media;
use App\Models\User;
use App\Models\UserRestriction;
use App\Services\FileStorageService;
use App\Services\Media\BulkDownloadArchive;
use App\Services\SettingsService;
use App\Services\UserAccountService;
use Aws\CommandInterface;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\StreamWrapper;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BulkTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['instance.modules.events' => true, 'instance.modules.library' => true]);
        Notification::fake();
        User::factory()->admin()->create(['id' => 100]);
        foreach (['photos', 's3', 'hls'] as $disk) {
            Storage::fake($disk);
        }
    }

    private function account(array $attributes = []): User
    {
        return User::factory()->approved()->create(['library_enabled' => true, ...$attributes]);
    }

    private function event(array $members = []): Event
    {
        $event = Event::create(['name' => 'Shared moments', 'visibility' => $members === [] ? 'everyone' : 'specific', 'timezone' => 'UTC']);
        $event->members()->attach($members);

        return $event;
    }

    private function media(User $owner, Event $event, string $mime = 'image/jpeg'): Media
    {
        $video = str_starts_with($mime, 'video/');
        $media = ($video ? Media::factory()->video() : Media::factory())->for($owner)->approved()->create([
            'purpose' => MediaPurpose::Event, 'event_id' => $event->id, 'audience' => Audience::Everyone,
            'mime_type' => $mime, 'original_filename' => 'private-person@example.invalid.'.($video ? 'mp4' : 'jpg'),
            'size_bytes' => 8, 'reviewed_object_key' => 'reviewed/'.uniqid('', true),
        ]);
        Storage::disk($media->disk)->put($media->object_key, 'mutable!');
        Storage::disk($media->disk)->put($media->reviewed_object_key, 'reviewed');

        return $media;
    }

    private function prepare(User $viewer, array $ulids, string $source = 'events'): string
    {
        $response = $this->withCredentials()->actingAs($viewer)->postJson('/api/'.($source === 'events' ? 'events/media' : 'library/items').'/bulk-downloads', ['ulids' => $ulids])->assertOk();
        $this->withCookie(config('session.cookie'), $this->app['session']->getId());

        return $response->json('data.download_url');
    }

    /** Read STORE entries through their ZIP central directory, without ext-zip. @return array<string, string> */
    private function entries(string $zip): array
    {
        $end = strrpos($zip, "PK\x05\x06");
        $this->assertNotFalse($end, 'A completed ZIP needs its central-directory terminator.');
        $offset = unpack('V', substr($zip, $end + 16, 4))[1];
        $entries = [];
        while (substr($zip, $offset, 4) === "PK\x01\x02") {
            $nameLength = unpack('v', substr($zip, $offset + 28, 2))[1];
            $extraLength = unpack('v', substr($zip, $offset + 30, 2))[1];
            $commentLength = unpack('v', substr($zip, $offset + 32, 2))[1];
            $size = unpack('V', substr($zip, $offset + 20, 4))[1];
            $local = unpack('V', substr($zip, $offset + 42, 4))[1];
            $name = substr($zip, $offset + 46, $nameLength);
            $dataStart = $local + 30 + unpack('v', substr($zip, $local + 26, 2))[1] + unpack('v', substr($zip, $local + 28, 2))[1];
            $entries[$name] = substr($zip, $dataStart, $size);
            $offset += 46 + $nameLength + $extraLength + $commentLength;
        }

        return $entries;
    }

    public function test_bulk_save_checks_every_event_acl_before_any_reference_is_created(): void
    {
        $owner = $this->account();
        $viewer = $this->account();
        $public = $this->media($owner, $this->event());
        $private = $this->media($owner, $this->event([$owner->id]));
        $this->actingAs($viewer)->postJson('/api/events/media/bulk-save', ['ulids' => [$public->ulid, $private->ulid]])->assertNotFound();
        $this->assertSame(0, $viewer->libraryItems()->count());
    }

    public function test_bulk_save_rolls_back_quota_failure_is_idempotent_and_survives_uploader_purge(): void
    {
        $owner = $this->account();
        $viewer = $this->account(['storage_limit_bytes' => 15]);
        $event = $this->event();
        $first = $this->media($owner, $event);
        $last = $this->media($owner, $event, 'video/mp4');
        $payload = ['ulids' => [$first->ulid, $last->ulid]];
        $this->actingAs($viewer)->postJson('/api/events/media/bulk-save', $payload)->assertUnprocessable();
        $this->assertSame(0, $viewer->libraryItems()->count());
        $viewer->forceFill(['storage_limit_bytes' => 16])->save();
        $this->postJson('/api/events/media/bulk-save', $payload)->assertOk()->assertJsonPath('data.saved_count', 2);
        $this->postJson('/api/events/media/bulk-save', $payload)->assertOk();
        $this->assertSame(2, $viewer->libraryItems()->count());
        $this->assertCount(2, Storage::disk('photos')->allFiles());
        app(UserAccountService::class)->purge($owner);
        $saved = $viewer->libraryItems()->pluck('ulid')->all();
        $this->get('/api/library/items/'.$saved[0].'/download')->assertOk()->assertStreamedContent('reviewed');
        $url = $this->prepare($viewer, $saved, 'library');
        $zip = $this->get($url)->assertOk()->streamedContent();
        $this->assertCount(2, $this->entries($zip));
        $this->assertStringNotContainsString('private-person@example.invalid', $zip);
        Storage::disk('photos')->assertExists($first->reviewed_object_key);
        Storage::disk('s3')->assertExists($last->reviewed_object_key);
    }

    public function test_shared_library_notes_and_files_save_privately_but_saved_items_cannot_be_reshared(): void
    {
        $owner = $this->account();
        $viewer = $this->account();
        $outsider = $this->account();
        $media = $this->media($owner, $this->event());
        $file = $owner->libraryItems()->create(['source' => 'upload', 'media_id' => $media->id]);
        $note = $owner->libraryItems()->create(['source' => 'note', 'body' => 'Private note']);
        foreach ([$file, $note] as $item) {
            $item->members()->attach($viewer);
        }
        $this->actingAs($viewer)->postJson('/api/library/items/bulk-save', ['ulids' => [$file->ulid, $note->ulid]])->assertOk();
        $this->assertSame(2, $viewer->libraryItems()->count());
        $saved = $viewer->libraryItems()->firstOrFail();
        $saved->members()->attach($outsider);
        $this->actingAs($outsider)->postJson('/api/library/items/bulk-save', ['ulids' => [$saved->ulid]])->assertNotFound();
        $this->postJson('/api/library/items/bulk-downloads', ['ulids' => [$saved->ulid]])->assertNotFound();
    }

    public function test_mixed_unauthorized_download_selection_is_rejected_before_any_original_is_opened(): void
    {
        $owner = $this->account();
        $viewer = $this->account();
        $public = $this->media($owner, $this->event());
        $private = $this->media($owner, $this->event([$owner->id]));
        $this->mock(FileStorageService::class)->shouldNotReceive('readStream');
        $this->actingAs($viewer)->postJson('/api/events/media/bulk-downloads', ['ulids' => [$public->ulid, $private->ulid]])->assertNotFound();
    }

    public function test_ticket_rechecks_revoked_access_before_returning_a_stream(): void
    {
        $owner = $this->account();
        $viewer = $this->account();
        $event = $this->event([$owner->id, $viewer->id]);
        $media = $this->media($owner, $event);
        $url = $this->prepare($viewer, [$media->ulid]);
        $event->members()->detach($viewer);
        $this->getJson($url)->assertNotFound();
    }

    public function test_zip_preserves_owned_filenames_original_heic_bytes_and_private_shared_names(): void
    {
        $owner = $this->account();
        $viewer = $this->account();
        $event = $this->event();
        $own = $this->media($viewer, $event, 'image/heic');
        $own->update(['original_filename' => '../../Camera Original.HEIC']);
        $shared = $this->media($owner, $event, 'video/mp4');
        $url = $this->prepare($viewer, [$own->ulid, $shared->ulid]);
        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/zip')->assertHeader('Cache-Control', 'no-store, private');
        $entries = $this->entries($response->streamedContent());
        $this->assertSame([
            $own->ulid.'/Camera Original.HEIC' => 'reviewed', 'media-'.$shared->ulid.'.mp4' => 'reviewed',
        ], $entries);
        $this->getJson($url)->assertNotFound();
    }

    public function test_download_ticket_is_bound_to_the_account(): void
    {
        $owner = $this->account();
        $viewer = $this->account();
        $other = $this->account();
        $media = $this->media($owner, $this->event());
        $url = $this->prepare($viewer, [$media->ulid]);
        $this->actingAs($other)->getJson($url)->assertNotFound();
    }

    public function test_parallel_downloads_are_rejected(): void
    {
        $owner = $this->account();
        $viewer = $this->account();
        $media = $this->media($owner, $this->event());
        $url = $this->prepare($viewer, [$media->ulid]);
        $lock = Cache::lock('bulk-download:user:'.$viewer->id, 60);
        $this->assertTrue($lock->get());
        $this->getJson($url)->assertStatus(429);
        $lock->release();
    }

    public function test_download_ticket_is_bound_to_the_session(): void
    {
        $owner = $this->account();
        $media = $this->media($owner, $this->event());
        $url = $this->prepare($owner, [$media->ulid]);
        $this->app['session']->regenerate();
        $this->withCookie(config('session.cookie'), $this->app['session']->getId());
        $this->getJson($url)->assertNotFound();
    }

    public function test_count_and_actual_total_byte_limits_reject_downloads_without_archive_output(): void
    {
        $owner = $this->account();
        $media = $this->media($owner, $this->event());
        $this->actingAs($owner)->postJson('/api/events/media/bulk-downloads', ['ulids' => array_fill(0, 51, $media->ulid)])->assertUnprocessable();
        $this->mock(FileStorageService::class, function ($mock): void {
            $mock->shouldReceive('getFileSize')->once()->andReturn(BulkDownloadArchive::MAX_BYTES + 1);
            $mock->shouldNotReceive('readStream');
        });
        $this->postJson('/api/events/media/bulk-downloads', ['ulids' => [$media->ulid]])->assertUnprocessable();
    }

    public function test_a_denied_original_get_rejects_the_whole_selection_before_a_ticket_exists(): void
    {
        $owner = $this->account();
        $media = $this->media($owner, $this->event());
        $this->mock(FileStorageService::class, function ($mock): void {
            $mock->shouldReceive('getFileSize')->andReturn(8);
            $mock->shouldReceive('readStream')->once()->andReturn(null);
        });
        $this->actingAs($owner)->postJson('/api/events/media/bulk-downloads', ['ulids' => [$media->ulid]])->assertNotFound();
    }

    public static function streamRevocations(): array
    {
        return [['event'], ['email'], ['ban'], ['age'], ['restriction'], ['module']];
    }

    #[DataProvider('streamRevocations')]
    public function test_access_revoked_during_streaming_stops_before_the_next_entry_and_never_finishes_a_partial_zip(string $change): void
    {
        $owner = $this->account();
        $viewer = $this->account();
        $event = $this->event([$owner->id, $viewer->id]);
        $first = $this->media($owner, $event);
        $last = $this->media($owner, $event);
        Storage::disk('photos')->put($last->reviewed_object_key, 'secret!!');
        $archive = app(BulkDownloadArchive::class);
        $entries = $archive->preflight($viewer, 'events', [$first->ulid, $last->ulid]);
        $output = '';
        try {
            $archive->write($viewer, 'events', $entries, function (string $chunk) use (&$output, $event, $viewer, $change): void {
                $output .= $chunk;
                if (str_contains($chunk, 'reviewed')) {
                    match ($change) {
                        'event' => $event->members()->detach($viewer),
                        'email' => $viewer->forceFill(['email_verified_at' => null])->save(),
                        'ban' => $viewer->forceFill(['banned_at' => now(), 'ban_hides_content' => false])->save(),
                        'age' => app(SettingsService::class)->set(SettingsService::AGE_VERIFICATION_REQUIRED, '1'),
                        'restriction' => UserRestriction::create(['user_id' => $viewer->id, 'capability' => RestrictionCapability::MediaView]),
                        'module' => config(['instance.modules.events' => false]),
                    };
                }
            });
            $this->fail('Revocation must interrupt the stream.');
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
            $this->assertStringNotContainsString('secret!!', $output);
            $this->assertStringNotContainsString("PK\x05\x06", $output);
        }
    }

    public function test_large_file_reads_and_emits_small_chunks_without_buffering_the_file_or_archive(): void
    {
        $owner = $this->account();
        $media = $this->media($owner, $this->event());
        $size = 50 * 1024 * 1024;
        $maxRead = 0;
        $closed = 0;
        $this->mock(FileStorageService::class, function ($mock) use ($size, &$maxRead, &$closed): void {
            $mock->shouldReceive('getFileSize')->andReturn($size);
            $mock->shouldReceive('readStream')->twice()->andReturnUsing(function () use ($size, &$maxRead, &$closed) {
                $position = 0;
                $stream = FnStream::decorate(Utils::streamFor(''), [
                    'read' => function (int $length) use ($size, &$maxRead, &$position): string {
                        $maxRead = max($maxRead, $length);
                        $length = min($length, $size - $position);
                        $position += $length;

                        return str_repeat('x', $length);
                    },
                    'eof' => static function () use ($size, &$position): bool {
                        return $position >= $size;
                    },
                    'close' => static function () use (&$closed): void {
                        $closed++;
                    },
                ]);

                return StreamWrapper::getResource($stream);
            });
        });
        $archive = app(BulkDownloadArchive::class);
        $entries = $archive->preflight($owner, 'events', [$media->ulid]);
        $emitted = 0;
        $maxChunk = 0;
        memory_reset_peak_usage();
        $before = memory_get_usage(true);
        $archive->write($owner, 'events', $entries, static function (string $chunk) use (&$emitted, &$maxChunk): void {
            $emitted += strlen($chunk);
            $maxChunk = max($maxChunk, strlen($chunk));
        });
        $this->assertLessThanOrEqual(65536, $maxRead);
        $this->assertLessThanOrEqual(65536, $maxChunk);
        // ZipStream's internal fread request preallocates a fixed 16 MiB in
        // PHP, even though our stream returns at most 64 KiB per read. Keep
        // the budget constant and well below this 50 MiB source's full size.
        $this->assertLessThan(24 * 1024 * 1024, memory_get_peak_usage(true) - $before);
        $this->assertGreaterThan($size, $emitted);
        $this->assertSame(2, $closed);
    }

    public function test_disabled_storage_blocks_save_but_existing_files_still_download(): void
    {
        $owner = $this->account(['library_enabled' => false]);
        $media = $this->media($owner, $this->event());
        $this->actingAs($owner)->postJson('/api/events/media/bulk-save', ['ulids' => [$media->ulid]])->assertForbidden();
        $url = $this->prepare($owner, [$media->ulid]);
        $this->get($url)->assertOk();
    }

    public function test_real_s3_adapters_request_sdk_streaming_for_every_media_disk(): void
    {
        foreach (['s3', 'photos', 'hls'] as $name) {
            $called = false;
            $disk = Storage::build([
                ...config('filesystems.disks.'.$name),
                'driver' => 's3', 'key' => 'disposable', 'secret' => 'disposable', 'region' => 'us-east-1',
                'bucket' => 'disposable-test', 'endpoint' => 'http://127.0.0.1:9',
                'handler' => function (CommandInterface $command) use (&$called): PromiseInterface {
                    $called = true;
                    $this->assertTrue($command['@http']['stream'] ?? false, 'GetObject must use streaming HTTP, not SDK whole-body buffering.');

                    return Create::promiseFor(new Result(['Body' => Utils::streamFor('streamed')]));
                },
            ]);
            $resource = $disk->readStream('fixture.bin');
            $this->assertTrue($called);
            $this->assertIsResource($resource);
            fclose($resource);
        }
    }

    public function test_ticket_consumed_while_waiting_for_the_user_lock_is_not_replayed(): void
    {
        $owner = $this->account();
        $session = $this->app['session']->driver();
        $session->start();
        $token = str_repeat('a', 48);
        $key = 'bulk-download:'.$token;
        $ticket = ['user_id' => $owner->id, 'session' => hash('sha256', $session->getId()), 'source' => 'events', 'ulids' => []];
        $lock = \Mockery::mock(Lock::class);
        $lock->shouldReceive('get')->once()->andReturn(true);
        $lock->shouldReceive('release')->once()->andReturn(true);
        Cache::shouldReceive('get')->with($key)->twice()->andReturn($ticket, null);
        Cache::shouldReceive('lock')->with('bulk-download:user:'.$owner->id, 3600)->once()->andReturn($lock);
        $archive = \Mockery::mock(BulkDownloadArchive::class);
        $archive->shouldNotReceive('preflight');
        $request = Request::create('/api/bulk-downloads/'.$token, 'GET');
        $request->setUserResolver(fn (): User => $owner);
        $request->setLaravelSession($session);
        try {
            app(BulkTransferController::class)->download($request, $token, $archive);
            $this->fail('A consumed ticket must not reach archive preflight.');
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
    }

    public function test_anonymous_requests_cannot_save_or_prepare_archives(): void
    {
        $this->postJson('/api/events/media/bulk-save', ['ulids' => []])->assertUnauthorized();
        $this->postJson('/api/events/media/bulk-downloads', ['ulids' => []])->assertUnauthorized();
    }
}
