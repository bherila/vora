<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Auth\SignIn;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Links an existing local account to its identity-provider subject before the
 * installation switches to provider sign-in. Provider sign-in never links by
 * email, so without this the person's first sign-in is refused because the
 * address already belongs to an account.
 */
final class BindProviderAccountCommand extends Command
{
    protected $signature = 'ops:bind-provider-account
        {email : Email address of the existing local account}
        {subject : The person\'s subject (opaque id) at the identity provider}
        {--provider= : Provider name; defaults to OAUTH_PROVIDER}';

    protected $description = 'Link an existing account to its identity provider subject';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $subject = trim((string) $this->argument('subject'));
        $provider = (string) ($this->option('provider') ?: SignIn::provider());
        if ($subject === '' || $provider === '') {
            $this->error('Give a non-empty subject and provider name.');

            return self::FAILURE;
        }

        return DB::transaction(function () use ($email, $subject, $provider): int {
            $user = User::withTrashed()->where('email', $email)->lockForUpdate()->first();
            if ($user === null) {
                $this->error('No account has that email address.');

                return self::FAILURE;
            }
            if ($user->oauth_provider === $provider && $user->oauth_subject === $subject) {
                $this->info("Account {$user->id} is already linked.");

                return self::SUCCESS;
            }
            if ($user->oauth_subject !== null) {
                $this->error("Account {$user->id} is already linked to a different identity; it was not changed.");

                return self::FAILURE;
            }
            $holder = User::withTrashed()->where('oauth_provider', $provider)->where('oauth_subject', $subject)->lockForUpdate()->first();
            if ($holder !== null) {
                $this->error("That identity is already linked to account {$holder->id}; nothing was changed.");

                return self::FAILURE;
            }

            $user->forceFill(['oauth_provider' => $provider, 'oauth_subject' => $subject])->save();
            $this->info("Linked account {$user->id} to {$provider} subject {$subject}.");

            return self::SUCCESS;
        });
    }
}
