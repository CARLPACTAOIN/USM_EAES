<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventParticipationScope;
use App\Models\RawScan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class AttendanceSyncPersistenceService
{
    public function __construct(
        private readonly QueueOutboxService $queueOutbox,
        private readonly EventAnalyticsCache $analyticsCache,
    ) {}

    /**
     * Persist only the scans that win the database uniqueness race.
     *
     * @param  array<int, array<string, mixed>>  $candidateScans
     * @return Collection<int, RawScan>
     */
    public function persist(Event $event, EventParticipationScope $scope, array $candidateScans): Collection
    {
        return $this->persistWithResolutionOutbox($event, $scope, $candidateScans)['scans'];
    }

    /**
     * Persist winners and create their durable attendance-resolution handoff in one transaction.
     *
     * @param  array<int, array<string, mixed>>  $candidateScans
     * @return array{scans: Collection<int, RawScan>, outbox_entry_id: ?string}
     */
    public function persistWithResolutionOutbox(Event $event, EventParticipationScope $scope, array $candidateScans): array
    {
        if ($candidateScans === []) {
            return ['scans' => collect(), 'outbox_entry_id' => null];
        }

        $result = DB::transaction(function () use ($candidateScans, $event, $scope): array {
            $persistedScans = $this->persistCandidates($event, $scope, $candidateScans);
            $outboxEntryId = $this->queueOutbox->recordAttendanceResolution(
                $event->id,
                $scope->id,
                $persistedScans->pluck('student_id')->filter()->all(),
                $persistedScans->pluck('id')->all(),
            );

            return ['scans' => $persistedScans, 'outbox_entry_id' => $outboxEntryId];
        });

        if ($result['scans']->isNotEmpty()) {
            $this->analyticsCache->invalidateEvent($event->id);
        }

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidateScans
     * @return Collection<int, RawScan>
     */
    private function persistCandidates(Event $event, EventParticipationScope $scope, array $candidateScans): Collection
    {
        RawScan::query()->insertOrIgnore($candidateScans);

        $persistedScans = RawScan::query()
            ->whereIn('id', collect($candidateScans)->pluck('id')->all())
            ->get();

        $persistedScansById = $persistedScans->keyBy('id');
        foreach ($candidateScans as $candidateScan) {
            $persistedScan = $persistedScansById->get($candidateScan['id']);

            if ($persistedScan && $persistedScan->dedup_key !== $candidateScan['dedup_key']) {
                throw new LogicException('A raw scan UUID conflicts with a different idempotency key.');
            }
        }

        $persistedScanIds = $persistedScans->pluck('id')->all();
        $persistedCandidates = collect($candidateScans)
            ->keyBy('id')
            ->only($persistedScanIds)
            ->values();

        $this->createPendingStudentLinks($event, $scope, $persistedCandidates);

        return $persistedScans;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $persistedCandidates
     */
    private function createPendingStudentLinks(Event $event, EventParticipationScope $scope, Collection $persistedCandidates): void
    {
        $timestamp = now();
        $pendingLinks = $persistedCandidates
            ->filter(fn (array $scan): bool => $scan['student_id'] === null)
            ->unique('qr_code_value')
            ->map(fn (array $scan): array => [
                'id' => (string) Str::uuid(),
                'event_id' => $event->id,
                'event_participation_scope_id' => $scope->id,
                'organization_id' => $scope->organization_id ?? $event->organization_id,
                'raw_scan_id' => $scan['id'],
                'qr_code_value' => $scan['qr_code_value'],
                'status' => 'pending',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ])
            ->values()
            ->all();

        if ($pendingLinks !== []) {
            DB::table('pending_student_links')->insertOrIgnore($pendingLinks);
        }
    }
}
