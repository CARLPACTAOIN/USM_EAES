<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\EmployeeRosterEntry;
use App\Models\Event;
use App\Models\EventParticipationScope;
use App\Models\RawScan;
use App\Models\User;
use App\Services\AttendanceSyncPersistenceService;
use App\Services\ParticipationScopeService;
use App\Services\QueueOutboxService;
use App\Services\ScannerSessionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AttendanceSyncController extends Controller
{
    public function __construct(
        private readonly ScannerSessionService $scannerSessions,
        private readonly ParticipationScopeService $participationScopes,
        private readonly AttendanceSyncPersistenceService $syncPersistence,
        private readonly QueueOutboxService $queueOutbox,
    ) {}

    /**
     * Expose compressed student roster hydration payload for mobile clients.
     */
    public function hydrate(string $eventId): JsonResponse
    {
        $event = Event::findOrFail($eventId);
        $access = $this->scannerSessions->validateRequest(request(), $event);
        if (! $access['allowed']) {
            return $this->scannerDeniedResponse($access, $event);
        }

        /** @var EventParticipationScope $scope */
        $scope = $access['scope'];
        $students = $this->hydrationRoster($scope);

        return response()->json([
            'success' => true,
            'event_id' => $event->id,
            'event_phase' => $event->lifecycle_phase,
            'event_participation_scope_id' => $scope->id,
            'participation_scope' => $this->participationScopes->scopePayload($scope),
            'scanner_session' => $this->scannerSessions->scannerState($event, $access['session']),
            'roster' => $students,
        ]);
    }

    /**
     * Synchronize a batch of raw scans from the mobile device.
     */
    public function sync(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'event_id' => 'required|uuid|exists:events,id',
            'scans' => 'required|array|max:'.config('services.eaes.sync_batch_size', 200),
            'scans.*.dedup_key' => 'required|string',
            'scans.*.scan_type' => 'required|in:time_in,time_out',
            'scans.*.scanned_at' => 'required|date',
            'scans.*.device_id' => 'required|string',
            'scans.*.manual_entry' => 'required|boolean',
            'scans.*.qr_code_value' => 'required|string',
            'scans.*.event_day_session_id' => 'nullable|uuid|exists:event_day_sessions,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $eventId = $request->input('event_id');
        $event = Event::findOrFail($eventId);
        $access = $this->scannerSessions->validateRequest($request, $event, forSync: true);
        if (! $access['allowed']) {
            return $this->scannerDeniedResponse($access, $event);
        }

        $scansData = $request->input('scans');
        $session = $access['session'];
        /** @var EventParticipationScope $scope */
        $scope = $access['scope'];

        // Extract idempotency keys (dedup_keys) and filter out duplicates already present in the database
        $dedupKeys = collect($scansData)->pluck('dedup_key')->toArray();
        $existingDedupKeys = RawScan::whereIn('dedup_key', $dedupKeys)
            ->pluck('dedup_key')
            ->toArray();

        $newScans = [];
        $rejectedScans = [];

        // Pre-fetch students by QR code to resolve student_id immediately on sync
        $qrValues = collect($scansData)
            ->whereNotIn('dedup_key', $existingDedupKeys)
            ->pluck('qr_code_value')
            ->unique()
            ->toArray();

        $studentsByQr = User::whereIn('qr_code_value', $qrValues)
            ->get()
            ->keyBy('qr_code_value');

        $employeeUsersByQr = EmployeeRosterEntry::with('user')
            ->where(function ($query) use ($qrValues): void {
                $query->whereIn('qr_code_value', $qrValues)
                    ->orWhereIn('employee_id', $qrValues);
            })
            ->where('status', EmployeeRosterEntry::STATUS_ACTIVE)
            ->get()
            ->filter(fn (EmployeeRosterEntry $entry) => $entry->user !== null)
            ->flatMap(function (EmployeeRosterEntry $entry) {
                $keys = [$entry->employee_id];
                if ($entry->qr_code_value) {
                    $keys[] = $entry->qr_code_value;
                }

                return collect($keys)->mapWithKeys(fn (string $key) => [$key => $entry->user]);
            });

        $seenDedupKeys = [];
        foreach ($scansData as $scan) {
            // Skip already ingested scans
            if (in_array($scan['dedup_key'], $existingDedupKeys, true) || in_array($scan['dedup_key'], $seenDedupKeys, true)) {
                continue;
            }
            $seenDedupKeys[] = $scan['dedup_key'];

            $scannedAt = Carbon::parse(
                $scan['scanned_at'],
                config('services.eaes.event_timezone', 'Asia/Manila')
            );
            $timestampGuard = $this->scannerSessions->validateScanTimestamp($event, $scannedAt, $scan['scan_type']);
            if ($timestampGuard) {
                $rejectedScans[] = [
                    'dedup_key' => $scan['dedup_key'],
                    'reason' => $timestampGuard['reason'],
                    'message' => $timestampGuard['message'],
                ];

                continue;
            }

            $resolvedSession = $this->scannerSessions->resolveScanSession($event, $scannedAt, $scan['scan_type']);

            // Resolve student_id
            $student = $studentsByQr->get($scan['qr_code_value']) ?: $employeeUsersByQr->get($scan['qr_code_value']);
            $studentId = $student ? $student->id : null;

            if ($studentId) {
                if (! $this->participationScopes->userBelongsToScope($student, $scope)) {
                    $rejectedScans[] = [
                        'dedup_key' => $scan['dedup_key'],
                        'reason' => 'out_of_scope',
                        'message' => 'Scanned user is outside this scanner participant scope.',
                    ];

                    AuditLog::create([
                        'target_id' => $event->id,
                        'admin_id' => $request->user()->id,
                        'action' => 'scanner-out-of-scope-scan-rejected',
                        'details' => [
                            'event_id' => $event->id,
                            'event_participation_scope_id' => $scope->id,
                            'scanner_session_id' => $session->id,
                            'scanner_user_id' => $request->user()->id,
                            'student_id' => $studentId,
                            'dedup_key' => $scan['dedup_key'],
                        ],
                    ]);

                    continue;
                }

            }

            // Map event day dynamic lookup later in background resolution if day_id is not passed
            $newScans[] = [
                'id' => (string) Str::uuid(),
                'event_id' => $eventId,
                'event_participation_scope_id' => $scope->id,
                'event_day_id' => $resolvedSession['day']->id ?? ($scan['event_day_id'] ?? null),
                'event_day_session_id' => $resolvedSession['session']->id ?? ($scan['event_day_session_id'] ?? null),
                'scanner_session_id' => $session->id,
                'scanner_user_id' => $request->user()->id,
                'captured_by_organization_id' => $scope->organization_id ?: $event->organization_id,
                'captured_by_admin_scope' => $scope->participation_role,
                'student_id' => $studentId,
                'qr_code_value' => $scan['qr_code_value'],
                'scan_type' => $scan['scan_type'],
                'scanned_at' => $scannedAt,
                'server_received_at' => now(),
                'device_id' => $scan['device_id'],
                'manual_entry' => $scan['manual_entry'],
                'dedup_key' => $scan['dedup_key'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (empty($newScans)) {
            return response()->json([
                'success' => true,
                'message' => $rejectedScans === []
                    ? 'All scans in batch were duplicates, skipped.'
                    : 'No scans were ingested because every new scan was outside the allowed event window.',
                'rejected_count' => count($rejectedScans),
                'rejected_scans' => $rejectedScans,
            ]);
        }

        $persistenceResult = $this->syncPersistence->persistWithResolutionOutbox($event, $scope, $newScans);
        $persistedScans = $persistenceResult['scans'];

        if ($persistedScans->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => $rejectedScans === []
                    ? 'All scans in batch were duplicates, skipped.'
                    : 'No scans were ingested because every new scan was outside the allowed event window.',
                'rejected_count' => count($rejectedScans),
                'rejected_scans' => $rejectedScans,
            ]);
        }

        if ($persistenceResult['outbox_entry_id']) {
            $this->queueOutbox->dispatch($persistenceResult['outbox_entry_id']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Scans ingested successfully. Processing started in background.',
            'ingested_count' => $persistedScans->count(),
            'duplicate_count' => count($scansData) - $persistedScans->count() - count($rejectedScans),
            'rejected_count' => count($rejectedScans),
            'rejected_scans' => $rejectedScans,
            'unresolved_count' => $persistedScans->whereNull('student_id')->count(),
        ]);
    }

    /**
     * @param  array{message: string, event_phase?: string, reason: string, status: int}  $access
     */
    private function scannerDeniedResponse(array $access, Event $event): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $access['message'],
            'event_status' => $event->status,
            'event_phase' => $event->lifecycle_phase,
            'reason' => $access['reason'],
        ], $access['status']);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function hydrationRoster(EventParticipationScope $scope): Collection
    {
        $scope->loadMissing('event');

        if ($scope->event?->event_domain === 'hr' || $scope->participant_type === 'hr_unit') {
            return EmployeeRosterEntry::query()
                ->where('status', EmployeeRosterEntry::STATUS_ACTIVE)
                ->when($scope->college_id, fn ($query, $collegeId) => $query->where('college_id', $collegeId))
                ->get()
                ->map(fn (EmployeeRosterEntry $entry) => [
                    'student_id' => $entry->user_id ?: $entry->id,
                    'qr_code_value' => $entry->qr_code_value ?: $entry->employee_id,
                    'name' => $entry->name,
                    'program_code' => $entry->office_department,
                    'student_id_number' => $entry->employee_id,
                ])
                ->values();
        }

        return $this->participationScopes
            ->audienceQuery($scope)
            ->whereNotNull('users.qr_code_value')
            ->select(
                'users.id as student_id',
                'users.qr_code_value',
                'users.name',
                'users.program_code',
                'users.student_id_number',
            )
            ->get();
    }
}
