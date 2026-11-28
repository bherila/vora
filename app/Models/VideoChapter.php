<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A named start time within a video. Only the uploader edits a video's
 * chapters, and the list is always replaced as a whole (VideoChapterService).
 * Author columns are internal: payloads expose only the time and title, so a
 * Separate persona's owner is never revealed through chapters.
 */
class VideoChapter extends Model
{
    /** Most chapters one video may have. */
    public const MAX_PER_VIDEO = 200;

    /** Longest chapter title, in characters. */
    public const MAX_TITLE_LENGTH = 120;

    /** @var list<string> */
    protected $fillable = ['media_id', 'start_ms', 'title', 'user_id', 'character_id'];

    /** @var list<string> */
    protected $hidden = ['user_id', 'character_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'media_id' => 'integer',
            'start_ms' => 'integer',
            'user_id' => 'integer',
            'character_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Media, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
