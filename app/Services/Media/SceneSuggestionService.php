<?php

namespace App\Services\Media;

use App\Models\Media;

/**
 * Turns the transcoder's raw scene analysis into a short list of suggested
 * chapter starts: the end of every black gap and the strongest hard cuts, no
 * two closer than a minimum gap, and none right at the start of the video.
 */
class SceneSuggestionService
{
    /** Most suggestions returned, matching the chapter limit. */
    public const MAX_SUGGESTIONS = 200;

    /** A black gap ending is the strongest signal of a new recording. */
    private const BLACK_END_SCORE = 100.0;

    public function __construct(private readonly HlsService $hls) {}

    /**
     * @return array{analysed: bool, suggestions: list<array{ms: int, kind: string, score: float, thumbnail: ?string}>}
     */
    public function suggestions(Media $video, int $minGapMs): array
    {
        $analysis = $this->hls->sceneAnalysis($video);
        if ($analysis === null) {
            return ['analysed' => false, 'suggestions' => []];
        }

        $candidates = [];
        foreach ($analysis['blackSegments'] as $black) {
            $candidates[] = ['ms' => $black['endMs'], 'kind' => 'black', 'score' => self::BLACK_END_SCORE, 'thumbnail' => $black['thumbnail']];
        }
        foreach ($analysis['cuts'] as $cut) {
            $candidates[] = ['ms' => $cut['ms'], 'kind' => 'cut', 'score' => $cut['score'], 'thumbnail' => $cut['thumbnail']];
        }
        // Strongest first; among equals, earlier first, so the choice is stable.
        usort($candidates, fn (array $a, array $b): int => [$b['score'], $a['ms']] <=> [$a['score'], $b['ms']]);

        $duration = $analysis['durationMs'] ?? $video->video_duration_ms;
        $accepted = [];
        foreach ($candidates as $candidate) {
            if (count($accepted) >= self::MAX_SUGGESTIONS) {
                break;
            }
            if ($candidate['ms'] < $minGapMs || ($duration !== null && $candidate['ms'] >= $duration)) {
                continue;
            }
            foreach ($accepted as $kept) {
                if (abs($kept['ms'] - $candidate['ms']) < $minGapMs) {
                    continue 2;
                }
            }
            $accepted[] = $candidate;
        }
        usort($accepted, fn (array $a, array $b): int => $a['ms'] <=> $b['ms']);

        return ['analysed' => true, 'suggestions' => $accepted];
    }
}
