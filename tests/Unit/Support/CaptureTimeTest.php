<?php

namespace Tests\Unit\Support;

use App\Support\CaptureTime;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class CaptureTimeTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_offsets_win_and_wall_clock_times_use_the_event_time_zone(): void
    {
        $this->assertSame('2026-10-25T00:32:05+00:00', CaptureTime::parse('2026-10-24T17:32:05-07:00', 'Europe/London')?->toIso8601String());
        $this->assertSame('2026-10-24T17:32:05+00:00', CaptureTime::parse('2026-10-24T17:32:05Z', 'America/New_York')?->toIso8601String());
        $this->assertSame('2026-10-25T00:32:05+00:00', CaptureTime::parse('2026-10-24T17:32:05', 'America/Los_Angeles')?->toIso8601String());
        $this->assertSame('2026-10-24T17:32:05+00:00', CaptureTime::parse('2026-10-24T17:32:05.250', 'UTC')?->toIso8601String());
    }

    public function test_malformed_impossible_and_implausible_times_are_dropped(): void
    {
        CarbonImmutable::setTestNow('2026-10-26T12:00:00Z');
        foreach ([null, '', '2026:10:24 17:32:05', '2026-02-30T10:00:00', '2026-10-24T25:00:00', '1989-12-31T23:59:59Z', '2026-10-28T12:00:00Z', '2026-10-24T17:32:05+99:00'] as $value) {
            $this->assertNull(CaptureTime::parse($value, 'UTC'), (string) $value);
        }
        $this->assertNull(CaptureTime::parse('2026-10-24T17:32:05', 'Not/AZone'));
        $this->assertNotNull(CaptureTime::parse('2026-10-27T11:00:00Z', 'UTC'));
    }
}
