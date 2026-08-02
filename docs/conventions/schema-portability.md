# Schema and query portability

Vora runs on **SQLite** (in-memory, every local and CI test run) and
**MariaDB 10.6** (production, plus the isolated CI `sql` job). Every migration
and query must work on both. A construct that works on one engine and silently
degrades on the other is a production incident waiting for a test suite that
cannot catch it.

## Migration safety

Read `AGENTS.md` first — it is binding. The short version:

- Never run `php artisan migrate` or `php artisan schema:dump` unless explicitly
  asked.
- When asked: `php artisan migrate --database=sqlite --no-interaction`.
- Never use `--prune`.
- Tests use SQLite in-memory. The only MariaDB target is the CI service
  container with its dedicated `vora_ci` database and credentials.

## Do not use partial or filtered unique indexes

`CREATE UNIQUE INDEX … WHERE …` is not portable. MariaDB has no equivalent, so a
partial index that guards an invariant on SQLite guards nothing in production.

**Use a nullable unique column instead.** Both engines permit multiple `NULL`s in
a unique index, which expresses "at most one" exactly:

```php
// "a user has at most one pending email-change token"
$table->string('pending_email_token')->nullable();
$table->unique('pending_email_token');
```

Rows that do not participate hold `NULL` and do not collide. Rows that do
participate are unique. No filtered index, no application-level guard, no engine
divergence.

### Check which direction you are constraining

A nullable foreign key already says "this row points at **at most one** parent" —
that is what a single-valued column means. Adding `unique()` to it does not
reinforce that; it asserts the *converse*, that no two rows may point at the same
parent. Two different statements, and the one you get for free is usually the one
you wanted.

`media.canonical_post_id` shipped with a unique index meant to express "a media
item has at most one canonical discussion post". The column said that already.
What `unique` added was "a post is canonical for at most one media item" — false
for a gallery post, which carries several media and is the canonical discussion
for all of them. Creating one raised an integrity violation and 500'd the
request. See #198.

Before reaching for `unique` on a foreign key, say the constraint out loud in the
*parent's* voice. If that sentence is not one you want to enforce, you want a
plain index.

Corollary: **do not model "at most one of N" with an `is_primary` boolean on a
pivot table.** "At most one true per group" is precisely the partial unique index
you cannot have. If the cardinality is genuinely one, use a single nullable
foreign key column on the parent and let the schema enforce it by construction.

## Cross-table invariants

There is no portable cross-table exclusion constraint. Where an invariant spans
tables, enforce what you can in the schema, enforce the rest in a single service,
and **write down in the migration or model docblock which part is only
service-enforced**. Do not leave a reader to discover it.

## Keyset pagination and indexes

Listings paginate by cursor (`cursorPaginate`), ordered `created_at DESC,
id DESC`. When you add an equality filter to a paginated listing, add a
**composite** index leading with the filtered column and continuing into the sort
columns:

```php
$table->index(['context_interest_id', 'created_at', 'id']);
```

A bare single-column index leaves MariaDB to filesort the page. The composite
lets the cursor walk the index directly.

See [privacy-and-visibility.md](privacy-and-visibility.md#filtering-happens-in-query-before-pagination)
for why the filter must be in-query in the first place.

## Foreign key delete behaviour is a product decision

`nullOnDelete`, `cascadeOnDelete`, and `restrictOnDelete` encode what should
happen to dependent rows, and the default is rarely the intended answer. Choose
deliberately and say why in the migration docblock.

Worked example: `post_comments.parent_id` originally used `nullOnDelete`, which
silently **promoted replies to top level** when their parent was deleted —
destroying the context the reply was written in. Once comments soft-delete, the
FK action only fires on an admin force-delete, where `cascadeOnDelete` is the
intended behaviour. The original choice was not wrong syntax; it was an
unexamined default that encoded the wrong product rule.

Note that soft deletes change when FK actions fire at all: `SoftDeletes` issues an
`UPDATE`, so the database-level action applies only on `forceDelete()`.

## Opaque public identifiers

Public routes and API payloads identify records by **ULID**, not autoincrement
id. ULIDs are unguessable, non-enumerable, and sortable. `posts.ulid` and
`media.ulid` are the pattern; add one before exposing a record on a new public
route.

Internal foreign keys stay integer. This is about what leaves the server, not how
rows relate.

## Engine-specific SQL

Do not introduce it without equivalent coverage on both engines. If a query needs
an engine-specific construct, it needs a portable fallback and a test that runs
against both, or a different query.

Both engines support recursive CTEs, which is usually the portable answer for
hierarchy traversal — prefer that over a denormalized ancestor table unless
profiling says otherwise.
