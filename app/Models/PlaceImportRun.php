<?php

namespace App\Models;

use App\Services\Location\PlaceImportRuns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One GeoNames import, queued or run. See {@see PlaceImportRuns}.
 *
 * @property string $id
 * @property string $state
 * @property string $source
 * @property string $dataset
 * @property string $mode
 * @property bool $rehome
 * @property bool $dry_run
 * @property string|null $files_run_id
 * @property string|null $preview_of
 * @property int|null $requested_by
 * @property array<string, mixed>|null $result
 * @property string|null $error
 * @property Carbon|null $requested_at
 * @property Carbon|null $started_at
 * @property Carbon|null $heartbeat_at
 * @property Carbon|null $finished_at
 */
class PlaceImportRun extends Model
{
    public const PENDING = 'pending';

    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const SUPERSEDED = 'superseded';

    public const INTERRUPTED = 'interrupted';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** Microseconds, so runs finishing within one second still order. */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * The current time in the stored format, for conditional updates that
     * bypass the model.
     */
    public static function now(): string
    {
        return now()->format('Y-m-d H:i:s.u');
    }

    protected function casts(): array
    {
        return [
            'rehome' => 'boolean',
            'dry_run' => 'boolean',
            'requested_by' => 'integer',
            'result' => 'array',
            'requested_at' => 'datetime',
            'started_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return in_array($this->state, [self::PENDING, self::RUNNING], true);
    }
}
