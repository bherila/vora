<?php

namespace App\Http\Requests\Concerns;

use App\Enums\Audience;
use Illuminate\Validation\Rule;

/**
 * Shared audience/discoverability validation and accessors for the Form Requests
 * that set a privacy policy (media, stories, future posts). Keeps the rule set
 * and the input parsing in one place so every content type validates the privacy
 * fields identically.
 */
trait ValidatesAudience
{
    /**
     * Validation rules for the privacy fields. $audiencePresence is the leading
     * presence rule(s) for the audience field, e.g. ['required'] on create or
     * ['sometimes', 'required'] on update.
     *
     * @param  list<string>  $audiencePresence
     * @return array<string, mixed>
     */
    protected function audienceRules(array $audiencePresence): array
    {
        return [
            'audience' => [...$audiencePresence, Rule::in(Audience::values())],
            'discoverable' => ['nullable', 'boolean'],
            'audience_user_ids' => ['nullable', 'array'],
            'audience_user_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')],
        ];
    }

    /**
     * The chosen audience, defaulting to Everyone when absent or invalid.
     */
    public function audience(): Audience
    {
        $value = $this->input('audience');

        return is_string($value) && in_array($value, Audience::values(), true)
            ? Audience::from($value)
            : Audience::Everyone;
    }

    /**
     * Whether the item is listed on discovery surfaces. Defaults to true (listed)
     * when the caller does not send the field.
     */
    public function discoverable(): bool
    {
        return $this->has('discoverable') ? $this->boolean('discoverable') : true;
    }

    /**
     * The allowlist user ids, only meaningful for the SpecificPeople audience.
     *
     * @return list<int>
     */
    public function audienceUserIds(): array
    {
        return array_values(array_unique(array_map('intval', (array) $this->input('audience_user_ids', []))));
    }
}
