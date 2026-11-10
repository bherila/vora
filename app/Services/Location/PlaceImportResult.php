<?php

namespace App\Services\Location;

/**
 * What an import did, or for a dry run what it would do.
 */
final readonly class PlaceImportResult
{
    public function __construct(
        public bool $dryRun,
        public string $mode,
        public string $dataset,
        public int $parsed,
        public int $added,
        public int $updated,
        public int $removed,
        public int $keptInUse,
        public int $rehomed,
        public int $accountsAffected,
        public int $regionsRemoved,
        public int $countriesRemoved,
        public bool $pruneSkipped,
    ) {}

    /**
     * @return array<string, bool|int|string>
     */
    public function toArray(): array
    {
        return [
            'dry_run' => $this->dryRun,
            'mode' => $this->mode,
            'dataset' => $this->dataset,
            'parsed' => $this->parsed,
            'added' => $this->added,
            'updated' => $this->updated,
            'removed' => $this->removed,
            'kept_in_use' => $this->keptInUse,
            'rehomed' => $this->rehomed,
            'accounts_affected' => $this->accountsAffected,
            'regions_removed' => $this->regionsRemoved,
            'countries_removed' => $this->countriesRemoved,
            'prune_skipped' => $this->pruneSkipped,
        ];
    }
}
