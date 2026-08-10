<?php

namespace App\Jobs;

use App\Models\AttendanceRecord;
use App\Models\Event;
use App\Models\EventParticipationScope;
use App\Models\RawScan;
use App\Services\EventAnalyticsCache;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ResolveAttendanceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var array<int, int> */
    public array $backoff = [5, 30, 90];

    protected string $eventId;

    /** @var array<int, string> */
    protected array $studentIds;

    protected ?string $eventParticipationScopeId;

    /**
     * Create a new job instance.
     *
     * @param  array<int, string>  $studentIds
     */
    public function __construct(string $eventId, array $studentIds, ?string $eventParticipationScopeId = null)
    {
        $this->onQueue('attendance');
        $this->eventId = $eventId;
        $this->studentIds = $studentIds;
        $this->eventParticipationScopeId = $eventParticipationScopeId;
    }

    /**
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->overlapKey()))
                ->releaseAfter(5)
                ->expireAfter(180),
        ];
    }

    public function handle(EventAnalyticsCache $analyticsCache): void
    {
        if (empty($this->studentIds)) {
            return;
        }

        $eventId = $this->eventId;
        $studentIds = collect($this->studentIds)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
        $eventParticipationScopeId = $this->eventParticipationScopeId;

        // Fetch event parameters
        $event = Event::with(['eventDays', 'participationScopes'])->findOrFail($eventId);
        $eventDays = $event->eventDays;
        $participationScopes = $event->participationScopes->keyBy('id');

        DB::transaction(function () use ($eventId, $studentIds, $event, $eventDays, $eventParticipationScopeId, $participationScopes): void {
            if ($eventParticipationScopeId) {
                EventParticipationScope::query()
                    ->whereKey($eventParticipationScopeId)
                    ->lockForUpdate()
                    ->firstOrFail();
            } else {
                Event::query()
                    ->whereKey($eventId)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            // Acquire locks on affected rows to avoid write collisions
            $existingRecords = AttendanceRecord::where('event_id', $eventId)
                ->whereIn('student_id', $studentIds)
                ->when($eventParticipationScopeId, fn ($query) => $query->where('event_participation_scope_id', $eventParticipationScopeId))
                ->lockForUpdate()
                ->get()
                ->groupBy(function ($record) {
                    return $record->student_id.'_'.$record->event_day_id.'_'.($record->event_participation_scope_id ?? '');
                });

            // Fetch all raw scans for these students on this event
            $rawScans = RawScan::where('event_id', $eventId)
                ->whereIn('student_id', $studentIds)
                ->when($eventParticipationScopeId, fn ($query) => $query->where('event_participation_scope_id', $eventParticipationScopeId))
                ->orderBy('scanned_at', 'asc')
                ->get()
                ->groupBy(function (RawScan $scan) {
                    return $scan->student_id.'_'.($scan->event_participation_scope_id ?? '');
                });

            foreach ($studentIds as $studentId) {
                $scopeKeys = $eventParticipationScopeId
                    ? [$studentId.'_'.$eventParticipationScopeId]
                    : $rawScans->keys()
                        ->filter(fn (string $key) => str_starts_with($key, $studentId.'_'))
                        ->values()
                        ->all();

                foreach ($scopeKeys as $scopeKey) {
                    $studentScans = $rawScans->get($scopeKey, collect());

                    if ($studentScans->isEmpty()) {
                        continue;
                    }

                    $scopeId = $studentScans->first()?->event_participation_scope_id;

                    // Group student scans by matching event day
                    $scansByDay = [];
                    foreach ($studentScans as $scan) {
                        $scanDate = Carbon::parse($scan->scanned_at)->toDateString();

                        // Match to event day by date
                        $matchedDay = $eventDays->first(function ($day) use ($scanDate) {
                            return $day->date->toDateString() === $scanDate;
                        });

                        if ($matchedDay) {
                            $scansByDay[$matchedDay->id][] = $scan;
                        }
                    }

                    // Process EILO per day
                    foreach ($eventDays as $day) {
                        $dayScans = collect($scansByDay[$day->id] ?? []);

                        $timeInScan = $dayScans->where('scan_type', 'time_in')->first();
                        $timeOutScan = $dayScans->where('scan_type', 'time_out')->last();

                        $timeIn = $timeInScan ? Carbon::parse($timeInScan->scanned_at) : null;
                        $timeOut = $timeOutScan ? Carbon::parse($timeOutScan->scanned_at) : null;

                        // If neither time_in nor time_out exists, skip creating/updating records for this day
                        if (! $timeIn && ! $timeOut) {
                            continue;
                        }

                        // Key to search existing record
                        $recordKey = $studentId.'_'.$day->id.'_'.($scopeId ?? '');
                        $existingGroup = $existingRecords->get($recordKey);
                        $existingRecord = $existingGroup ? $existingGroup->first() : null;

                        // 1. Calculate official punctuality status from organizer policy, or scope fallback.
                        $attendanceStatus = 'absent';
                        $scope = $scopeId ? $participationScopes->get($scopeId) : null;
                        $lateCutoffMinutes = $event->lateCutoffMinutesFor($scope);

                        if ($timeIn) {
                            // Compare time portion
                            $scanTime = Carbon::createFromTimeString($timeIn->format('H:i:s'));
                            $dayStartTime = Carbon::createFromTimeString($day->start_time);
                            $diffInMinutes = $dayStartTime->diffInMinutes($scanTime, false); // Negative if early

                            if ($diffInMinutes <= 0) {
                                $attendanceStatus = 'present_on_time';
                            } elseif ($diffInMinutes <= $lateCutoffMinutes) {
                                $attendanceStatus = 'late';
                            } else {
                                $attendanceStatus = 'late_cutoff';
                            }
                        }

                        // 2. Calculate Left-Early anomaly
                        $leftEarly = false;
                        if ($timeOut) {
                            $scanTimeOut = Carbon::createFromTimeString($timeOut->format('H:i:s'));
                            $dayEndTime = Carbon::createFromTimeString($day->end_time);

                            // If they scan out earlier than end_time minus buffer threshold
                            $cutoffTime = $dayEndTime->copy()->subMinutes($event->left_early_buffer_min);
                            if ($scanTimeOut->lt($cutoffTime)) {
                                $leftEarly = true;
                            }
                        }

                        // 3. Upsert Canonical Attendance Record
                        AttendanceRecord::updateOrCreate(
                            [
                                'event_id' => $eventId,
                                'event_participation_scope_id' => $scopeId,
                                'event_day_id' => $day->id,
                                'student_id' => $studentId,
                            ],
                            [
                                'time_in' => $timeIn,
                                'time_out' => $timeOut,
                                'society_status' => $attendanceStatus,
                                'competition_status' => $attendanceStatus,
                                'left_early' => $leftEarly,
                                'valid' => $existingRecord ? $existingRecord->valid : false, // Preserve evaluation gate validation state
                                'force_validated' => $existingRecord ? $existingRecord->force_validated : false,
                                'validated_by' => $existingRecord ? $existingRecord->validated_by : null,
                            ]
                        );

                        // Also, update the raw scans to associate them with the event_day_id if not done yet
                        if ($timeInScan && empty($timeInScan->event_day_id)) {
                            $timeInScan->event_day_id = $day->id;
                            $timeInScan->save();
                        }
                        if ($timeOutScan && empty($timeOutScan->event_day_id)) {
                            $timeOutScan->event_day_id = $day->id;
                            $timeOutScan->save();
                        }
                    }
                }

                RawScan::where('event_id', $eventId)
                    ->where('student_id', $studentId)
                    ->when($eventParticipationScopeId, fn ($query) => $query->where('event_participation_scope_id', $eventParticipationScopeId))
                    ->whereNull('processed_at')
                    ->update(['processed_at' => now()]);
            }
        });

        $analyticsCache->invalidateEvent($eventId);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Attendance resolution job failed.', [
            'event_id' => $this->eventId,
            'event_participation_scope_id' => $this->eventParticipationScopeId,
            'exception_class' => $exception ? $exception::class : null,
        ]);
    }

    private function overlapKey(): string
    {
        return 'attendance-resolution:'.$this->eventId.':'.($this->eventParticipationScopeId ?? 'legacy');
    }
}
