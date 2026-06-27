<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\EventDay;
use App\Models\RawScan;
use App\Models\AttendanceRecord;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ResolveAttendanceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $eventId;
    protected $studentIds;

    /**
     * Create a new job instance.
     *
     * @param  string  $eventId
     * @param  array  $studentIds
     * @return void
     */
    public function __construct($eventId, array $studentIds)
    {
        $this->eventId = $eventId;
        $this->studentIds = $studentIds;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if (empty($this->studentIds)) {
            return;
        }

        $eventId = $this->eventId;
        $studentIds = $this->studentIds;

        // Fetch event parameters
        $event = Event::with('eventDays')->findOrFail($eventId);
        $eventDays = $event->eventDays;

        // Perform pessimistic lock on attendance records inside transaction
        DB::transaction(function () use ($eventId, $studentIds, $event, $eventDays) {
            
            // Acquire locks on affected rows to avoid write collisions
            $existingRecords = AttendanceRecord::where('event_id', $eventId)
                ->whereIn('student_id', $studentIds)
                ->lockForUpdate()
                ->get()
                ->groupBy(function ($record) {
                    return $record->student_id . '_' . $record->event_day_id;
                });

            // Fetch all raw scans for these students on this event
            $rawScans = RawScan::where('event_id', $eventId)
                ->whereIn('student_id', $studentIds)
                ->orderBy('scanned_at', 'asc')
                ->get()
                ->groupBy('student_id');

            foreach ($studentIds as $studentId) {
                $studentScans = $rawScans->get($studentId, collect());

                if ($studentScans->isEmpty()) {
                    continue;
                }

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
                    if (!$timeIn && !$timeOut) {
                        continue;
                    }

                    // Key to search existing record
                    $recordKey = $studentId . '_' . $day->id;
                    $existingGroup = $existingRecords->get($recordKey);
                    $existingRecord = $existingGroup ? $existingGroup->first() : null;

                    // 1. Calculate Punctuality Statuses
                    $societyStatus = 'absent';
                    $competitionStatus = 'absent';

                    if ($timeIn) {
                        // Compare time portion
                        $scanTime = Carbon::createFromTimeString($timeIn->format('H:i:s'));
                        $dayStartTime = Carbon::createFromTimeString($day->start_time);
                        $diffInMinutes = $dayStartTime->diffInMinutes($scanTime, false); // Negative if early

                        // Society status calculations
                        if ($diffInMinutes <= 0) {
                            $societyStatus = 'present_on_time';
                        } elseif ($diffInMinutes <= $event->society_late_threshold_min) {
                            $societyStatus = 'late';
                        } else {
                            $societyStatus = 'late_cutoff';
                        }

                        // Competition status calculations
                        if ($diffInMinutes <= 0) {
                            $competitionStatus = 'present_on_time';
                        } elseif ($diffInMinutes <= $event->general_competition_threshold_min) {
                            $competitionStatus = 'late';
                        } else {
                            $competitionStatus = 'late_cutoff';
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
                            'event_day_id' => $day->id,
                            'student_id' => $studentId,
                        ],
                        [
                            'time_in' => $timeIn,
                            'time_out' => $timeOut,
                            'society_status' => $societyStatus,
                            'competition_status' => $competitionStatus,
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
        });
    }
}
