<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateStudentProfileRequest;
use App\Models\AttendanceRecord;
use App\Models\College;
use App\Models\Department;
use App\Models\Evaluation;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Program;
use App\Models\User;
use App\Services\StudentProfileService;
use App\Support\EvaluationWindow;
use App\Support\EventEvaluationForm;
use App\Support\YearLevels;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PortalController extends Controller
{
    /**
     * Student portal home page.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Profile completion check
        $profileComplete = $user->student_id_number
            && $user->qr_code_value
            && $user->year_level
            && $user->college_id
            && $user->department_id
            && $user->program_id
            && $user->organization_id;

        $pendingEvalEvents = $this->evaluationCollections($user)['pendingEvalEvents'];

        // Recent attendance
        $recentAttendance = AttendanceRecord::where('student_id', $user->id)
            ->with('event')
            ->latest()
            ->take(5)
            ->get();

        return view('portal.index', compact(
            'user', 'profileComplete', 'pendingEvalEvents', 'recentAttendance'
        ));
    }

    /**
     * Student evaluations task list.
     */
    public function evaluations(Request $request)
    {
        return view('portal.evaluations', array_merge(
            ['user' => $request->user()],
            $this->evaluationCollections($request->user())
        ));
    }

    /**
     * Show profile form.
     */
    public function profile(Request $request)
    {
        $user = $request->user()->load(['organization', 'college', 'department', 'program.department']);
        $colleges = College::orderBy('name')->get();

        $departmentsByCollege = [];
        Department::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get()
            ->each(function (Department $department) use (&$departmentsByCollege): void {
                $departmentsByCollege[$department->college_id][] = [
                    'id' => $department->id,
                    'name' => $department->name,
                    'code' => $department->code,
                ];
            });

        $programsByDepartment = [];
        Program::query()
            ->whereNotNull('department_id')
            ->orderBy('name')
            ->get()
            ->each(function (Program $program) use (&$programsByDepartment): void {
                $programsByDepartment[$program->department_id][] = [
                    'id' => $program->id,
                    'name' => $program->name,
                    'code' => $program->code,
                ];
            });

        $orgsByProgram = [];
        Organization::query()
            ->whereIn('type', ['society', 'non_academic_org', 'academic_unit'])
            ->where(function ($query): void {
                $query->where('status', 'active')
                    ->orWhereNull('status');
            })
            ->with('programs:id')
            ->orderBy('name')
            ->get()
            ->each(function (Organization $organization) use (&$orgsByProgram): void {
                foreach ($organization->programs as $program) {
                    if (in_array($organization->type, ['society', 'academic_unit'], true)
                        && $organization->department_id === null) {
                        continue;
                    }

                    $orgsByProgram[$program->id][] = [
                        'id' => $organization->id,
                        'name' => $organization->name,
                        'acronym' => $organization->acronym,
                        'type' => $organization->type,
                    ];
                }
            });

        $yearLevelOptions = YearLevels::options();

        return view('portal.profile', compact('user', 'colleges', 'departmentsByCollege', 'programsByDepartment', 'orgsByProgram', 'yearLevelOptions'));
    }

    /**
     * Update student profile (PRD Feature 1.2).
     */
    public function updateProfile(UpdateStudentProfileRequest $request, StudentProfileService $profiles)
    {
        $profiles->update($request->user(), $request->validated());

        return redirect()->route('portal.profile')
            ->with('success', 'Profile updated successfully.');
    }

    /**
     * Show evaluation form for a specific event.
     */
    public function evaluation(Request $request, $eventId)
    {
        $user = $request->user();
        $event = Event::with(['eventDays', 'evaluationFormVersion'])->findOrFail($eventId);

        // Check if already submitted
        $existing = Evaluation::where('event_id', $eventId)
            ->where('student_id', $user->id)
            ->first();

        // Check evaluation window
        $windowCloseAt = EvaluationWindow::closesAt($event);
        $windowOpen = $windowCloseAt !== null && Carbon::now()->lte($windowCloseAt);

        $categories = EventEvaluationForm::questionMapForEvent($event);

        return view('portal.evaluation', compact(
            'event', 'existing', 'windowOpen', 'windowCloseAt', 'categories'
        ));
    }

    /**
     * Submit evaluation (delegates to existing evaluation gate logic).
     */
    public function submitEvaluation(Request $request)
    {
        $user = $request->user();

        $rules = [
            'event_id' => 'required|uuid|exists:events,id',
            'section_scores' => 'required|array',
            'open_comment' => 'nullable|string|max:2000',
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $eventId = $request->input('event_id');
        $event = Event::with(['eventDays', 'evaluationFormVersion'])->findOrFail($eventId);
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
            return redirect()->back()->withErrors($scoreValidator)->withInput();
        }

        if (! AttendanceRecord::where('event_id', $eventId)->where('student_id', $user->id)->exists()) {
            abort(403, 'Only attendees can submit an evaluation.');
        }

        // Check evaluation window
        if (EvaluationWindow::isClosed($event)) {
            return redirect()->back()->with('error', 'Evaluation window has closed for this event.');
        }

        // Check duplicate submission
        if (Evaluation::where('event_id', $eventId)->where('student_id', $user->id)->exists()) {
            return redirect()->back()->with('error', 'You have already submitted an evaluation for this event.');
        }

        // Create evaluation
        Evaluation::create([
            'event_id' => $eventId,
            'student_id' => $user->id,
            'evaluation_form_version_id' => $event->evaluation_form_version_id,
            'section_scores' => collect($request->input('section_scores'))->only(array_keys($questionMap))->all(),
            'open_comment' => $request->input('open_comment'),
            'sentiment' => 'unprocessed',
            'submitted_at' => Carbon::now(),
        ]);

        // Evaluation Gate: validate attendance
        AttendanceRecord::where('event_id', $eventId)
            ->where('student_id', $user->id)
            ->update(['valid' => true]);

        return redirect()->route('portal')
            ->with('success', 'Evaluation submitted successfully. Your attendance has been validated.');
    }

    private function evaluationCollections(User $user): array
    {
        $attendedEventIds = AttendanceRecord::where('student_id', $user->id)
            ->pluck('event_id')
            ->unique();

        $evaluatedEventIds = Evaluation::where('student_id', $user->id)
            ->pluck('event_id')
            ->unique();

        $eventsNeedingEvaluation = Event::whereIn('id', $attendedEventIds)
            ->whereNotIn('id', $evaluatedEventIds)
            ->with(['eventDays', 'evaluationFormVersion'])
            ->orderByDesc('start_date')
            ->get();

        $pendingEvalEvents = $eventsNeedingEvaluation
            ->filter(fn (Event $event): bool => EvaluationWindow::isOpen($event))
            ->values();

        $missedEvalEvents = $eventsNeedingEvaluation
            ->filter(fn (Event $event): bool => EvaluationWindow::isClosed($event))
            ->values();

        $submittedEvaluations = Evaluation::where('student_id', $user->id)
            ->with(['event.eventDays'])
            ->orderByDesc('submitted_at')
            ->get();

        return compact('pendingEvalEvents', 'missedEvalEvents', 'submittedEvaluations');
    }
}
