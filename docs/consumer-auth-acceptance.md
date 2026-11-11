# Provider account-management acceptance

Vora keeps application accounts, approval, age gates, moderation, invitations,
preferences and historical records. In external or embedded sign-in mode, the
provider owns credentials and identity. Use this guide with
[auth modes](auth-modes.md) and the provider's
[consumer cutover procedure](https://github.com/bherila/auth-manager/blob/main/docs/consumer-account-cutover.md).
Code availability, reviewed schema updates, feature activation and live account
cutover are separate steps. None of the defaults below activates a live change.

## Control inventory

| Control | Vora behavior under provider sign-in |
| --- | --- |
| Password, passkeys, recovery and email identity | Settings links to the provider's `/settings`; ordinary local credential routes are unavailable and local email mutations are rejected. |
| Account binding | Configured provider plus case-sensitive subject. A matching email never adopts a local account. |
| Name and display name | Application-owned names are retained for existing accounts; sign-in refreshes the provider email projection subject to local uniqueness. |
| Approval, ages, local disable/ban/deactivation, storage and invitations | Continue to use Vora's local policies. Provider identity and grants do not override them. |
| Existing browser sessions and application JSON APIs | Same Vora web/session lane, with optional configured provider status enforcement and fresh checks before administrator writes. |
| Host API/MCP bearer credentials | Vora currently has no host OAuth authorization server, API-token model or MCP endpoint. Shared-package bearer tests do not establish a Vora credential lane. |
| Account retirement and historical attribution | Existing Vora deletion service and provider tombstone handler; held and protected accounts remain pending without a false acknowledgement. |
| Delegated access | Only the application-administrator projection described below. No copied provider directory or fabricated workspace permissions. |

Provider status enforcement remains opt-in through
`BHERILA_AUTH_PROVIDER_IDENTITY_ENABLED`. Verify the configured status endpoint
before enabling it. Provider expiry alone does not instantly terminate an
independent Vora session; see [synthetic test accounts](auth-modes.md#embedded).

## Designated emergency administrator

Emergency access is a deliberate exception for an existing, explicitly
designated, unbound local administrator. It is not an automatic response to a
provider outage. Configure it privately before retiring ordinary local access:

```dotenv
AUTH_EMERGENCY_ACCESS_ENABLED=false
AUTH_EMERGENCY_ACCESS_USER_IDS=
```

The list identifies local account IDs, not emails or provider subjects. An
operator must separately provision the local account and credentials through
an authorized process. The feature never creates an account, grants a role or
converts a provider-bound person. Keep real identifiers out of public examples.

When intentionally enabled in external or embedded mode, use the explicit
`/emergency-login` entry. Normal `/login` continues to use the provider. The
emergency flow retains the shared password preflight, throttling, audit,
CSRF and emailed second factor. Keep the host mailer working and verify delivery
during the outage rehearsal. Possession of a local password or a remembered
browser session alone is insufficient.

The selected person must still be an eligible local administrator and both
provider binding columns must be null. Recheck designation and eligibility
during the password step, pending second factor and subsequent requests. Removing
the designation, disabling the feature, demoting or binding the account ends
the exception. Emergency sign-out must not depend on a provider response.
Ordinary local accounts and provider-owned accounts never gain this path.

Rehearse with isolated accounts while the provider transport is unavailable.
Record the exact tested revision and private operational recovery procedure;
do not treat an enabled switch as evidence of a successful emergency sign-in.

## Delegated application administrator management

The shared `bherila/auth-laravel` contract-version-3 endpoint handles actor
assertions, trusted configuration, nonces, request bounds and durable operation
receipts. Vora's adapter owns local authorization and changes. A valid provider
signature proves the actor's identity, not their Vora administrator permission.

Keep both consumer switches off until the schema and configured read path pass:

```dotenv
DELEGATED_ACCESS_ENABLED=false
DELEGATED_ACCESS_WRITES_ENABLED=false
DELEGATED_ACCESS_ISSUER=https://identity.example.test
DELEGATED_ACCESS_ENDPOINT=https://app.example.test/application-access
DELEGATED_ACCESS_APPLICATION=example-app
DELEGATED_ACCESS_PUBLIC_KEYS=example-app-key|/private/example-app.pub.pem
```

Set `OAUTH_PROVIDER` explicitly to the same provider namespace used by local
bindings. Pin the exact configured issuer, endpoint and this application's own
public key. The key must be dedicated to this integration; never reuse OAuth
keys, the provider's instance-wide fallback key or another application's key.
For an embedded provider, use its exact HTTPS issuer including the configured
mount, such as `https://app.example.test/auth`, on both sides and in the sign-in
base URL. The adopted provider `0.1.6` and shared PHP `0.28.7` support that exact
issuer; a different origin or mount is refused. Do not remove the mount to bypass
a configuration error. Do not obtain trusted destinations or keys from browser
input or OAuth metadata.

The nonce and receipt migrations are part of the reviewed host schema update.
Leave them installed during rollback. The host scheduler runs
`bherila-auth:prune-delegated-nonces` daily, even when the endpoint is later
disabled, to clear expired nonces and receipts older than 30 days. Every worker
must share durable storage; provider-context cleanup must not replace host cleanup.
Do not manually run schema commands against a live instance as a verification
shortcut.

The advertised capabilities are deliberately narrow:

- Existing eligible Vora administrators can manage the administrator flag for
  accounts bound to the configured provider, subject to protected-administrator
  and stale-revision checks.
- No workspace roles or provisioning are advertised. A provider grant does not
  create a Vora account, supply its email/age/approval requirements or grant an
  application role.
- Removing delegated access removes only the advertised administrator role.
  The account, ordinary sign-in, approval, moderation, Library/Event access and
  historical records remain. This is not account deletion or a promise to
  revoke all Vora access.

Local changes recheck actor authority and target state under a transaction and
commit the application audit with the role change. Unsupported controls and
foreign-provider subjects are refused. A stale revision requires a new read;
an uncertain write requires receipt/canonical-state reconciliation rather than
an automatic second mutation.

Complete the provider's
[per-application setup and verification](https://github.com/bherila/auth-manager/blob/v0.1.6/docs/connecting-an-application.md)
separately: its registry, explicit client grant, access-management roles,
per-application signing key and write switches remain provider-owned. Verify
reads before enabling writes on either side. A provider-only administrator must
remain unable to manage Vora roles.

## Acceptance and rollback evidence

Repository evidence is kept in the following focused tests, in addition to the
existing sign-in, account and deletion suites:

| Test | Scope |
| --- | --- |
| `EmergencyAccessTest` | Shared password/MFA, explicit designation, fresh local eligibility, no ordinary/remember-cookie bypass, CSRF/throttle, native forms, suspicious-email reporting and outage-independent logout. |
| `DelegatedApplicationAccessTest` | Shared adapter conformance, actual signed endpoint and durable receipt replay, role/scope/revision safeguards, disclosed removal and transactional audit failures. |
| `EmbeddedDelegatedApplicationAccessTest` | Host/provider account and context separation with actual mounted-issuer verification. |
| `EmbeddedDelegationRoundTripTest` | Actual provider login and recent password confirmation, per-application roles/grant/key, signed transport and Vora adapter mutation; independent host authority, replay and wrong-issuer refusal. |
| `EmbeddedDelegatedPruningTest` | Daily host scheduler registration and actual host receipt cleanup while the endpoint is disabled; provider receipts remain untouched. |
| `ProviderConsumerLifecycleAcceptanceTest` | Real provider PKCE sign-in, token/identity bootstrap, password change/reset/disable/delete at 299/300 seconds, fresh administrator checks, retryable outages, actual deletion/feed/acknowledgement and committed local cleanup. |

The lifecycle suite runs actual registered provider route middleware and
controllers through a test-only in-process HTTP transport while Vora requests
use its HTTP kernel. It does not run a deployed network or browser. The
delegated roundtrip also runs real provider controllers and the shared host
endpoint, with an in-process network hop that restores each realm. Its nonce
store is an in-memory test fixture: it tests assertion replay, not durable
production nonce storage. The pruning fixture exercises actual receipt cleanup
but skips nonce deletion because the production store correctly refuses SQLite
`:memory:` as non-durable. The emergency fixture captures mail; it does not prove
remote mail delivery.
Provider and shared-package fixtures remain responsible for reusable protocol
failure cases and synthetic workspace adapters.

Track configured-instance checks separately in
[#301](https://github.com/bherila/vora/issues/301). Keep them open until the
operator records actual configured results; no test fixture enables the
corresponding live switch.

Run focused SQLite tests locally and let exact-candidate CI run the full backend,
isolated MariaDB, frontend and browser gates required by the change. Preserve
the distinction between actual provider-handler tests, browser journeys and
deployed behavior. Neither a synthetic fixture nor green deployment health
proves a live directory or consumer switch has been cut over.

Before a live change, record sign-in/security navigation, immutable generation
and the 300-second boundary, fresh privileged checks, retryable outages,
password change/reset/disable/delete, cascade-before-acknowledgement and the
emergency path. Keep private hostnames, accounts, keys and deployment assignments
in operational records. Interactive RP-specific passkey enrollment and actual
recovery delivery remain operator checks when not exercised by the recorded test.

To pause delegated edits, turn off writes before disabling the endpoint. A
navigation rollback does not alter subject bindings or restore deleted records.
Restore a coherent reviewed auth configuration without exposing ordinary local
credentials for provider-owned people. Preserve nonce/receipt tables, provider
generation enforcement and historical attribution. Rehearse emergency access
through rollback, then revoke it explicitly when no longer required.
