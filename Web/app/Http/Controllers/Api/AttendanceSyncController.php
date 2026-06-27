<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\User;
use App\Models\RawScan;
use App\Models\PendingStudentLink;
use App\Jobs\ResolveAttendanceJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class AttendanceSyncController extends Controller
{
    /**
     * Expose compressed student roster hydration payload for mobile clients.
     *
     * @param  string  $eventId
     * @return \Illuminate\Http\JsonResponse
     */
    public function hydrate($eventId)
    {
        $event = Event::findOrFail($eventId);
        if (!$this->eventAllowsScanning($event)) {
            return $this->scannerLockedResponse($event);
        }

        // Fetch students who have a valid QR code value
        // Note: For large university databases, we can scope it to constituent programs/colleges,
        // but to prevent blockages on scans, we return all students with valid QR codes.
        $students = User::role('Student')
            ->whereNotNull('qr_code_value')
            ->select('id as student_id', 'qr_code_value', 'name', 'program_code')
            ->get();

        return response()->json([
            'success' => true,
            'event_id' => $event->id,
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
        if (!$this->eventAllowsScanning($event)) {
            return $this->scannerLockedResponse($event);
        }

        $scansData = $request->input('scans');

        // Extract idempotency keys (dedup_keys) and filter out duplicates already present in the database
        $dedupKeys = collect($scansData)->pluck('dedup_key')->toArray();
        $existingDedupKeys = RawScan::whereIn('dedup_key', $dedupKeys)
            ->pluck('dedup_key')
            ->toArray();

        $newScans = [];
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

        $seenDedupKeys = [];
        foreach ($scansData as $scan) {
            // Skip already ingested scans
            if (in_array($scan['dedup_key'], $existingDedupKeys, true) || in_array($scan['dedup_key'], $seenDedupKeys, true)) {
                continue;
            }
            $seenDedupKeys[] = $scan['dedup_key'];

            // Resolve student_id
            $student = $studentsByQr->get($scan['qr_code_value']);
            $studentId = $student ? $student->id : null;

            if ($studentId) {
                $studentIds[] = $studentId;
            } else {
                $unresolvedQrs[] = $scan['qr_code_value'];
            }

            // Map event day dynamic lookup later in background resolution if day_id is not passed
            $newScans[] = [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'event_id' => $eventId,
                'event_day_id' => $scan['event_day_id'] ?? null,
                'student_id' => $studentId,
                'qr_code_value' => $scan['qr_code_value'],
                'scan_type' => $scan['scan_type'],
                'scanned_at' => $scan['scanned_at'],
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
                'message' => 'All scans in batch were duplicates, skipped.'
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
                    'qr_code_value' => $scan['qr_code_value'],
                    'status' => 'pending',
                ],
                [
                    'organization_id' => $event->organization_id,
                    'raw_scan_id' => $scan['id'],
                ]
            );
        }

        // Dispatch background processing job
        // Pass both resolved studentIds and unresolved Qrs so the worker knows who to process
        if (!empty($studentIds)) {
            ResolveAttendanceJob::dispatch($eventId, array_unique($studentIds));
        }

        return response()->json([
            'success' => true,
            'message' => 'Scans ingested successfully. Processing started in background.',
            'ingested_count' => count($newScans),
            'duplicate_count' => count($scansData) - count($newScans),
            'unresolved_count' => count($unresolvedQrs),
        ]);
    }

    private function eventAllowsScanning(Event $event): bool
    {
        return in_array($event->status, ['approved', 'completed'], true);
    }

    private function scannerLockedResponse(Event $event)
    {
        return response()->json([
            'success' => false,
            'message' => 'Scanner session is locked until the event proposal is approved.',
            'event_status' => $event->status,
        ], 423);
    }
}
