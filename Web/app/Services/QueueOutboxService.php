<?php

namespace App\Services;

use App\Jobs\ResolveAttendanceJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class QueueOutboxService
{
    public const TYPE_ATTENDANCE_RESOLUTION = 'attendance_resolution';

    /**
     * @param  array<int, string>  $studentIds
     * @param  array<int, string>  $rawScanIds
     */
    public function recordAttendanceResolution(
        string $eventId,
        ?string $eventParticipationScopeId,
        array $studentIds,
        array $rawScanIds,
    ): ?string {
        $studentIds = collect($studentIds)->filter()->unique()->sort()->values()->all();
        $rawScanIds = collect($rawScanIds)->filter()->unique()->sort()->values()->all();

        if ($studentIds === [] || $rawScanIds === []) {
            return null;
        }

        $dedupKey = self::TYPE_ATTENDANCE_RESOLUTION.':'.hash('sha256', implode('|', $rawScanIds));
        $timestamp = now();

        DB::table('queue_outbox_entries')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'type' => self::TYPE_ATTENDANCE_RESOLUTION,
            'dedup_key' => $dedupKey,
            'payload' => json_encode([
                'event_id' => $eventId,
                'event_participation_scope_id' => $eventParticipationScopeId,
                'student_ids' => $studentIds,
            ], JSON_THROW_ON_ERROR),
            'dispatch_attempts' => 0,
            'available_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return DB::table('queue_outbox_entries')
            ->where('dedup_key', $dedupKey)
            ->value('id');
    }

    public function dispatch(string $outboxEntryId): bool
    {
        $entry = DB::table('queue_outbox_entries')
            ->where('id', $outboxEntryId)
            ->whereNull('dispatched_at')
            ->first();

        if (! $entry) {
            return false;
        }

        try {
            DB::table('queue_outbox_entries')
                ->where('id', $entry->id)
                ->increment('dispatch_attempts', 1, ['updated_at' => now()]);

            $payload = json_decode($entry->payload, true, 512, JSON_THROW_ON_ERROR);
            if ($entry->type !== self::TYPE_ATTENDANCE_RESOLUTION) {
                throw new \LogicException('Unsupported queue outbox entry type.');
            }

            ResolveAttendanceJob::dispatch(
                $payload['event_id'],
                $payload['student_ids'],
                $payload['event_participation_scope_id'],
            );

            DB::table('queue_outbox_entries')
                ->where('id', $entry->id)
                ->whereNull('dispatched_at')
                ->update([
                    'dispatched_at' => now(),
                    'last_error_class' => null,
                    'updated_at' => now(),
                ]);

            return true;
        } catch (Throwable $exception) {
            DB::table('queue_outbox_entries')
                ->where('id', $entry->id)
                ->update([
                    'last_error_class' => $exception::class,
                    'updated_at' => now(),
                ]);

            Log::warning('Queue outbox dispatch deferred.', [
                'outbox_entry_id' => $entry->id,
                'outbox_type' => $entry->type,
                'exception_class' => $exception::class,
            ]);

            return false;
        }
    }

    public function dispatchPending(int $limit = 100): int
    {
        $outboxEntryIds = DB::table('queue_outbox_entries')
            ->whereNull('dispatched_at')
            ->where(function ($query): void {
                $query->whereNull('available_at')->orWhere('available_at', '<=', now());
            })
            ->orderBy('created_at')
            ->limit($limit)
            ->pluck('id');

        return $outboxEntryIds
            ->filter(fn (string $outboxEntryId): bool => $this->dispatch($outboxEntryId))
            ->count();
    }
}
