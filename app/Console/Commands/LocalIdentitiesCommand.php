<?php

namespace App\Console\Commands;

use App\Services\Auth\LocalIdentityMigration;
use Illuminate\Console\Command;
use RuntimeException;

class LocalIdentitiesCommand extends Command
{
    protected $signature = 'ops:local-identities
        {--apply : Commit eligible per-account migrations; otherwise only inspect}
        {--user=* : Restrict to explicit local account IDs}
        {--copy-passkeys : Copy compatible passkeys, preserving user handles and counters}
        {--leave-passkeys : Explicitly leave passkeys local and migrate passwords only}
        {--rp-id= : Confirm the unchanged WebAuthn relying-party ID when copying passkeys}
        {--origin= : Confirm the unchanged browser origin when copying passkeys}
        {--json : Emit a report containing account IDs and reason codes, never credentials}';

    protected $description = 'Plan or apply local accounts to the same-database embedded identity provider';

    public function handle(LocalIdentityMigration $migration): int
    {
        $ids = [];
        foreach ((array) $this->option('user') as $id) {
            if (! is_string($id) || ! ctype_digit($id) || (int) $id < 1 || (string) (int) $id !== $id) {
                $this->error('--user must contain positive integer account IDs.');

                return self::INVALID;
            }
            $ids[] = (int) $id;
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        try {
            $report = $migration->run(
                apply: (bool) $this->option('apply'),
                ids: $ids,
                copyPasskeys: (bool) $this->option('copy-passkeys'),
                leavePasskeys: (bool) $this->option('leave-passkeys'),
                rpId: $this->option('rp-id'),
                origin: $this->option('origin'),
            );
        } catch (RuntimeException $exception) {
            // The service exposes sanitized preflight failures only, never query bindings.
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info($report['apply'] ? 'Apply: completed accounts are committed separately.' : 'Dry run: no account, credential, binding or sequence was changed.');
            $this->table(['Local ID', 'Status', 'Reason', 'Passkeys copied', 'Disabled reasons'], array_map(
                static fn (array $row): array => [$row['user_id'], $row['status'], $row['reason'], $row['passkeys'], implode(', ', $row['disabled_reasons'])],
                $report['accounts'],
            ));
            $this->line('Next provider subject floor: '.$report['next_subject_floor']);
            $this->line('This command does not switch the installation\'s public sign-in mode.');
        }

        return $report['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
