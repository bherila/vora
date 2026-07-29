<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AssertPersonaSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_succeeds_against_the_fresh_install_schema(): void
    {
        $this->artisan('schema:assert-persona')
            ->expectsOutput('Required persona schema is present.')
            ->assertSuccessful();
    }

    public function test_it_fails_and_names_every_missing_model_required_persona_column(): void
    {
        Schema::shouldReceive('hasColumn')
            ->once()
            ->with('characters', 'ulid')
            ->andReturnFalse();
        Schema::shouldReceive('hasColumn')
            ->once()
            ->with('characters', 'is_linked')
            ->andReturnTrue();
        Schema::shouldReceive('hasColumn')
            ->once()
            ->with('story_authors', 'character_id')
            ->andReturnFalse();

        $this->artisan('schema:assert-persona')
            ->expectsOutput('Required persona schema is incomplete:')
            ->expectsOutput(' - characters.ulid')
            ->expectsOutput(' - story_authors.character_id')
            ->assertFailed();
    }

    public function test_production_deploy_asserts_persona_schema_before_refreshing_web_roots(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/deploy.yml'));

        $this->assertIsString($workflow);

        $migration = strpos($workflow, 'artisan migrate --force --no-interaction');
        $assertion = strpos($workflow, 'artisan schema:assert-persona');
        $webRootRefresh = strpos($workflow, 'ln -s laravel/public public_html');

        $this->assertNotFalse($migration, 'The deploy must run migrations.');
        $this->assertNotFalse($assertion, 'The deploy must assert the persona schema.');
        $this->assertNotFalse($webRootRefresh, 'The deploy must refresh the public web roots.');
        $this->assertLessThan($assertion, $migration, 'The schema assertion must run after migrations.');
        $this->assertLessThan($webRootRefresh, $assertion, 'The schema assertion must run before web roots are refreshed.');
    }
}
