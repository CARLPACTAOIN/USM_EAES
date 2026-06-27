<?php

namespace App\Support;

use App\Models\Event;
use App\Models\EventDay;
use Carbon\Carbon;

class EvaluationWindow
{
    public static function finalEventDay(Event $event): ?EventDay
    {
        $event->loadMissing('eventDays');

        return $event->eventDays
            ->sortByDesc(fn (EventDay $day): string => sprintf(
                '%s %s',
                $day->date?->format('Y-m-d') ?? '',
                $day->end_time ?? '00:00:00'
            ))
            ->first();
    }

    public static function closesAt(Event $event): ?Carbon
    {
        $finalDay = self::finalEventDay($event);

        if (!$finalDay) {
            return null;
        }

        $endTime = $finalDay->end_time ?: '23:59:59';
        $closedAt = Carbon::parse($finalDay->date->toDateString() . ' ' . $endTime);

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
