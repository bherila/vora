<?php

namespace Database\Factories;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Models\Media;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    protected $model = Report::class;

    public function definition(): array
    {
        return [
            'reporter_user_id' => User::factory(),
            'reportable_type' => (new Media)->getMorphClass(),
            'reportable_id' => Media::factory(),
            'reason' => ReportReason::Harassment,
            'details' => fake()->optional()->sentence(),
            'status' => ReportStatus::Open,
        ];
    }

    /**
     * Point the report at an existing reportable model.
     */
    public function targeting(object $reportable): static
    {
        return $this->state([
            'reportable_type' => $reportable->getMorphClass(),
            'reportable_id' => $reportable->getKey(),
        ]);
    }
}
