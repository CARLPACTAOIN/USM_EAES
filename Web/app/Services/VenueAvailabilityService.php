<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventDay;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class VenueAvailabilityService
{
    private const CONFIRMED_STATUSES = [
        Event::STATUS_APPROVED,
        Event::STATUS_COMPLETED,
    ];

    private const TENTATIVE_STATUSES = [
        Event::STATUS_SUBMITTED,
        Event::STATUS_UNDER_REVIEW,
    ];

    public function normalizeVenueName(?string $name): string
    {
        return Str::of((string) $name)->squish()->lower()->toString();
    }

    /**
     * @return array{summary: array{used: int, tentative: int, available: int}, venues: list<array<string, mixed>>, disclaimer: string}
     */
    public function availabilityFor(
        ?string $date,
        ?string $startTime = null,
        ?string $endTime = null,
        ?string $search = null,
        string $status = 'all',
        string $source = 'all',
        ?string $exceptEventId = null
    ): array {
        $date = $this->validDate($date);
        if (! $date) {
            return $this->emptyResult();
        }

        $timeWindow = $this->timeWindow($date, $startTime, $endTime);
        $catalogRows = $this->catalogRows();
        $usageRows = $this->usageRows($date, $timeWindow, $exceptEventId);
        $rows = $this->mergeRows($catalogRows, $usageRows);
        $rows = $this->filterRows($rows, $search, $status, $source);

        return [
            'summary' => [
                'used' => $rows->where('status', 'used')->count(),
                'tentative' => $rows->where('status', 'tentative')->count(),
                'available' => $rows->where('status', 'available')->count(),
            ],
            'venues' => $rows->values()->all(),
            'disclaimer' => $this->disclaimer(),
        ];
    }

    /**
     * @param  list<array{date?: string, start_time?: string, end_time?: string}>  $eventDays
     * @return array{confirmed: list<array<string, mixed>>, tentative: list<array<string, mixed>>, disclaimer: string}
     */
    public function warningsForVenueSchedule(string $venueName, array $eventDays, ?string $exceptEventId = null): array
    {
        $normalizedVenueName = $this->normalizeVenueName($venueName);
        if ($normalizedVenueName === '') {
            return ['confirmed' => [], 'tentative' => [], 'disclaimer' => $this->disclaimer()];
        }

        $confirmed = [];
        $tentative = [];

        foreach ($eventDays as $day) {
            $date = $this->validDate((string) ($day['date'] ?? ''));
            if (! $date) {
                continue;
            }

            $usageRows = $this->usageRows(
                $date,
                $this->timeWindow($date, (string) ($day['start_time'] ?? ''), (string) ($day['end_time'] ?? '')),
                $exceptEventId
            );

            foreach ($usageRows as $row) {
                if ($row['normalized_name'] !== $normalizedVenueName) {
                    continue;
                }

                foreach ($row['events'] as $event) {
                    if ($event['usage_status'] === 'used') {
                        $confirmed[] = $event;
                    } elseif ($event['usage_status'] === 'tentative') {
                        $tentative[] = $event;
                    }
                }
            }
        }

        return [
            'confirmed' => array_values($confirmed),
            'tentative' => array_values($tentative),
            'disclaimer' => $this->disclaimer(),
        ];
    }

    public function disclaimer(): string
    {
        return 'System-recorded usage only; confirm reservation with the managing office.';
    }

    /** @return Collection<int, array<string, mixed>> */
    private function catalogRows(): Collection
    {
        return Venue::query()
            ->with('college')
            ->orderBy('name')
            ->get()
            ->map(fn (Venue $venue): array => [
                'normalized_name' => $this->normalizeVenueName($venue->name),
                'name' => $venue->name,
                'display_name' => $venue->name,
                'source' => 'catalog',
                'status' => $venue->is_active ? 'available' : 'retired',
                'is_active' => (bool) $venue->is_active,
                'campus_location' => $venue->campus_location,
                'managing_office' => $venue->managing_office,
                'inquiry_instructions' => $venue->inquiry_instructions,
                'college' => $venue->college?->code,
                'events' => [],
            ])
            ->filter(fn (array $row): bool => $row['normalized_name'] !== '')
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function usageRows(Carbon $date, ?array $timeWindow, ?string $exceptEventId): Collection
    {
        return Event::query()
            ->with([
                'organization',
                'venue.college',
                'eventDays' => fn ($query) => $query->whereDate('date', $date->toDateString())->orderBy('day_number'),
            ])
            ->where('location_type', 'on-campus')
            ->when($exceptEventId, fn ($query) => $query->whereKeyNot($exceptEventId))
            ->whereIn('status', array_merge(self::CONFIRMED_STATUSES, self::TENTATIVE_STATUSES))
            ->whereHas('eventDays', fn ($query) => $query->whereDate('date', $date->toDateString()))
            ->orderBy('title')
            ->get()
            ->flatMap(function (Event $event) use ($date, $timeWindow): array {
                return $event->eventDays
                    ->filter(fn (EventDay $day): bool => $this->dayOverlaps($day, $date, $timeWindow, $this->usageStatus($event)))
                    ->map(fn (EventDay $day): array => $this->usageRow($event, $day, $date))
                    ->all();
            })
            ->filter(fn (array $row): bool => $row['normalized_name'] !== '')
            ->groupBy('normalized_name')
            ->map(function (Collection $group): array {
                $first = $group->first();
                $events = $group->pluck('event')->values()->all();
                $hasConfirmed = collect($events)->contains(fn (array $event): bool => $event['usage_status'] === 'used');

                return [
                    'normalized_name' => $first['normalized_name'],
                    'name' => $first['name'],
                    'display_name' => $first['display_name'],
                    'source' => $first['source'],
                    'status' => $hasConfirmed ? 'used' : 'tentative',
                    'is_active' => $first['is_active'],
                    'campus_location' => $first['campus_location'],
                    'managing_office' => $first['managing_office'],
                    'inquiry_instructions' => $first['inquiry_instructions'],
                    'college' => $first['college'],
                    'events' => $events,
                ];
            })
            ->values();
    }

    private function usageRow(Event $event, EventDay $day, Carbon $date): array
    {
        $venueName = $event->venue?->name ?: $event->location_details;
        $usageStatus = $this->usageStatus($event);
        $source = $event->venue ? 'catalog' : 'manual';
        $start = $this->dateTime($date, (string) $day->start_time);
        $end = $this->dateTime($date, (string) $day->end_time);
        $bufferStart = $usageStatus === 'used' ? $start?->copy()->subHour() : $start;
        $bufferEnd = $usageStatus === 'used' ? $end?->copy()->addHour() : $end;

        return [
            'normalized_name' => $this->normalizeVenueName($venueName),
            'name' => (string) $venueName,
            'display_name' => (string) $venueName,
            'source' => $source,
            'is_active' => (bool) ($event->venue?->is_active ?? true),
            'campus_location' => $event->venue?->campus_location,
            'managing_office' => $event->venue?->managing_office ?: $event->venue_confirmation_office,
            'inquiry_instructions' => $event->venue?->inquiry_instructions,
            'college' => $event->venue?->college?->code,
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'organization' => $event->organization?->acronym ?: $event->organization?->name,
                'status' => $event->status,
                'usage_status' => $usageStatus,
                'date' => $date->toDateString(),
                'start_time' => $this->shortTime((string) $day->start_time),
                'end_time' => $this->shortTime((string) $day->end_time),
                'buffer_start_time' => $bufferStart?->format('H:i'),
                'buffer_end_time' => $bufferEnd?->format('H:i'),
                'venue_name' => (string) $venueName,
            ],
        ];
    }

    /** @param Collection<int, array<string, mixed>> $catalogRows
     * @param  Collection<int, array<string, mixed>>  $usageRows
     * @return Collection<int, array<string, mixed>>
     */
    private function mergeRows(Collection $catalogRows, Collection $usageRows): Collection
    {
        $usageByName = $usageRows->keyBy('normalized_name');
        $rows = $catalogRows->map(function (array $catalogRow) use ($usageByName): array {
            $usageRow = $usageByName->get($catalogRow['normalized_name']);

            if (! $usageRow) {
                return $catalogRow;
            }

            return array_merge($catalogRow, [
                'status' => $usageRow['status'],
                'events' => $usageRow['events'],
            ]);
        });

        $manualRows = $usageRows
            ->reject(fn (array $usageRow): bool => $catalogRows->contains('normalized_name', $usageRow['normalized_name']))
            ->values();

        return $rows
            ->concat($manualRows)
            ->reject(fn (array $row): bool => $row['status'] === 'retired' && $row['events'] === [])
            ->sortBy([['status', 'desc'], ['name', 'asc']])
            ->values();
    }

    /** @param Collection<int, array<string, mixed>> $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function filterRows(Collection $rows, ?string $search, string $status, string $source): Collection
    {
        $search = $this->normalizeVenueName($search);

        return $rows
            ->when($search !== '', fn (Collection $items) => $items->filter(
                fn (array $row): bool => Str::contains($row['normalized_name'], $search)
                    || Str::contains($this->normalizeVenueName($row['managing_office'] ?? ''), $search)
            ))
            ->when(in_array($status, ['used', 'tentative', 'available'], true), fn (Collection $items) => $items->where('status', $status))
            ->when(in_array($source, ['catalog', 'manual'], true), fn (Collection $items) => $items->where('source', $source))
            ->values();
    }

    private function usageStatus(Event $event): string
    {
        return in_array($event->status, self::CONFIRMED_STATUSES, true) ? 'used' : 'tentative';
    }

    private function dayOverlaps(EventDay $day, Carbon $date, ?array $timeWindow, string $usageStatus): bool
    {
        if ($timeWindow === null) {
            return true;
        }

        $dayStart = $this->dateTime($date, (string) $day->start_time);
        $dayEnd = $this->dateTime($date, (string) $day->end_time);
        if (! $dayStart || ! $dayEnd) {
            return false;
        }

        if ($usageStatus === 'used') {
            $dayStart->subHour();
            $dayEnd->addHour();
        }

        return $dayStart->lt($timeWindow['end']) && $dayEnd->gt($timeWindow['start']);
    }

    /** @return array{start: Carbon, end: Carbon}|null */
    private function timeWindow(Carbon $date, ?string $startTime, ?string $endTime): ?array
    {
        if (blank($startTime) || blank($endTime)) {
            return null;
        }

        $start = $this->dateTime($date, $startTime);
        $end = $this->dateTime($date, $endTime);

        if (! $start || ! $end || $end->lte($start)) {
            return null;
        }

        return ['start' => $start, 'end' => $end];
    }

    private function dateTime(Carbon $date, ?string $time): ?Carbon
    {
        $time = $this->shortTime((string) $time);
        if ($time === '') {
            return null;
        }

        try {
            return Carbon::parse($date->toDateString().' '.$time);
        } catch (Throwable) {
            return null;
        }
    }

    private function validDate(?string $date): ?Carbon
    {
        if (blank($date)) {
            return null;
        }

        try {
            return Carbon::parse($date)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    private function shortTime(string $time): string
    {
        return preg_match('/^\d{2}:\d{2}/', $time) === 1 ? substr($time, 0, 5) : '';
    }

    /** @return array{summary: array{used: int, tentative: int, available: int}, venues: list<array<string, mixed>>, disclaimer: string} */
    private function emptyResult(): array
    {
        return [
            'summary' => ['used' => 0, 'tentative' => 0, 'available' => 0],
            'venues' => [],
            'disclaimer' => $this->disclaimer(),
        ];
    }
}
