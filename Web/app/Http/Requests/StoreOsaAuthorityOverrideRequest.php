<?php

namespace App\Http\Requests;

use App\Models\Organization;
use App\Models\OsaAuthorityOverride;
use App\Services\AdminAssignmentService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreOsaAuthorityOverrideRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasRole('Super Admin (OSA)') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $actionsRequiringTarget = implode(',', [
            OsaAuthorityOverride::ACTION_GRANT,
            OsaAuthorityOverride::ACTION_REPLACE,
        ]);
        $actionsRequiringRole = implode(',', [
            OsaAuthorityOverride::ACTION_GRANT,
            OsaAuthorityOverride::ACTION_REPLACE,
        ]);

        return [
            'action' => ['required', Rule::in(OsaAuthorityOverride::actions())],
            'target_user_id' => ["required_if:action,{$actionsRequiringTarget}", 'nullable', 'uuid', 'exists:users,id'],
            'role_name' => ["required_if:action,{$actionsRequiringRole}", 'nullable', Rule::in(AdminAssignmentService::OSA_ASSIGNABLE_ROLES)],
            'assignment_id' => ['required_if:action,'.OsaAuthorityOverride::ACTION_REVOKE, 'nullable', 'uuid', 'exists:admin_assignments,id'],
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'college_id' => ['nullable', 'uuid', 'exists:colleges,id'],
            'department_id' => ['nullable', 'uuid', 'exists:departments,id'],
            'school_year_id' => ['nullable', 'uuid', 'exists:school_years,id'],
            'academic_year' => ['required_unless:action,'.OsaAuthorityOverride::ACTION_REVOKE, 'nullable', 'string', 'max:20'],
            'term_start' => ['nullable', 'date'],
            'term_end' => ['nullable', 'date', 'after_or_equal:term_start'],
            'position_title' => ['nullable', 'string', 'max:120'],
            'reason' => ['required', 'string', 'min:15', 'max:2000'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],
            'is_primary_admin' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $action = $this->input('action');

            if (in_array($action, [OsaAuthorityOverride::ACTION_GRANT, OsaAuthorityOverride::ACTION_REPLACE], true)) {
                $this->validateProjectedAuthorityScope($validator);
            }

            if ($action === OsaAuthorityOverride::ACTION_LOCK) {
                if (! $this->filled('organization_id')) {
                    $validator->errors()->add('organization_id', 'Select the organization roster scope to lock.');
                }

                if (! $this->filled('school_year_id')) {
                    $validator->errors()->add('school_year_id', 'Select the School Year for the roster lock.');
                }
            }
        });
    }

    private function validateProjectedAuthorityScope(Validator $validator): void
    {
        if (! $this->filled('school_year_id')) {
            $validator->errors()->add('school_year_id', 'Select the School Year for this authority override.');
        }

        $role = $this->input('role_name');
        $organization = $this->filled('organization_id')
            ? Organization::find($this->input('organization_id'))
            : null;

        if (! $organization) {
            $validator->errors()->add('organization_id', 'Select the exact organization scope for this authority override.');

            return;
        }

        if ($role === 'Society Admin' && ! in_array($organization->type, ['society', 'non_academic_org'], true)) {
            $validator->errors()->add('organization_id', 'Society Admin overrides must target a society or recognized non-academic organization.');
        }

        if ($role === 'LSG Admin' && $organization->type !== 'lsg') {
            $validator->errors()->add('organization_id', 'LSG Admin overrides must target an LSG organization.');
        }

        if ($role === 'USG Admin' && $organization->type !== 'usg') {
            $validator->errors()->add('organization_id', 'USG Admin overrides must target the USG organization.');
        }

        if ($role === 'Academic Unit Admin' && $organization->type !== 'academic_unit') {
            $validator->errors()->add('organization_id', 'Academic Unit Admin overrides must target an academic unit organization.');
        }

        if ($role === 'Office Event Admin' && $organization->type !== 'administrative_office') {
            $validator->errors()->add('organization_id', 'Office Event Admin overrides must target an administrative office.');
        }

        if ($this->filled('college_id') && $organization->college_id && $organization->college_id !== $this->input('college_id')) {
            $validator->errors()->add('college_id', 'Select the College that owns the selected organization.');
        }

        if ($this->filled('department_id') && $organization->department_id && $organization->department_id !== $this->input('department_id')) {
            $validator->errors()->add('department_id', 'Select the Department that owns the selected organization.');
        }
    }
}
