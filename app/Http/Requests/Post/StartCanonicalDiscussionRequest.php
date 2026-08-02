<?php

namespace App\Http\Requests\Post;

use App\Enums\RestrictionCapability;
use App\Models\Media;
use App\Models\Story;
use App\Services\Moderation\RestrictionGate;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class StartCanonicalDiscussionRequest extends FormRequest
{
    private Media|Story|null $discussionContent = null;

    private bool $restrictionDenied = false;

    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null || ! $user->isApproved() || ! $user->canLogin()) {
            return false;
        }

        $content = $this->routeIs('media.discussion.start')
            ? Media::query()->where('ulid', $this->route('ulid'))->first()
            : Story::query()->where('ulid', $this->route('ulid'))->first();

        if (! ($content instanceof Media || $content instanceof Story) || ! $user->can('view', $content)) {
            return false;
        }

        $this->discussionContent = $content;
        $restrictions = app(RestrictionGate::class);
        $this->restrictionDenied = $restrictions->denies($user, RestrictionCapability::CommentCreate)
            || ($content instanceof Media
                && $content->user_id !== $user->id
                && $restrictions->denies($user, RestrictionCapability::MediaView));

        return ! $this->restrictionDenied;
    }

    protected function failedAuthorization(): void
    {
        if ($this->restrictionDenied) {
            parent::failedAuthorization();
        }

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
