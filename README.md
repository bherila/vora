# Ben Herila's Skeleton Project

A web application.

## Features

- None yet

## Tech Stack

- **Backend**: Laravel 12 (PHP 8.1+)
- **Frontend**: React 19 with TypeScript
- **UI Components**: shadcn/ui + Radix UI primitives
- **Styling**: Tailwind CSS v4
- **Build**: Vite
- **Database**: MySQL/SQLite

## Getting Started

### Prerequisites

- PHP 8.1 or higher
- Composer
- Node.js 18+ and pnpm
- MySQL or SQLite

### Installation

1. **Clone the repository**
   ```bash
   git clone <repository-url>
   cd k1flow
   ```

2. **Install dependencies**
   ```bash
   composer install
   pnpm install
   ```

3. **Configure environment**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
   
   Update `.env` with your database credentials.

4. **Run migrations**
   ```bash
   php artisan migrate
   ```

5. **Build assets**
   ```bash
   pnpm run build
   ```

### Development

Run the development server:
```bash
# Start Laravel server and Vite dev server concurrently
pnpm run dev
php artisan serve
```

Or use multiple terminals:
- Terminal 1: `php artisan serve`
- Terminal 2: `pnpm run dev`

## Database Schema

### Core Tables

- None yet

## API Endpoints

- None yet

## Deployment

See [Deployment Instructions](#deployment-instructions) below for deploying to a cPanel-hosted Apache server.

### Deployment Instructions

1. **Upload Project Files**
   - Upload all project files to your server, excluding `node_modules/`, `vendor/`, and `.env`
   - Place files in a directory outside of `public_html`, e.g., `~/bwh-php/`

2. **Install Dependencies**
   ```bash
   cd ~/bwh-php
   composer install --no-dev --optimize-autoloader
   pnpm install
   pnpm run build
   ```

3. **Configure Environment**
   - Create `.env` and configure database, `APP_KEY`, and `APP_URL`

4. **Set Up Public Directory**
   - Copy `~/bwh-php/public/` contents to `~/public_html/`
   - Update `index.php` paths as needed

5. **Database Setup**
   ```bash
   php artisan migrate --force
   ```

6. **Set Permissions**
   ```bash
   chmod -R 775 storage bootstrap/cache
   ```

7. **Cache Configuration**
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```

## Testing

### Running Tests

```bash
# Run all PHP tests
composer test

# Or directly with artisan
php artisan test

# Run specific test file
php artisan test tests/Feature/ExampleTest.php

# Run with coverage (requires Xdebug)
php artisan test --coverage
```

### Database Safety

**Important:** Tests are configured to ALWAYS use SQLite in-memory database, never MySQL.

This is enforced at multiple levels:
1. `phpunit.xml` sets `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`
2. `Tests\SafeTestCase` verifies SQLite is active and throws an error if not

Even if your `.env` file contains MySQL credentials, tests will use SQLite. This prevents accidentally running tests against a production database.

### Writing Tests

**Feature Tests** (tests that need the Laravel application):
```php
namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyFeatureTest extends TestCase
{
    use RefreshDatabase; // Safe to use - only affects SQLite in-memory

    public function test_something(): void
    {
        // Your test code here
    }
}
```

**Unit Tests** (pure PHP tests without Laravel):
```php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class MyUnitTest extends TestCase
{
    public function test_something(): void
    {
        // Your test code here
    }
}
```

### Test Structure

```
tests/
├── Feature/           # Tests requiring full Laravel application
│   └── ExampleTest.php
├── Unit/              # Pure PHP unit tests
│   └── ExampleTest.php
├── SafeTestCase.php   # Base class enforcing SQLite safety
└── TestCase.php       # Base class for feature tests
```

## License

Private - All rights reserved
