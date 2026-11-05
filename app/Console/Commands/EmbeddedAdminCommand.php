<?php

namespace App\Console\Commands;

use App\Services\Auth\SignIn;
use BWH\Auth\Models\AuthAuditLog;
use BWH\AuthManager\Embedded\EmbeddedContext;
use BWH\AuthManager\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Bootstrap an empty provider directory without putting a password in shell history. */
class EmbeddedAdminCommand extends Command
{
    protected $signature = 'ops:embedded-admin {email : First provider administrator email} {name : Administrator display name}';

    protected $description = 'Create the first administrator in an empty embedded identity directory';

    public function handle(EmbeddedContext $context): int
    {
        if (SignIn::mode() !== SignIn::EMBEDDED) {
            $this->error('This command requires AUTH_SIGN_IN=embedded.');

            return self::FAILURE;
        }

        return $context->run(function (): int {
            if (User::withTrashed()->exists()) {
                $this->error('The provider directory already contains people. Use its administrator screens.');

                return self::FAILURE;
            }

            $values = [
                'email' => mb_strtolower(trim((string) $this->argument('email'))),
                'name' => trim((string) $this->argument('name')),
                'password' => $this->secret('Password'),
                'password_confirmation' => $this->secret('Confirm password'),
            ];
            $validator = Validator::make($values, [
                'email' => ['required', 'email:rfc', 'max:254'],
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', 'min:'.config('auth-manager.password_min_length', 12), 'max:72', 'confirmed'],
            ]);
            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $error) {
                    $this->error($error);
                }

                return self::FAILURE;
            }

            $created = DB::transaction(static function () use ($values): bool {
                // Password entry may take time; another operator may finish setup meanwhile.
                if (User::withTrashed()->lockForUpdate()->exists()) {
                    return false;
                }

                $user = User::create([
                    'email' => $values['email'],
                    'name' => $values['name'],
                    'password' => $values['password'],
                    'user_role' => 'admin',
                ]);
                AuthAuditLog::create([
                    'user_id' => $user->id,
                    'acting_user_id' => null,
                    'event' => 'embedded_admin_created',
                    'auth_method' => 'console',
                    'succeeded' => true,
                ]);

                return true;
            });
            if (! $created) {
                $this->error('The provider directory already contains people. Use its administrator screens.');

                return self::FAILURE;
            }
            $this->info('Provider administrator created. Register the Vora OAuth client and grant this person access before signing in to Vora.');

            return self::SUCCESS;
        });
    }
}
