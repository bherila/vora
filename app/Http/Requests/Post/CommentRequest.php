<?php

namespace App\Http\Requests\Post;

use App\Enums\RestrictionCapability;
use App\Services\Moderation\RestrictionGate;
use Illuminate\Foundation\Http\FormRequest;

class CommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null || ! $user->isApproved() || ! $user->canLogin()) {
            return false;
        }

        // Authorize the target post here, before validation runs, so a viewer who
        // cannot see an existing post gets the same generic 404 as a missing post
        // (the {post} route binding already 404s missing rows) rather than a 422
        // that would reveal the post exists.
        abort_unless($user->can('view', $this->route('post')), 404, 'Not found.');

        return ! app(RestrictionGate::class)->denies($user, RestrictionCapability::CommentCreate);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:2000'],
            // Same-post and one-level constraints are enforced in the controller,
            // which has the post in scope.
            'parent_id' => ['nullable', 'integer'],
        ];
    }
}
