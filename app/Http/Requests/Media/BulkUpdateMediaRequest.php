<?php

namespace App\Http\Requests\Media;

use App\Enums\Audience;
use App\Http\Requests\Concerns\ValidatesAudience;
use App\Models\Character;
use App\Models\Media;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

class BulkUpdateMediaRequest extends BulkMediaRequest
{
    use ValidatesAudience {
        audience as private validatedAudience;
    }

    public const ACTION_ASSIGN_CHARACTER = 'assign_character';

    public const ACTION_CLEAR_CHARACTER = 'clear_character';

    public const ACTION_SET_PRIVACY = 'set_privacy';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'action' => ['required', Rule::in([
                self::ACTION_ASSIGN_CHARACTER,
                self::ACTION_CLEAR_CHARACTER,
                self::ACTION_SET_PRIVACY,
            ])],
            'character_id' => [
                'required_if:action,'.self::ACTION_ASSIGN_CHARACTER,
                'nullable',
                'integer',
                Rule::exists('characters', 'id')->where('user_id', $this->user()?->id),
            ],
            ...$this->audienceRules(['required_if:action,'.self::ACTION_SET_PRIVACY]),
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator): void {
            if ($this->action() === self::ACTION_SET_PRIVACY) {
                $this->validateSpecificAudienceMembersAreMutuals($validator);
                $characterAssociatedCount = Media::query()
                    ->whereIn('id', $this->mediaIds())
                    ->where('user_id', $this->user()?->id)
                    ->whereNotNull('character_id')
                    ->count();

                if ($characterAssociatedCount > 0) {
                    $validator->errors()->add('media_ids', 'Character media inherits character privacy. Clear the character first to change media privacy directly.');
                }
            }
        });
    }

    public function action(): string
    {
        return (string) $this->input('action');
    }

    public function character(): ?Character
    {
        $id = $this->input('character_id');
        if ($id === null) {
            return null;
        }

        return Character::query()
            ->with('audienceMembers')
            ->where('user_id', $this->user()?->id)
            ->find((int) $id);
    }

    /**
     * The chosen audience is only meaningful for the set_privacy action.
     */
    public function audience(): Audience
    {
        if ($this->action() !== self::ACTION_SET_PRIVACY) {
            return Audience::Everyone;
        }

        return $this->validatedAudience();
    }
}
