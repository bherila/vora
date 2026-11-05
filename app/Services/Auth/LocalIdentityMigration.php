<?php

namespace App\Services\Auth;

use App\Models\User;
use BWH\AuthManager\Services\IdentityCopy\TableSequence;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Copies only locally owned identities, with ownership proof and binding committed
 * atomically. The two Laravel connections must describe the same physical database.
 */
class LocalIdentityMigration
{
    private Connection $host;

    private Connection $provider;

    private string $providerName;

    private string $clientId;

    private string $targetFingerprint;

    private string $sourcePasskeys;

    private string $targetPasskeys;

    private string $targetAudit;

    /**
     * @param  list<int>  $ids
     * @return array{apply: bool, next_subject_floor: int, sequence_raised: bool, failed: bool, accounts: list<array{user_id: int, status: string, reason: string, passkeys: int, disabled_reasons: list<string>}>}
     */
    public function run(bool $apply, array $ids = [], bool $copyPasskeys = false, bool $leavePasskeys = false, ?string $rpId = null, ?string $origin = null): array
    {
        if (SignIn::mode() !== SignIn::EMBEDDED) {
            throw new RuntimeException('Stage the command with AUTH_SIGN_IN=embedded; public sign-in must remain closed during migration.');
        }
        if ($copyPasskeys && $leavePasskeys) {
            throw new RuntimeException('Choose either --copy-passkeys or --leave-passkeys, never both.');
        }
        $this->host = DB::connection();
        $this->provider = DB::connection('auth-manager');
        $this->assertSameDatabase();

        $previousPdo = $this->provider->getRawPdo();
        $previousReadPdo = $this->provider->getRawReadPdo();
        // One PDO makes provider rows, the local ledger, and bindings one commit.
        // No provider-side service that opens a second transaction is used below.
        $this->provider->setPdo($this->host->getPdo());
        $this->provider->setReadPdo($this->host->getPdo());

        try {
            $this->preflight();
            $query = User::on($this->host->getName())->withTrashed()->orderBy('id');
            if ($ids !== []) {
                $query->whereIn('id', $ids);
                if ($query->count() !== count($ids)) {
                    throw new RuntimeException('One or more selected local account IDs do not exist; nothing was migrated.');
                }
            }

            // Include unselected, soft-deleted and formerly allocated source subjects.
            $floor = (new TableSequence($this->host))->next('users');
            $sequence = new TableSequence($this->provider);
            // MySQL AUTO_INCREMENT reservation is DDL: deliberately before account transactions.
            $raised = $apply && $sequence->raiseTo('users', $floor);
            $accounts = [];
            foreach ($query->lazyById(100) as $user) {
                try {
                    $inspect = fn (): array => $this->account((int) $user->getKey(), $apply, $copyPasskeys, $leavePasskeys, $rpId, $origin);
                    $accounts[] = $apply ? $this->host->transaction($inspect, 3) : $inspect();
                } catch (QueryException) {
                    // No SQL, credential bindings, addresses or driver messages in reports.
                    $accounts[] = $this->result((int) $user->getKey(), 'conflict', 'database_conflict');
                }
            }

            return [
                'apply' => $apply,
                'next_subject_floor' => $floor,
                'sequence_raised' => $raised,
                'failed' => collect($accounts)->contains(static fn (array $row): bool => in_array($row['status'], ['conflict', 'unsupported'], true)),
                'accounts' => $accounts,
            ];
        } catch (QueryException) {
            throw new RuntimeException('The migration could not inspect the required schema or reserve subjects. Check the isolated provider setup; no query details are printed.');
        } finally {
            $this->provider->setPdo($previousPdo);
            $this->provider->setReadPdo($previousReadPdo);
        }
    }

    private function assertSameDatabase(): void
    {
        if (! in_array($this->host->getDriverName(), ['sqlite', 'mysql', 'mariadb'], true)
            || $this->host->getDriverName() !== $this->provider->getDriverName()
            || $this->host->getDatabaseName() !== $this->provider->getDatabaseName()
            || $this->host->getName() === $this->provider->getName()
            || $this->host->getTablePrefix() === $this->provider->getTablePrefix()) {
            throw new RuntimeException('Local migration requires distinct host/provider tables in the same physical database. Separate databases are not supported.');
        }
        foreach (['host', 'port', 'username', 'password', 'unix_socket', 'url'] as $key) {
            if ((string) $this->host->getConfig($key) !== (string) $this->provider->getConfig($key)) {
                throw new RuntimeException('The embedded connection must use the same physical database and credentials as the host.');
            }
        }
        if ($this->host->transactionLevel() !== 0 || $this->provider->transactionLevel() !== 0
            || $this->host->getConfig('read') || $this->host->getConfig('write')
            || $this->provider->getConfig('read') || $this->provider->getConfig('write')) {
            throw new RuntimeException('Run local migration outside an existing transaction, without read/write connection splitting.');
        }
    }

    private function preflight(): void
    {
        if (config('auth-manager.sign_in.code_after_password') !== true || config('auth-manager.sign_in.code_only') !== false) {
            throw new RuntimeException('Preserve local sign-in assurance: set AUTH_MANAGER_REQUIRE_CODE_AFTER_PASSWORD=true and AUTH_MANAGER_ALLOW_CODE_ONLY_SIGN_IN=false before planning or applying migration.');
        }
        $this->providerName = SignIn::provider();
        $this->clientId = (string) config('bherila-auth.oauth_client.client_id');
        $issuer = (string) config('auth-manager.issuer');
        if ($this->providerName === '' || strlen($this->providerName) > 64 || $this->clientId === ''
            || rtrim((string) config('bherila-auth.oauth_client.base_url'), '/') !== $issuer) {
            throw new RuntimeException('Configure the embedded issuer, a stable OAUTH_PROVIDER and this installation\'s OAUTH_CLIENT_ID before migration.');
        }
        $client = $this->provider->table('oauth_clients')->where('id', $this->clientId)->where('revoked', false)->first();
        if ($client === null
            || ! in_array('authorization_code', json_decode((string) $client->grant_types, true) ?? [], true)
            || ! in_array((string) config('bherila-auth.oauth_client.redirect_uri'), json_decode((string) $client->redirect_uris, true) ?? [], true)) {
            throw new RuntimeException('The configured provider client must be active and allow authorization-code sign-in with this installation\'s exact callback.');
        }
        if (! $this->host->getSchemaBuilder()->hasTable('local_identity_migrations')) {
            throw new RuntimeException('Apply the host migrations before planning local identities.');
        }
        $overlay = (array) config('auth-manager-embedded.overlay.bherila-auth');
        $this->sourcePasskeys = (string) config('bherila-auth.passkeys.table');
        $this->targetPasskeys = (string) data_get($overlay, 'passkeys.table');
        $this->targetAudit = (string) data_get($overlay, 'audit.table');
        foreach ([$this->sourcePasskeys, $this->targetPasskeys, $this->targetAudit] as $table) {
            if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $table) !== 1) {
                throw new RuntimeException('The configured credential tables are not supported by local migration.');
            }
        }
        $this->targetFingerprint = hash('sha256', json_encode([
            $this->providerName, $issuer, $this->clientId,
            $this->provider->getDriverName(), $this->provider->getDatabaseName(), $this->provider->getTablePrefix(),
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{user_id: int, status: string, reason: string, passkeys: int, disabled_reasons: list<string>} */
    private function account(int $id, bool $apply, bool $copyPasskeys, bool $leavePasskeys, ?string $rpId, ?string $origin): array
    {
        $user = User::on($this->host->getName())->withTrashed()->when($apply, static fn ($query) => $query->lockForUpdate())->find($id);
        if ($user === null) {
            return $this->result($id, 'conflict', 'source_disappeared');
        }
        $passkeys = $this->host->table($this->sourcePasskeys)->where('user_id', $id)->orderBy('id')
            ->when($apply, static fn ($query) => $query->lockForUpdate())
            ->get()->map(static fn (object $row): array => (array) $row)->all();
        $disabled = $this->disabledReasons($user);
        $fingerprint = hash('sha256', json_encode([
            $id, $user->getRawOriginal('name'), $user->getRawOriginal('email'), $user->getRawOriginal('password'), $disabled, $passkeys,
        ], JSON_THROW_ON_ERROR));
        $ledger = $this->host->table('local_identity_migrations')->where('user_id', $id)->first();
        if ($ledger !== null) {
            $matches = $ledger->provider === $this->providerName && $ledger->subject === (string) $id
                && $ledger->client_id === $this->clientId && $ledger->target_fingerprint === $this->targetFingerprint
                && $ledger->source_fingerprint === $fingerprint
                && $user->oauth_provider === $ledger->provider && $user->oauth_subject === $ledger->subject
                && $this->provider->table('users')->where('id', $id)->exists();

            return $this->result($id, $matches ? 'unchanged' : 'conflict', $matches ? 'checkpoint_verified' : 'checkpoint_mismatch', (int) $ledger->passkeys_copied, $disabled);
        }
        if ($user->oauth_provider !== null || $user->oauth_subject !== null) {
            return $this->result($id, 'conflict', 'binding_without_checkpoint');
        }
        if ($this->provider->table('users')->where('id', $id)->exists()) {
            return $this->result($id, 'conflict', 'provider_subject_occupied');
        }
        if (Validator::make(['email' => $user->email, 'name' => $user->name], [
            'email' => ['required', 'email:rfc', 'max:255'], 'name' => ['required', 'string', 'max:255'],
        ])->fails()) {
            return $this->result($id, 'unsupported', 'invalid_profile');
        }
        $email = mb_strtolower($user->email);
        if ($this->provider->table('users')->whereRaw('lower(email) = ?', [$email])->exists()
            || $this->host->table('users')->whereRaw('lower(email) = ?', [$email])->count() !== 1) {
            return $this->result($id, 'conflict', 'email_occupied_or_ambiguous');
        }
        $algorithm = match (config('hashing.driver', 'bcrypt')) {
            'bcrypt' => 'bcrypt', 'argon' => 'argon2i', 'argon2id' => 'argon2id', default => null,
        };
        if ($algorithm === null || (password_get_info((string) $user->getRawOriginal('password'))['algoName'] ?? null) !== $algorithm) {
            return $this->result($id, 'unsupported', 'password_reset_required');
        }
        $reason = $this->passkeyProblem($passkeys, $copyPasskeys, $leavePasskeys, $rpId, $origin);
        if ($reason !== null) {
            return $this->result($id, 'unsupported', $reason);
        }
        $count = $copyPasskeys ? count($passkeys) : 0;
        if (! $apply) {
            return $this->result($id, $disabled === [] ? 'ready' : 'ready_disabled', $passkeys !== [] && $leavePasskeys ? 'passkeys_left_local' : 'new_identity', $count, $disabled);
        }

        $this->write($user, $copyPasskeys ? $passkeys : [], $fingerprint, $disabled);

        return $this->result($id, $disabled === [] ? 'migrated' : 'migrated_disabled', $passkeys !== [] && $leavePasskeys ? 'passkeys_left_local' : 'created_and_bound', $count, $disabled);
    }

    /** @return list<string> */
    private function disabledReasons(User $user): array
    {
        return array_keys(array_filter([
            'disabled' => $user->is_disabled,
            'deleted' => $user->trashed(),
            'unapproved' => ! $user->isApproved(),
            'unverified' => ! $user->hasVerifiedEmail(),
            'banned' => $user->isBanned(),
            'deactivated' => $user->isDeactivated(),
            'password_change_required' => $user->force_change_pw,
        ]));
    }

    /** @param list<array<string, mixed>> $passkeys */
    private function passkeyProblem(array $passkeys, bool $copy, bool $leave, ?string $rpId, ?string $origin): ?string
    {
        if ($passkeys === [] || $leave) {
            return null;
        }
        if (! $copy) {
            return 'passkey_decision_required';
        }
        $appUrl = rtrim((string) config('app.url'), '/');
        $parts = parse_url($appUrl);
        $host = $parts['host'] ?? '';
        $secure = ($parts['scheme'] ?? '') === 'https'
            || (app()->environment(['local', 'testing']) && in_array($host, ['localhost', '127.0.0.1', '::1'], true));
        $hostRp = config('bherila-auth.passkeys.rp_id') ?: $host;
        $providerRp = config('auth-manager-embedded.overlay.bherila-auth.passkeys.rp_id') ?: $host;
        $verification = ['discouraged' => 0, 'preferred' => 1, 'required' => 2];
        $hostVerification = $verification[config('bherila-auth.passkeys.user_verification', 'preferred')] ?? null;
        $providerVerification = $verification[config('auth-manager-embedded.overlay.bherila-auth.passkeys.user_verification', 'preferred')] ?? null;
        $hostOrigins = array_unique([$appUrl, ...(array) config('bherila-auth.passkeys.allowed_origins', [])]);
        $providerOrigins = array_unique([$appUrl, ...(array) config('auth-manager-embedded.overlay.bherila-auth.passkeys.allowed_origins', [])]);
        if (! $secure || isset($parts['path']) || $origin !== $appUrl || $rpId === null || $rpId !== $hostRp || $rpId !== $providerRp
            || ($rpId !== $host && ! str_ends_with($host, '.'.$rpId))
            || $hostVerification === null || $providerVerification === null || $providerVerification < $hostVerification
            || array_diff($providerOrigins, $hostOrigins) !== []) {
            return 'passkey_origin_or_rp_incompatible';
        }
        if (array_diff(['user_id', 'credential_id', 'credential_id_hash', 'public_key', 'counter', 'rp_id', 'transports'],
            $this->provider->getSchemaBuilder()->getColumnListing($this->targetPasskeys)) !== []) {
            return 'passkey_schema_incompatible';
        }
        foreach ($passkeys as $key) {
            if (($key['rp_id'] ?? null) !== $rpId || ! is_string($key['credential_id'] ?? null)
                || $key['credential_id'] === '' || ! is_string($key['public_key'] ?? null) || $key['public_key'] === ''
                || ! is_numeric($key['counter'] ?? null) || $key['counter'] < 0
                || base64_decode($key['public_key'], true) === false
                || ($key['credential_id_hash'] ?? null) !== hash('sha256', $key['credential_id'])) {
                return 'passkey_record_incompatible';
            }
            if ($this->provider->table($this->targetPasskeys)->where('credential_id_hash', $key['credential_id_hash'])->exists()) {
                return 'passkey_already_at_provider';
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $passkeys
     * @param  list<string>  $disabled
     */
    private function write(User $user, array $passkeys, string $fingerprint, array $disabled): void
    {
        $id = (int) $user->getKey();
        $now = now();
        // Raw insert intentionally preserves the hash byte-for-byte: no hashed casts.
        $this->provider->table('users')->insert([
            'id' => $id, 'name' => $user->name, 'email' => $user->email,
            'email_verified_at' => $user->getRawOriginal('email_verified_at'),
            'password' => $user->getRawOriginal('password'), 'remember_token' => null,
            'user_role' => 'user', 'disabled_at' => $disabled === [] ? null : $now,
            'deleted_at' => $user->getRawOriginal('deleted_at'), 'credential_version' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ($passkeys as $key) {
            unset($key['id']);
            $key['user_id'] = $id; // WebAuthn userHandle must stay the same numeric subject.
            $key = array_intersect_key($key, array_flip($this->provider->getSchemaBuilder()->getColumnListing($this->targetPasskeys)));
            $this->provider->table($this->targetPasskeys)->insert($key);
        }
        if ($disabled === []) {
            $this->provider->table('oauth_client_grants')->insert([
                'subject' => $id, 'oauth_client_id' => $this->clientId, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $this->host->table('local_identity_migrations')->insert([
            'user_id' => $id, 'provider' => $this->providerName, 'subject' => (string) $id,
            'client_id' => $this->clientId, 'target_fingerprint' => $this->targetFingerprint,
            'source_fingerprint' => $fingerprint, 'passkeys_copied' => count($passkeys),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->host->table('users')->where('id', $id)->update(['oauth_provider' => $this->providerName, 'oauth_subject' => (string) $id]);
        $this->provider->table($this->targetAudit)->insert([
            'user_id' => $id, 'event' => 'local_identity_imported', 'auth_method' => 'console',
            'succeeded' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /**
     * @param  list<string>  $disabled
     * @return array{user_id: int, status: string, reason: string, passkeys: int, disabled_reasons: list<string>}
     */
    private function result(int $id, string $status, string $reason, int $passkeys = 0, array $disabled = []): array
    {
        return ['user_id' => $id, 'status' => $status, 'reason' => $reason, 'passkeys' => $passkeys, 'disabled_reasons' => $disabled];
    }
}
