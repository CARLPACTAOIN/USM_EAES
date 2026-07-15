<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\EmployeeRosterEntry;
use App\Models\Event;
use App\Models\EventParticipationScope;
use App\Models\User;
use App\Models\RawScan;
use App\Models\PendingStudentLink;
use App\Jobs\ResolveAttendanceJob;
use App\Services\ParticipationScopeService;
use App\Services\ScannerSessionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class AttendanceSyncController extends Controller
{
    public function __construct(
        private readonly ScannerSessionService $scannerSessions,
        private readonly ParticipationScopeService $participationScopes
    ) {
    }

    /**
     * Expose compressed student roster hydration payload for mobile clients.
     *
     * @param  string  $eventId
     * @return \Illuminate\Http\JsonResponse
     */
    public function hydrate($eventId)
    {
        $event = Event::findOrFail($eventId);
        $access = $this->scannerSessions->validateRequest(request(), $event);
        if (!$access['allowed']) {
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
            'roster' => $students
        ]);
    }

    /**
     * Synchronize a batch of raw scans from the mobile device.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sync(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'event_id' => 'required|uuid|exists:events,id',
            'scans' => 'required|array|max:' . config('services.eaes.sync_batch_size', 200),
            'scans.*.dedup_key' => 'required|string',
            'scans.*.scan_type' => 'required|in:time_in,time_out',
            'scans.*.scanned_at' => 'required|date',
            'scans.*.device_id' => 'required|string',
            'scans.*.manual_entry' => 'required|boolean',
            'scans.*.qr_code_value' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $eventId = $request->input('event_id');
        $event = Event::findOrFail($eventId);
        $access = $this->scannerSessions->validateRequest($request, $event, forSync: true);
        if (!$access['allowed']) {
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
        $unresolvedQrs = [];
        $studentIds = [];

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
            $timestampGuard = $this->scannerSessions->validateScanTimestamp($event, $scannedAt);
            if ($timestampGuard) {
                $rejectedScans[] = [
                    'dedup_key' => $scan['dedup_key'],
                    'reason' => $timestampGuard['reason'],
                    'message' => $timestampGuard['message'],
                ];
                continue;
            }

            // Resolve student_id
            $student = $studentsByQr->get($scan['qr_code_value']) ?: $employeeUsersByQr->get($scan['qr_code_value']);
            $studentId = $student ? $student->id : null;

            if ($studentId) {
                if (!$this->participationScopes->userBelongsToScope($student, $scope)) {
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

                $studentIds[] = $studentId;
            } else {
                $unresolvedQrs[] = $scan['qr_code_value'];
            }

            // Map event day dynamic lookup later in background resolution if day_id is not passed
            $newScans[] = [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'event_id' => $eventId,
                'event_participation_scope_id' => $scope->id,
                'event_day_id' => $scan['event_day_id'] ?? null,
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

        // Perform high-speed bulk insert and queue unresolved QR records for manual review.
        DB::transaction(function () use ($newScans) {
            RawScan::insert($newScans);
        });

        foreach ($newScans as $scan) {
            if ($scan['student_id']) {
                continue;
            }

            PendingStudentLink::firstOrCreate(
                [
                    'event_id' => $eventId,
                    'event_participation_scope_id' => $scope->id,
                    'qr_code_value' => $scan['qr_code_value'],
                    'status' => 'pending',
                ],
                [
                    'organization_id' => $scope->organization_id ?: $event->organization_id,
                    'raw_scan_id' => $scan['id'],
                ]
            );
        }

        // Dispatch background processing job
        // Pass both resolved studentIds and unresolved Qrs so the worker knows who to process
        if (!empty($studentIds)) {
            ResolveAttendanceJob::dispatch($eventId, array_unique($studentIds), $scope->id);
        }

        return response()->json([
            'success' => true,
            'message' => 'Scans ingested successfully. Processing started in background.',
            'ingested_count' => count($newScans),
            'duplicate_count' => count($scansData) - count($newScans) - count($rejectedScans),
            'rejected_count' => count($rejectedScans),
            'rejected_scans' => $rejectedScans,
            'unresolved_count' => count($unresolvedQrs),
        ]);
    }

    private function scannerDeniedResponse(array $access, Event $event)
    {
        return response()->json([
            'success' => false,
            'message' => $access['message'],
            'event_status' => $event->status,
            'event_phase' => $event->lifecycle_phase,
            'reason' => $access['reason'],
        ], $access['status']);
    }

    private function hydrationRoster(EventParticipationScope $scope)
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
            ->whereNotNull('qr_code_value')
            ->select(
                'id as student_id',
                'qr_code_value',
                'name',
                'program_code',
                'student_id_number',
            )
            ->get();
    }
}
