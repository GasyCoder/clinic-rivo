<?php

namespace App\Enums;

enum LeaveDayCountMethod: string
{
    case CalendarDaysInclusive = 'CALENDAR_DAYS_INCLUSIVE';
    case WeekdaysInclusive = 'WEEKDAYS_INCLUSIVE';

    public function label(): string
    {
        return match ($this) {
            self::CalendarDaysInclusive => 'Tous les jours calendaires, dates incluses',
            self::WeekdaysInclusive => 'Du lundi au vendredi, dates incluses',
        };
    }
}
