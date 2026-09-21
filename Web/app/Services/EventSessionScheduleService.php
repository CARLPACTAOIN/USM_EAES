<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventDaySession;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Collection;

class EventSessionScheduleService
{
    /**
     * @param  list<array<string, mixed>>  $eventDays
     * @return list<array<string, mixed>>
     */
    public function normalizeEventDays(array $eventDays): array
    {
        return collect($eventDays)
            ->values()
            ->map(function (array $dayData, int $dayIndex): array {
                $sessions = $this->normalizeSessionsForDay($dayData, $dayIndex);
                $this->assertSessionsFitDay($dayData, $sessions, $dayIndex);
                $this->assertSessionsDoNotOverlap($sessions, $dayIndex);

                return [
                    ...$dayData,
                    'sessions' => $sessions,
                ];
            })
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $sessions
     */
    public function replaceSessions(EventDay $eventDay, array $sessions): void
    {
        $eventDay->sessions()->delete();

        foreach ($sessions as $index => $sessionData) {
            EventDaySession::create([
                'event_day_id' => $eventDay->id,
                'session_number' => $index + 1,
                'name' => filled($sessionData['name'] ?? null) ? (string) $sessionData['name'] : 'Session '.($index + 1),
                'start_time' => $sessionData['start_time'],
                'end_time' => $sessionData['end_time'],
                'required_for_attendance' => (bool) ($sessionData['required_for_attendance'] ?? true),
            ]);
        }
    }

    /**
     * @return array{day: EventDay, session: EventDaySession, window_start: Carbon, window_end: Carbon}|null
     */
    public function resolveSessionForScan(Event $event, Carbon $scannedAt, string $scanType): ?array
    {
        $event->loadMissing('eventDays.sessions');

        $candidates = $event->eventDays
            ->flatMap(fn (EventDay $day): Collection => $this->sessionsForDay($day)
                ->map(fn (EventDaySession $session): array => [
                    'day' => $day,
                    'session' => $session,
                    ...$this->scanWindowForSession($day, $session, $scanType),
                ]))
            ->filter(fn (array $candidate): bool => $scannedAt->betweenIncluded($candidate['window_start'], $candidate['window_end']))
            ->values();

        if ($candidates->isEmpty()) {
            return null;
        }

        if ($scanType === 'time_in') {
            return $candidates
                ->sortBy(function (array $candidate) use ($scannedAt): int {
                    $sessionStart = $this->sessionDateTime($candidate['day'], $candidate['session']->start_time);

                    return $sessionStart->gte($scannedAt)
                        ? $sessionStart->diffInSeconds($scannedAt)
                        : 86_400 + $sessionStart->diffInSeconds($scannedAt);
                })
                ->first();
        }

        return $candidates
            ->sortBy(function (array $candidate) use ($scannedAt): int {
                $sessionEnd = $this->sessionDateTime($candidate['day'], $candidate['session']->end_time);

                return $sessionEnd->lte($scannedAt)
                    ? $sessionEnd->diffInSeconds($scannedAt)
                    : 86_400 + $sessionEnd->diffInSeconds($scannedAt);
            })
            ->first();
    }

    /**
     * @return Collection<int, EventDaySession>
     */
    public function sessionsForDay(EventDay $day): Collection
    {
        $sessions = $day->relationLoaded('sessions')
            ? $day->sessions
            : $day->sessions()->get();

        if ($sessions->isNotEmpty()) {
            return $sessions->sortBy('session_number')->values();
        }

        return collect([
            new EventDaySession([
                'event_day_id' => $day->id,
                'session_number' => 1,
                'name' => 'Session 1',
                'start_time' => $day->start_time,
                'end_time' => $day->end_time,
                'required_for_attendance' => true,
            ]),
        ]);
    }

    public function sessionDateTime(EventDay $day, string $time): Carbon
    {
        return Carbon::parse(
            $day->date->toDateString().' '.$time,
            config('services.eaes.event_timezone', 'Asia/Manila')
        );
    }

    /**
     * @return array{window_start: Carbon, window_end: Carbon}
     */
    public function scanWindowForSession(EventDay $day, EventDaySession $session, string $scanType): array
    {
        $earlyCheckinMinutes = (int) config('services.eaes.scan_early_checkin_buffer_min', 60);
        $checkoutGraceMinutes = (int) config('services.eaes.checkout_grace_period_min', 60);
        $sessionStart = $this->sessionDateTime($day, $session->start_time);
        $sessionEnd = $this->sessionDateTime($day, $session->end_time);

        if ($scanType === 'time_in') {
            return [
                'window_start' => $sessionStart->copy()->subMinutes($earlyCheckinMinutes),
                'window_end' => $sessionEnd,
            ];
        }

        return [
            'window_start' => $sessionStart,
            'window_end' => $sessionEnd->copy()->addMinutes($checkoutGraceMinutes),
        ];
    }

    /**
     * @param  array<string, mixed>  $dayData
     * @return list<array{name: string, start_time: string, end_time: string, required_for_attendance: bool}>
     */
    private function normalizeSessionsForDay(array $dayData, int $dayIndex): array
    {
        $sessions = $dayData['sessions'] ?? [];

        if (! is_array($sessions) || $sessions === []) {
            $sessions = [[
                'name' => 'Session 1',
                'start_time' => $dayData['start_time'] ?? null,
                'end_time' => $dayData['end_time'] ?? null,
                'required_for_attendance' => true,
            ]];
        }

        return collect($sessions)
            ->values()
            ->map(function (array $session, int $sessionIndex) use ($dayIndex): array {
                if (blank($session['start_time'] ?? null) || blank($session['end_time'] ?? null)) {
                    throw new DomainException('Day '.($dayIndex + 1).' session '.($sessionIndex + 1).' must have a start and end time.');
                }

                if ($this->minutes((string) $session['start_time']) >= $this->minutes((string) $session['end_time'])) {
                    throw new DomainException('Day '.($dayIndex + 1).' session '.($sessionIndex + 1).' must end after it starts.');
                }

                return [
                    'name' => filled($session['name'] ?? null) ? (string) $session['name'] : 'Session '.($sessionIndex + 1),
                    'start_time' => (string) $session['start_time'],
                    'end_time' => (string) $session['end_time'],
                    'required_for_attendance' => (bool) ($session['required_for_attendance'] ?? true),
                ];
            })
            ->sortBy('start_time')
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $sessions
     */
    private function assertSessionsFitDay(array $dayData, array $sessions, int $dayIndex): void
    {
        $dayStart = $this->minutes((string) ($dayData['start_time'] ?? '00:00'));
        $dayEnd = $this->minutes((string) ($dayData['end_time'] ?? '00:00'));

        foreach ($sessions as $sessionIndex => $session) {
            $sessionStart = $this->minutes($session['start_time']);
            $sessionEnd = $this->minutes($session['end_time']);

            if ($sessionStart < $dayStart || $sessionEnd > $dayEnd) {
                throw new DomainException('Day '.($dayIndex + 1).' session '.($sessionIndex + 1).' must stay within the event day time range.');
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $sessions
     */
    private function assertSessionsDoNotOverlap(array $sessions, int $dayIndex): void
    {
        $previousEnd = null;

        foreach ($sessions as $sessionIndex => $session) {
            $start = $this->minutes($session['start_time']);

            if ($previousEnd !== null && $start < $previousEnd) {
                throw new DomainException('Day '.($dayIndex + 1).' sessions must not overlap. Back-to-back sessions and gaps are allowed.');
            }

            $previousEnd = $this->minutes($session['end_time']);
        }
    }

    private function minutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return ($hour * 60) + $minute;
    }
}
