<?php

namespace App\Http\Requests\Story;

use App\Models\Story;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdateStoryAuthorIdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $current = $this->user();
        $story = $this->route('story');
        $author = $this->route('user');

        return $current instanceof User
            && $story instanceof Story
            && $author instanceof User
            && $current->id === $author->id
            && $current->isApproved()
            && $current->canLogin()
            && $current->can('update', $story);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $author = $this->route('user');
        $authorId = $author instanceof User ? $author->id : 0;

        return [
            'character_id' => [
                'required',
                'nullable',
                'integer',
                Rule::exists('characters', 'id')->where(
                    fn (Builder $query): Builder => $query
                        ->where('user_id', $authorId)
                        ->whereNull('deleted_at'),
                ),
            ],
        ];
    }

    protected function failedAuthorization(): void
    {
        $current = $this->user();
        $story = $this->route('story');
        $author = $this->route('user');

        // An accepted author choosing another author's identity is a real
        // permission error. Everyone else gets the story APIs' generic 404 so
        // this endpoint does not become an existence oracle for hidden stories.
        if ($current instanceof User
            && $story instanceof Story
            && $author instanceof User
            && $current->can('update', $story)
            && $current->id !== $author->id) {
            parent::failedAuthorization();
        }

        throw new NotFoundHttpException('Not found.');
    }
}
