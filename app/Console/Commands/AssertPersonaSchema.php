<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class AssertPersonaSchema extends Command
{
    protected $signature = 'schema:assert-persona';

    protected $description = 'Fail unless every database column required by the persona models exists';

    /**
     * Columns whose absence allows reads to degrade while making persona writes
     * fail. Keep this list aligned with Character and StoryAuthor.
     *
     * @var array<string, list<string>>
     */
    private const REQUIRED_COLUMNS = [
        'characters' => [
            'ulid',
            'is_linked',
        ],
        'story_authors' => [
            'character_id',
        ],
    ];

    public function handle(): int
    {
        $missing = [];

        foreach (self::REQUIRED_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $missing[] = "{$table}.{$column}";
                }
            }
        }

        if ($missing !== []) {
            $this->error('Required persona schema is incomplete:');

            foreach ($missing as $column) {
                $this->line(" - {$column}");
            }

            return self::FAILURE;
        }

        $this->info('Required persona schema is present.');

        return self::SUCCESS;
    }
}
