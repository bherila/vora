<?php

namespace App\Http\Requests\Profile;

use App\Enums\Audience;
use App\Http\Requests\Concerns\CapsArrayInput;
use App\Models\User;
use App\Rules\AllExist;
use App\Services\Auth\SignIn;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    use CapsArrayInput;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->user()?->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'display_name' => ['sometimes', 'required', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'pronouns' => ['nullable', 'string', 'max:40'],
            'gender' => ['nullable', 'string', Rule::in(['male', 'female', 'other'])],
            'gender_other' => ['required_if:gender,other', 'nullable', 'string', 'max:100'],
            'user_type' => ['nullable', 'string', Rule::in(['human', 'furry', 'other'])],
            'user_type_other' => ['required_if:user_type,other', 'nullable', 'string', 'max:100'],
            'preferred_user_types' => ['nullable', 'array'],
            'preferred_user_types.*' => ['required', 'string', 'distinct', Rule::in(['human', 'furry', 'other'])],
            'preferred_genders' => ['nullable', 'array'],
            'preferred_genders.*' => ['required', 'string', 'distinct', Rule::in(['male', 'female', 'other'])],
            'profile_audience' => ['sometimes', Rule::in(Audience::values())],
            'audience_user_ids' => ['nullable', 'array', 'max:100', new AllExist('users', scope: fn (Builder $query) => $query->where('id', '!=', $userId))],
            'audience_user_ids.*' => ['integer', 'distinct'],
            'notify_new_post' => ['sometimes', 'boolean'],
            'notify_post_reaction' => ['sometimes', 'boolean'],
            'notify_post_comment' => ['sometimes', 'boolean'],
            'notify_follow_request' => ['sometimes', 'boolean'],
            'notify_follow_accepted' => ['sometimes', 'boolean'],
            'notify_co_author_invite' => ['sometimes', 'boolean'],
            'notify_co_author_invite_accepted' => ['sometimes', 'boolean'],
            'notify_favorite' => ['sometimes', 'boolean'],
            'notify_message' => ['sometimes', 'boolean'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            // Changing the address that receives password resets is a credential
            // change: a hijacked session alone must not be able to make it.
            'current_password' => [
                Rule::requiredIf(fn (): bool => $this->changesEmail()),
                'nullable',
                'string',
                'current_password:web',
            ],
        ];
    }

    /** Whether this request asks to change the local account's email address. */
    public function changesEmail(): bool
    {
        $email = $this->input('email');
        $user = $this->user();

        // Locked and provider-bound addresses are refused by the controller with
        // their own message; asking for a password first would hide it.
        return SignIn::mode() === SignIn::LOCAL
            && is_string($email)
            && $user instanceof User
            && $email !== $user->email
            && ! $user->email_locked
            && $user->oauth_subject === null;
    }

    /**
     * @return list<int>
     */
    public function audienceUserIds(): array
    {
        return array_values(array_unique(array_map('intval', (array) $this->input('audience_user_ids', []))));
    }
}
