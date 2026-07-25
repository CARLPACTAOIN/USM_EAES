<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\AdminAssignment;
use App\Models\Event;
use App\Models\PendingStudentLink;
use App\Models\RawScan;
use App\Models\ScannerSession;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class EventLifecycleService
{
    public const PHASE_SCHEDULED = 'scheduled';
    public const PHASE_ACTIVE = 'active';
    public const PHASE_ENDED = 'ended';
    public const PHASE_EVALUATION_OPEN = 'evaluation_open';
    public const PHASE_EVALUATION_CLOSED = 'evaluation_closed';
    public const PHASE_PROCESSING = 'processing';
    public const PHASE_COMPLETED = 'completed';

    public function update(Event $event, ?Carbon $now = null): bool
    {
        $event->loadMissing('eventDays');
        $now ??= now();

        $oldPhase = $event->lifecycle_phase ?: self::PHASE_SCHEDULED;
        $newPhase = $this->determinePhase($event, $now);

        $updates = [];
        if ($oldPhase !== $newPhase) {
            $updates['lifecycle_phase'] = $newPhase;
        }

        $evaluationOpen = $newPhase === self::PHASE_EVALUATION_OPEN;
        if ((bool) $event->evaluation_open !== $evaluationOpen) {
            $updates['evaluation_open'] = $evaluationOpen;
        }

        if ($event->status === 'approved' && $newPhase === self::PHASE_COMPLETED) {
            $updates['status'] = 'completed';
        }

        if ($updates === []) {
            return false;
        }

        $event->forceFill($updates)->save();
        $this->audit($event, 'event-lifecycle-phase-changed', [
            'from' => $oldPhase,
            'to' => $newPhase,
        ]);

        if ($newPhase === self::PHASE_COMPLETED) {
            $this->audit($event, 'event-marked-completed', ['event_id' => $event->id]);
        }

        return true;
    }

    public function determinePhase(Event $event, ?Carbon $now = null): string
    {
        $now ??= now();

        if ($event->status !== 'approved' && $event->status !== 'completed') {
            return $event->lifecycle_phase ?: self::PHASE_SCHEDULED;
        }

        [$firstStart, $finalEnd] = $this->eventBounds($event);
        if (!$firstStart || !$finalEnd) {
            return self::PHASE_SCHEDULED;
        }

        $evaluationClosesAt = $finalEnd->copy()->addHours($this->evaluationWindowHours());

        if ($now->lt($firstStart)) {
            return self::PHASE_SCHEDULED;
        }

        if ($this->isWithinAnyEventDay($event->eventDays, $now)) {
            return self::PHASE_ACTIVE;
        }

        if ($now->lt($finalEnd)) {
            return self::PHASE_SCHEDULED;
        }

        if ($now->lt($evaluationClosesAt)) {
            return self::PHASE_EVALUATION_OPEN;
        }

        $this->expirePastDueScannerSessions($event, $now);

        if (!$this->completionRequirementsSatisfied($event)) {
            return self::PHASE_PROCESSING;
        }

        return self::PHASE_COMPLETED;
    }

    public function eventBounds(Event $event): array
    {
        $event->loadMissing('eventDays');
        $days = $event->eventDays->sortBy('date')->values();

        if ($days->isEmpty()) {
            return [null, null];
        }

        $starts = $days->map(fn ($day) => $this->eventDayDateTime($day->date, $day->start_time));
        $ends = $days->map(fn ($day) => $this->eventDayDateTime($day->date, $day->end_time));

        return [$starts->min(), $ends->max()];
    }

    public function scanWindow(Event $event): array
    {
        $windows = $this->scanWindows($event);

        return [
            $windows['time_in'][0],
            $windows['time_out'][1],
        ];
    }

    public function scanWindowForType(Event $event, string $scanType): array
    {
        $windows = $this->scanWindows($event);

        return $windows[$scanType] ?? $this->scanWindow($event);
    }

    public function scanWindows(Event $event): array
    {
        [$firstStart, $finalEnd] = $this->eventBounds($event);
        $earlyCheckinMinutes = (int) config('services.eaes.scan_early_checkin_buffer_min', 60);
        $checkoutGraceMinutes = (int) config('services.eaes.checkout_grace_period_min', 60);

        return [
            'time_in' => [
                $firstStart?->copy()->subMinutes($earlyCheckinMinutes),
                $finalEnd?->copy(),
            ],
            'time_out' => [
                $firstStart?->copy(),
                $finalEnd?->copy()->addMinutes($checkoutGraceMinutes),
            ],
        ];
    }

    public function syncGraceEndsAt(Event $event): ?Carbon
    {
        [, $finalEnd] = $this->eventBounds($event);

        return $finalEnd?->copy()->addHours((int) config('services.eaes.sync_grace_period_hours', 72));
    }

    public function completionRequirementsSatisfied(Event $event): bool
    {
        $pendingLinks = PendingStudentLink::where('event_id', $event->id)
            ->where('status', 'pending')
            ->exists();

        $unresolvedScans = RawScan::where('event_id', $event->id)
            ->whereNull('student_id')
            ->exists();

        $unprocessedScans = RawScan::where('event_id', $event->id)
            ->whereNotNull('student_id')
            ->whereNull('processed_at')
            ->exists();

        $unprocessedSentiments = $event->evaluations()
            ->pendingSentimentComments()
            ->exists();

        $activeSessions = $event->scannerSessions()
            ->where('status', ScannerSession::STATUS_ACTIVE)
            ->exists();

        return !$pendingLinks && !$unresolvedScans && !$unprocessedScans && !$unprocessedSentiments && !$activeSessions;
    }

    private function isWithinAnyEventDay(Collection $days, Carbon $now): bool
    {
        return $days->contains(function ($day) use ($now): bool {
            $start = $this->eventDayDateTime($day->date, $day->start_time);
            $end = $this->eventDayDateTime($day->date, $day->end_time);

            return $now->betweenIncluded($start, $end);
        });
    }

    private function eventDayDateTime(Carbon $date, string $time): Carbon
    {
        return Carbon::parse(
            $date->toDateString() . ' ' . $time,
            config('services.eaes.event_timezone', 'Asia/Manila')
        );
    }

    private function expirePastDueScannerSessions(Event $event, Carbon $now): void
    {
        $event->scannerSessions()
            ->where('status', ScannerSession::STATUS_ACTIVE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->get()
            ->each(function (ScannerSession $session): void {
                $session->update([
                    'status' => ScannerSession::STATUS_EXPIRED,
                ]);
                $this->audit($session->event, 'scanner-session-expired', [
                    'scanner_session_id' => $session->id,
                ]);
            });
    }

    private function evaluationWindowHours(): int
    {
        return (int) config('services.eaes.evaluation_window_hours', 24);
    }

    private function audit(Event $event, string $action, array $details): void
    {
        $adminId = $event->organization?->adminAssignments()
            ->where('status', AdminAssignment::STATUS_ACTIVE)
            ->orderByDesc('is_primary_admin')
            ->orderByDesc('approved_at')
            ->first()?->user_id
            ?? $event->rawScans()->whereNotNull('scanner_user_id')->first()?->scanner_user_id;

        if (!$adminId) {
            return;
        }

        AuditLog::create([
            'target_id' => $event->id,
            'admin_id' => $adminId,
            'action' => $action,
            'details' => $details + ['event_id' => $event->id],
        ]);
    }
}
