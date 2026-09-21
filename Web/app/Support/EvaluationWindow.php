<?php

namespace App\Support;

use App\Models\Event;
use App\Models\EventDay;
use App\Services\EventSessionScheduleService;
use Carbon\Carbon;

class EvaluationWindow
{
    public static function finalEventDay(Event $event): ?EventDay
    {
        $event->loadMissing('eventDays.sessions');
        $sessionSchedules = app(EventSessionScheduleService::class);

        return $event->eventDays
            ->sortByDesc(fn (EventDay $day): string => sprintf(
                '%s %s',
                $day->date?->format('Y-m-d') ?? '',
                $sessionSchedules->sessionsForDay($day)->max('end_time') ?? $day->end_time ?? '00:00:00'
            ))
            ->first();
    }

    public static function closesAt(Event $event): ?Carbon
    {
        $finalDay = self::finalEventDay($event);

        if (! $finalDay) {
            return null;
        }

        $sessionSchedules = app(EventSessionScheduleService::class);
        $endTime = $sessionSchedules->sessionsForDay($finalDay)->max('end_time') ?: $finalDay->end_time ?: '23:59:59';
        $closedAt = Carbon::parse($finalDay->date->toDateString().' '.$endTime);

        return $closedAt->addHours((int) config('services.eaes.evaluation_window_hours', 24));
    }

    public static function isClosed(Event $event, ?Carbon $now = null): bool
    {
        $closedAt = self::closesAt($event);

        return $closedAt !== null && ($now ?? now())->gt($closedAt);
    }

    public static function isOpen(Event $event, ?Carbon $now = null): bool
    {
        $closedAt = self::closesAt($event);

        return $closedAt !== null && ($now ?? now())->lte($closedAt);
    }
}
