<?php

namespace Database\Seeders;

use App\Models\StaticPage;
use App\Models\User;
use App\Services\SettingsService;
use App\Support\DefaultStaticPages;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        foreach (DefaultStaticPages::all() as $page) {
            StaticPage::query()->updateOrCreate(
                ['slug' => $page['slug']],
                array_merge($page, ['variables' => json_encode($page['variables'], JSON_THROW_ON_ERROR)])
            );
        }

        // Default signup settings (public signups open, no auto-granted invites).
        $settings = app(SettingsService::class);
        $settings->set(SettingsService::PUBLIC_SIGNUPS_ENABLED, true);
        $settings->set(SettingsService::DEFAULT_NEW_USER_INVITES, 0);
    }
}
