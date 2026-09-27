<?php

namespace App\Services;

use App\Models\BusinessCalendarHoliday;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;

class BusinessCalendarService
{
    public function workingDays(): array
    {
        $value = getWebConfig(name: 'commerce_working_days');
        $days = is_array($value) ? $value : json_decode((string) ($value ?: '[0,1,2,3,4]'), true);
        $days = array_values(array_unique(array_filter(array_map('intval', (array) $days), fn (int $day) => $day >= 0 && $day <= 6)));

        return $days !== [] ? $days : [0, 1, 2, 3, 4];
    }

    public function holidayDates(): array
    {
        if (! Schema::hasTable('business_calendar_holidays')) {
            return [];
        }

        return BusinessCalendarHoliday::query()
            ->where('active', true)
            ->pluck('holiday_date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->all();
    }

    public function isBusinessDay(CarbonInterface $date): bool
    {
        return in_array($date->dayOfWeek, $this->workingDays(), true)
            && ! in_array($date->toDateString(), $this->holidayDates(), true);
    }

    public function addBusinessDays(CarbonInterface $from, int $days): Carbon
    {
        $date = Carbon::parse($from)->startOfDay();
        $remaining = max(0, $days);

        while ($remaining > 0) {
            $date->addDay();
            if ($this->isBusinessDay($date)) {
                $remaining--;
            }
        }

        return $date;
    }

    public function snapshot(): array
    {
        return [
            'working_days' => $this->workingDays(),
            'holidays' => $this->holidayDates(),
            'timezone' => config('app.timezone', 'Africa/Cairo'),
            'captured_at' => now()->toISOString(),
        ];
    }
}
