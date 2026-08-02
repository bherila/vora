<?php

namespace App\Http\Requests\Post;

use App\Models\Media;
use App\Models\Story;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class StartCanonicalDiscussionRequest extends FormRequest
{
    private Media|Story|null $discussionContent = null;

    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null || ! $user->isApproved() || ! $user->canLogin()) {
            return false;
        }

        $content = $this->routeIs('media.discussion.start')
            ? Media::query()->where('ulid', $this->route('ulid'))->first()
            : Story::query()->where('ulid', $this->route('ulid'))->first();

        if (($content instanceof Media || $content instanceof Story) && $user->can('view', $content)) {
            $this->discussionContent = $content;

            return true;
        }

        return false;
    }

    protected function failedAuthorization(): void
    {
        throw new NotFoundHttpException('Not found.');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:2000'],
        ];
    }

    public function content(): Media|Story
    {
        if (! $this->discussionContent instanceof Media && ! $this->discussionContent instanceof Story) {
            throw new NotFoundHttpException('Not found.');
        }

        return $this->discussionContent;
    }
}
