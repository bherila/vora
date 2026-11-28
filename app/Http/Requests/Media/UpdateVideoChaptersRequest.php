<?php

namespace App\Http\Requests\Media;

use App\Models\VideoChapter;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a whole replacement chapter list. Ownership is checked by the
 * controller through MediaPolicy::editChapters, and the controller bounds
 * start times by the video's duration once it is known.
 */
class UpdateVideoChaptersRequest extends FormRequest
{
    /** Upper bound for start times before the duration is known: 48 hours. */
    public const MAX_START_MS = 48 * 3_600_000;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->isApproved() && $user->canLogin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'chapters' => ['present', 'array', 'max:'.VideoChapter::MAX_PER_VIDEO],
            'chapters.*' => ['required', 'array:start_ms,title'],
            'chapters.*.start_ms' => ['required', 'integer', 'min:0', 'max:'.self::MAX_START_MS, 'distinct'],
            // Titles are single-line cue text: no control characters (a blank
            // line would end a WebVTT cue).
            'chapters.*.title' => ['required', 'string', 'max:'.VideoChapter::MAX_TITLE_LENGTH, 'not_regex:/[\x00-\x1F\x7F]/u'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'chapters.*.start_ms.distinct' => 'Two chapters start at the same time.',
            'chapters.*.title.not_regex' => 'Chapter titles must be a single line.',
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $chapters = $this->input('chapters');
            if (! is_array($chapters) || $chapters === [] || $validator->errors()->isNotEmpty()) {
                return;
            }
            $starts = array_map(fn (mixed $chapter): mixed => is_array($chapter) ? ($chapter['start_ms'] ?? null) : null, $chapters);
            if (! in_array(0, array_map('intval', $starts), true)) {
                $validator->errors()->add('chapters', 'The first chapter must start at 0:00.');
            }
        });
    }

    /**
     * @return list<array{start_ms: int, title: string}>
     */
    public function chapters(): array
    {
        /** @var list<array{start_ms: int|string, title: string}> $chapters */
        $chapters = $this->validated('chapters');

        return array_map(fn (array $chapter): array => [
            'start_ms' => (int) $chapter['start_ms'],
            'title' => trim($chapter['title']),
        ], $chapters);
    }
}
