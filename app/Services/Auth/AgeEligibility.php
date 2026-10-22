<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\SettingsService;

class AgeEligibility
{
    public function __construct(private readonly SettingsService $settings) {}

    /** @return array<string, mixed> */
    public function policy(): array
    {
        $minimum = $this->settings->minimumAge();

        return [
            'minimum_age' => $minimum,
            'age_verification_required' => $this->settings->ageVerificationRequired(),
            'birth_date_required' => true,
            'latest_birth_date' => today()->subYears($minimum ?? 0)->toDateString(),
        ];
    }

    /** @return list<string> */
    public function birthDateRules(bool $adminCorrection = false): array
    {
        // Admins must be able to record an accurate date even when it makes an
        // existing account ineligible. They cannot verify away the minimum age.
        $policy = $this->policy();

        return [
            ...($adminCorrection ? ['sometimes', 'nullable'] : ['required']),
            'date_format:Y-m-d',
            'before_or_equal:'.($adminCorrection ? today()->toDateString() : $policy['latest_birth_date']),
        ];
    }

    public function reason(User $user): ?string
    {
        // Retain an administrative recovery path when policy changes apply to
        // current sessions; account approval and email verification still apply.
        if ($user->isAdmin()) {
            return null;
        }
        $policy = $this->policy();
        if ($policy['minimum_age'] !== null && ($user->birth_date === null
            || $user->birth_date->toDateString() > $policy['latest_birth_date'])) {
            return 'minimum_age';
        }
        if ($policy['age_verification_required'] && ($user->birth_date === null || $user->id_verified_at === null)) {
            return 'verification_required';
        }

        return null;
    }

    public function terms(): string
    {
        $minimum = $this->settings->minimumAge();
        $text = $minimum === null ? 'This site has no configured minimum account age.' : 'You must be at least '.$minimum.' years old to hold an account on this site.';

        return $text.($this->settings->ageVerificationRequired() ? ' An administrator must confirm your age before you can access shared content.' : ' Manual age verification is not required by this site.');
    }
}
