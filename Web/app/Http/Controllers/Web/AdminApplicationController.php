<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AdminApplication;
use App\Models\College;
use App\Models\Organization;
use App\Models\Program;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AdminApplicationController extends Controller
{
    private const REQUEST_ROLE_MAP = [
        'existing_usg' => 'USG Admin',
        'existing_aro' => 'ARO Admin',
        'existing_lsg' => 'LSG Admin',
        'existing_society' => 'Society Admin',
        'new_society' => 'Society Admin',
    ];

    public function index(Request $request)
    {
        $user = $request->user();

        $applications = AdminApplication::with(['organization', 'college', 'programs'])
            ->where('applicant_id', $user->id)
            ->latest()
            ->paginate(10);

        $colleges = College::orderBy('name')->get();
        $organizations = Organization::with('college')->where('status', 'active')->orderBy('name')->get();
        $programs = Program::with('college')->orderBy('name')->get();

        return view('portal.admin-applications.index', compact('applications', 'colleges', 'organizations', 'programs'));
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'request_type' => ['required', Rule::in(array_keys(self::REQUEST_ROLE_MAP))],
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'college_id' => ['nullable', 'uuid', 'exists:colleges,id'],
            'organization_name' => ['nullable', 'string', 'max:255'],
            'organization_acronym' => ['nullable', 'string', 'max:50'],
            'program_ids' => ['nullable', 'array'],
            'program_ids.*' => ['uuid', 'exists:programs,id'],
            'adviser_name' => ['nullable', 'string', 'max:255'],
            'academic_year' => ['required', 'string', 'max:20'],
            'term_start' => ['nullable', 'date'],
            'term_end' => ['nullable', 'date', 'after_or_equal:term_start'],
            'position_title' => ['nullable', 'string', 'max:120'],
            'proof_document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $validator->after(function ($validator) use ($request) {
            $type = $request->input('request_type');

            if (in_array($type, ['existing_lsg', 'new_society'], true) && !$request->filled('college_id')) {
                $validator->errors()->add('college_id', 'Select the college for this request.');
            }

            if ($type === 'existing_society' && !$request->filled('organization_id')) {
                $validator->errors()->add('organization_id', 'Select the existing society.');
            }

            if ($type === 'new_society') {
                if (!$request->filled('organization_name')) {
                    $validator->errors()->add('organization_name', 'Enter the society name.');
                }
                if (!$request->filled('organization_acronym')) {
                    $validator->errors()->add('organization_acronym', 'Enter the society acronym.');
                }
                if (count($request->input('program_ids', [])) === 0) {
                    $validator->errors()->add('program_ids', 'Select at least one covered program.');
                }
            }
        });

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $roleName = self::REQUEST_ROLE_MAP[$request->input('request_type')];
        $organization = $this->resolveRequestedOrganization($request);
        $collegeId = $this->resolveRequestedCollegeId($request, $organization);

        $this->validateProgramScope($request, $collegeId);
        $this->ensureNoDuplicatePendingApplication($request, $roleName, $organization?->id, $collegeId);

        $proofPath = $request->file('proof_document')->store('admin-applications/proofs');
        $logoPath = $request->file('logo')?->store('organization-logos', 'public');

        $application = AdminApplication::create([
            'applicant_id' => $request->user()->id,
            'request_type' => $request->input('request_type'),
            'role_name' => $roleName,
            'organization_id' => $organization?->id,
            'college_id' => $collegeId,
            'organization_name' => $request->input('organization_name'),
            'organization_acronym' => $request->filled('organization_acronym') ? strtoupper($request->input('organization_acronym')) : null,
            'adviser_name' => $request->input('adviser_name'),
            'academic_year' => $request->input('academic_year'),
            'term_start' => $request->input('term_start'),
            'term_end' => $request->input('term_end'),
            'position_title' => $request->input('position_title'),
            'proof_document_path' => $proofPath,
            'proof_document_original_name' => $request->file('proof_document')->getClientOriginalName(),
            'logo_path' => $logoPath,
            'status' => AdminApplication::STATUS_PENDING,
        ]);

        if (in_array($request->input('request_type'), ['new_society', 'existing_society'], true)) {
            $application->programs()->sync($request->input('program_ids', []));
        }

        return redirect()->route('portal.admin-applications')
            ->with('success', 'Admin access application submitted for OSA review.');
    }

    private function resolveRequestedOrganization(Request $request): ?Organization
    {
        return match ($request->input('request_type')) {
            'existing_usg' => Organization::where('type', 'usg')->first(),
            'existing_aro' => Organization::where('type', 'aro')->first(),
            'existing_lsg' => Organization::where('type', 'lsg')->where('college_id', $request->input('college_id'))->first(),
            'existing_society' => Organization::find($request->input('organization_id')),
            default => null,
        };
    }

    private function resolveRequestedCollegeId(Request $request, ?Organization $organization): ?string
    {
        if ($request->filled('college_id')) {
            return $request->input('college_id');
        }

        return $organization?->college_id;
    }

    private function validateProgramScope(Request $request, ?string $collegeId): void
    {
        $programIds = $request->input('program_ids', []);
        if (count($programIds) === 0) {
            return;
        }

        $invalidCount = Program::whereIn('id', $programIds)
            ->where('college_id', '!=', $collegeId)
            ->count();

        if ($invalidCount > 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'program_ids' => 'Covered programs must belong to the selected college.',
            ]);
        }
    }

    private function ensureNoDuplicatePendingApplication(Request $request, string $roleName, ?string $organizationId, ?string $collegeId): void
    {
        $exists = AdminApplication::query()
            ->where('applicant_id', $request->user()->id)
            ->where('role_name', $roleName)
            ->where('status', AdminApplication::STATUS_PENDING)
            ->where('organization_id', $organizationId)
            ->where('college_id', $collegeId)
            ->when($request->input('request_type') === 'new_society', function ($query) use ($request) {
                $query->where('organization_acronym', strtoupper($request->input('organization_acronym')));
            })
            ->exists();

        if ($exists) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'request_type' => 'You already have a pending application for this role and scope.',
            ]);
        }
    }
}
