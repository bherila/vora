# K1 Flow Copilot Instructions

- **Architecture**: Laravel 12 API + Blade shell + React 19/TypeScript via Vite. Blade routes in [routes/web.php](routes/web.php) return minimal views that mount React roots with `data-*` props (see [resources/views](resources/views)). React entrypoints are configured in [vite.config.ts](vite.config.ts) with alias `@` → `resources/js` and shadcn/Tailwind v4 enabled.
- **Core domain models** (all under [app/Models/K1](app/Models/K1)): `K1Company` (companies + `k1Forms`, ownership edges), `K1Form` (full K-1 box fields, decimal/boolean casts, `incomeSources`), `OwnershipInterest` (links owner↔owned, inception basis fields), `OutsideBasis` + `ObAdjustment` (increase/decrease codes), `LossLimitation`, `LossCarryforward`, `K1IncomeSource`. Dates use [SerializesDatesAsLocal](app/Traits/SerializesDatesAsLocal.php) to avoid TZ shifts when serialized to JS.
- **API surface**: Defined in [routes/api.php](routes/api.php). REST-ish CRUD for companies and forms (`/api/companies/{company}/forms`), income sources on forms, ownership interests, outside basis, and loss limitations/carryforwards. Adjustments are added via `/ownership-interests/{id}/basis/{year}/adjustments` and updated/deleted via `/adjustments/{id}`; carryforwards via `/carryforwards/{id}`.
- **Basis & loss calculations**: [OutsideBasisController](app/Http/Controllers/K1/OutsideBasisController.php) builds a year-by-year basis walk from inception, summing increases/decreases and carrying forward ending basis. `ObAdjustment` holds canonical increase/decrease codes. [LossLimitationController](app/Http/Controllers/K1/LossLimitationController.php) first-or-creates per-year records and manages loss carryforwards.
- **PDF import**: [K1FormController](app/Http/Controllers/K1/K1FormController.php) can `extractFromPdf` using Gemini 2.5 Flash; requires `GEMINI_API_KEY` in `config('services.gemini')`. PDFs are stored on the `private` disk; old uploads are cleaned before replace.
- **Frontend patterns**: Each page script reads mount element attributes then `createRoot` renders the TSX component (e.g., [resources/js/company.tsx](resources/js/company.tsx), [resources/js/k1-form.tsx](resources/js/k1-form.tsx), [resources/js/ownership-interest.tsx](resources/js/ownership-interest.tsx)). Data fetching goes through [resources/js/fetchWrapper.ts](resources/js/fetchWrapper.ts) (includes CSRF meta header, `credentials: include`, JSON parsing fallback to text).
- **Key UI flows**: 
  - Home ([resources/js/home.tsx](resources/js/home.tsx)) shows `CompanyList` and `OwnershipGraph` (mermaid) fed by `/api/companies` and `/api/ownership-interests`.
  - [CompanyDetail](resources/js/components/k1/CompanyDetail.tsx) loads company, forms, and ownership edges; creates forms then redirects to `/company/{id}/k1/{form}`; adds ownership edges from available companies.
  - [K1FormDetail](resources/js/components/k1/K1FormDetail.tsx) uses refs + `pendingChangesRef` for auto-save-on-blur of individual fields, plus a "Save All" that PUTs the whole payload. PDF upload/extract merges AI output into the ref and marks pending fields. Tabs mirror Part I/II/III.
  - [OwnershipInterestDetail](resources/js/components/k1/OwnershipInterestDetail.tsx) shows inception basis, tabs for basis walk, loss limits, carryforwards; saves fields individually (PUT) and refreshes basis walk when inception values change. [BasisWalk](resources/js/components/k1/BasisWalk.tsx) renders summary with hover previews and deep links to year adjustments. [OwnershipBasisDetail](resources/js/components/k1/OwnershipBasisDetail.tsx) edits yearly adjustments, recalculates totals, and supports ending basis overrides and notes.
- **Mounting data contract**: Blade views pass IDs via `data-*` (examples in [resources/views/company.blade.php](resources/views/company.blade.php) and [resources/views/ownership-interest.blade.php](resources/views/ownership-interest.blade.php)); ensure new pages keep this pattern so scripts can read integers and hydrate.
- **UI/formatting conventions**: Money/percent helpers live in [resources/js/lib/currency.ts](resources/js/lib/currency.ts). UI uses shadcn/Radix components under `resources/js/components/ui`. Mermaids use loose security level to allow click-through to company routes.
- **Build/test workflow**: Install with `composer install && pnpm install`; copy `.env` then `php artisan key:generate` and migrate. Dev: `composer dev` (spawns artisan serve, queue listener, pail logs, Vite via `npx concurrently`) or run `php artisan serve` and `pnpm dev` separately. Tests: `composer test` (PHPUnit) and `pnpm test` (Jest via ts-jest). Build: `pnpm build` (Vite) with inputs from [vite.config.ts](vite.config.ts).
- **PHP Testing Safety**: Tests ALWAYS use SQLite in-memory database, never MySQL. This is enforced by:
  1. `phpunit.xml` sets `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`
  2. `Tests\SafeTestCase` (extended by `Tests\TestCase`) verifies SQLite is active at runtime and throws a RuntimeException if MySQL or another database is detected
  
  When writing tests:
  - Feature tests should extend `Tests\TestCase` (which extends `SafeTestCase`)
  - Use `RefreshDatabase` trait freely - it only affects SQLite in-memory
  - Unit tests can extend `PHPUnit\Framework\TestCase` directly (no database access)
  - Run tests with `composer test` or `php artisan test`
  
  Example feature test:
  ```php
  namespace Tests\Feature;
  
  use Illuminate\Foundation\Testing\RefreshDatabase;
  use Tests\TestCase;
  
  class MyTest extends TestCase
  {
      use RefreshDatabase;
      
      public function test_example(): void
      {
          // Safe to use database - always SQLite in-memory
          $user = User::factory()->create();
          $this->actingAs($user)->get('/dashboard')->assertOk();
      }
  }
  ```
- **Storage/infra**: File storage service for S3 in [app/Services/FileStorageService.php](app/Services/FileStorageService.php) and helper trait [app/Traits/HasFileStorage.php](app/Traits/HasFileStorage.php); K-1 PDFs currently use the `private` disk by default.
- **Path aliases & TS**: Paths configured in [tsconfig.json](tsconfig.json) (`@/*` → `resources/js/*`); TS includes `tests-ts` for frontend unit tests.
- **When extending**: Keep DECIMAL fields as strings/precise numbers in APIs, preserve `credentials: include` in fetches for session + CSRF, and prefer first-or-create patterns used in controllers for per-year resources.
