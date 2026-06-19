<?php

namespace Database\Factories;

use App\Enums\Audience;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        $name = fake()->name();

        return [
            'name' => $name,
            'display_name' => $name,
            'birth_date' => fake()->dateTimeBetween('-80 years', '-18 years')->format('Y-m-d'),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'gender' => 'male',
            'user_type' => 'human',
            'profile_audience' => Audience::Everyone,
            'notify_new_post' => true,
            'notify_post_reaction' => true,
            'notify_post_comment' => true,
            'notify_follow_request' => true,
            'notify_follow_accepted' => true,
            'notify_co_author_invite' => true,
            'notify_co_author_invite_accepted' => true,
            'preferred_user_types' => ['human', 'furry', 'other'],
            'preferred_genders' => ['male', 'female', 'other'],
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Admin-approved account ready to use the app.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_admin' => true,
            'approved_at' => now(),
        ]);
    }

    /**
     * Verified + admin-approved (non-admin) account.
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'approved_at' => now(),
        ]);
    }

    /**
     * Verified but awaiting admin approval.
     */
    public function pendingApproval(): static
    {
        return $this->state(fn (array $attributes) => [
            'approved_at' => null,
        ]);
    }

    /**
     * Hard-disabled / rejected account.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_disabled' => true,
        ]);
    }

    /**
     * Banned account (login allowed, gated to appeal/deactivate/delete). Content
     * stays visible — "memorialized".
     */
    public function banned(): static
    {
        return $this->state(fn (array $attributes) => [
            'approved_at' => now(),
            'banned_at' => now(),
            'ban_reason' => 'Violated community rules.',
            'ban_hides_content' => false,
        ]);
    }

    /**
     * Banned account whose content is also hidden from other users.
     */
    public function bannedAndHidden(): static
    {
        return $this->banned()->state(fn (array $attributes) => [
            'ban_hides_content' => true,
        ]);
    }

    /**
     * Account under a legal hold (cannot delete their account).
     */
    public function onLegalHold(): static
    {
        return $this->state(fn (array $attributes) => [
            'legal_hold_at' => now(),
        ]);
    }

    /**
     * A trusted inviter — their invitees skip the admin approval gate.
     */
    public function trustedInviter(): static
    {
        return $this->state(fn (array $attributes) => [
            'approved_at' => now(),
            'trusted_inviter' => true,
        ]);
    }

    /**
     * Barred from receiving future invite grants.
     */
    public function cannotReceiveInvites(): static
    {
        return $this->state(fn (array $attributes) => [
            'can_receive_invites' => false,
        ]);
    }
}
