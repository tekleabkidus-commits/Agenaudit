<?php

namespace App\Services\Dashboard;

use Carbon\CarbonImmutable;

readonly class DateRange
{
    public function __construct(public CarbonImmutable $start, public CarbonImmutable $end, public string $label) {}

    public static function fromPreset(string $preset, ?string $from = null, ?string $to = null): self
    {
        $now = CarbonImmutable::now(config('app.timezone'));
        return match ($preset) {
            'last_24_hours' => new self($now->subDay(), $now, 'Last 24 Hours'),
            'yesterday' => new self($now->subDay()->startOfDay(), $now->subDay()->endOfDay(), 'Yesterday'),
            'this_week' => new self($now->startOfWeek(), $now, 'This Week'),
            'last_week' => new self($now->subWeek()->startOfWeek(), $now->subWeek()->endOfWeek(), 'Last Week'),
            'this_month' => new self($now->startOfMonth(), $now, 'This Month'),
            'last_month' => new self($now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth(), 'Last Month'),
            'last_3_months' => new self($now->subMonths(3), $now, 'Last 3 Months'),
            'custom' => new self(CarbonImmutable::parse($from)->startOfDay(), CarbonImmutable::parse($to)->endOfDay(), 'Custom'),
            default => new self($now->startOfDay(), $now, 'Today'),
        };
    }
}
