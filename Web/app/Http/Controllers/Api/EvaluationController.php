<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Evaluation;
use App\Models\Event;
use App\Support\EvaluationWindow;
use App\Support\EventEvaluationForm;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EvaluationController extends Controller
{
    /**
     * Submit an event evaluation questionnaire (Student action).
     *
     * @return JsonResponse
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'event_id' => 'required|uuid|exists:events,id',
            'section_scores' => 'required|array', // Likert scale results
            'open_comment' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $eventId = $request->input('event_id');
        $event = Event::with('evaluationFormVersion')->findOrFail($eventId);
        $questionMap = EventEvaluationForm::questionMapForEvent($event);
        $scoreRules = [];

        foreach ($questionMap as $key => $question) {
            $scoreRules["section_scores.{$key}"] = [
                $question['required'] ? 'required' : 'nullable',
                'integer',
                'min:'.$question['scale_min'],
                'max:'.$question['scale_max'],
            ];
        }

        $scoreValidator = Validator::make($request->all(), $scoreRules);
        $scoreValidator->after(function ($validator) use ($request, $questionMap): void {
            foreach (array_keys((array) $request->input('section_scores', [])) as $key) {
                if (! array_key_exists($key, $questionMap)) {
                    $validator->errors()->add("section_scores.{$key}", 'This evaluation question does not belong to this event.');
                }
            }
        });

        if ($scoreValidator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $scoreValidator->errors(),
            ], 422);
        }

        // 1. Check if the evaluation window has closed (24 hours after final event day concludes)
        $event->load('eventDays');
        $windowClosedAt = EvaluationWindow::closesAt($event);

        if (! $windowClosedAt) {
            return response()->json([
                'success' => false,
                'message' => 'Event days not defined.',
            ], 422);
        }

        if (Carbon::now()->gt($windowClosedAt)) {
            return response()->json([
                'success' => false,
                'message' => 'Evaluation window closed. Submissions are no longer accepted for this event.',
            ], 403);
        }

        // 2. Check if student already submitted an evaluation
        $existing = Evaluation::where('event_id', $eventId)
            ->where('student_id', $user->id)
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'You have already submitted an evaluation for this event.',
            ], 422);
        }

        // 3. Create Evaluation record (sentiment left as "unprocessed")
        $evaluation = Evaluation::create([
            'event_id' => $eventId,
            'student_id' => $user->id,
            'evaluation_form_version_id' => $event->evaluation_form_version_id,
            'section_scores' => collect($request->input('section_scores'))->only(array_keys($questionMap))->all(),
            'open_comment' => $request->input('open_comment'),
            'sentiment' => 'unprocessed',
            'submitted_at' => Carbon::now(),
        ]);

        // 4. Evaluation Gate Logic: Unlock and validate all daily attendance records for this student/event
        AttendanceRecord::where('event_id', $eventId)
            ->where('student_id', $user->id)
            ->update(['valid' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Evaluation submitted successfully. Your attendance has been validated.',
            'evaluation' => $evaluation,
        ], 201);
    }
}
