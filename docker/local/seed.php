<?php

use App\Enums\Audience;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('staging') || env('LOCAL_STAGING_ENABLED') !== '1'
    || ($argv[1] ?? null) !== '--allow-disposable-accounts'
    || config('database.default') !== 'sqlite'
    || config('database.connections.sqlite.database') !== '/var/lib/vora-staging/database.sqlite') {
    fwrite(STDERR, "Refusing to seed outside explicitly enabled local staging SQLite.\n");
    exit(1);
}
if (User::withTrashed()->exists()) {
    fwrite(STDERR, "Refusing to seed a database that already contains accounts.\n");
    exit(1);
}

foreach (['admin', 'uploader', 'recipient', 'outsider'] as $role) {
    $user = new User;
    $user->forceFill([
        'name' => ucfirst($role).' Local Staging',
        'display_name' => ucfirst($role).' Local Staging',
        'email' => $role.'@staging.invalid',
        'email_verified_at' => now(),
        'approved_at' => now(),
        'password' => 'Local-Staging-Only-2026!',
        'birth_date' => '1990-01-01',
        'gender' => 'other',
        'user_type' => 'human',
        'profile_audience' => Audience::Everyone,
        'is_admin' => $role === 'admin',
        'library_enabled' => true,
    ])->save();
}
fwrite(STDOUT, "Created four disposable local staging accounts. Normal email two-factor login remains enabled.\n");
