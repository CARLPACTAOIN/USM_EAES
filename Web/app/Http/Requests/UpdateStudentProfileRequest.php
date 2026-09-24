<?php

namespace App\Http\Requests;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Program;
use App\Models\SchoolYear;
use App\Support\YearLevels;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateStudentProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasRole('Student') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->user();

        return [
            'college_id' => ['required', 'uuid', 'exists:colleges,id'],
            'department_id' => ['required', 'uuid', 'exists:departments,id'],
            'program_id' => ['required', 'uuid', 'exists:programs,id'],
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'year_level' => ['required', Rule::in(YearLevels::values())],
            'student_id_number' => [
                'required',
                'string',
                'max:20',
                Rule::unique('users', 'student_id_number')->ignore($user?->id),
            ],
            'qr_code_value' => [
                'required',
                'string',
                'max:255',
                Rule::unique('users', 'qr_code_value')->ignore($user?->id),
            ],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if (! SchoolYear::query()->where('status', SchoolYear::STATUS_ACTIVE)->exists()) {
                    $validator->errors()->add('school_year', 'Student onboarding requires an active School Year.');
                }

                $department = Department::find($this->input('department_id'));
                $program = Program::find($this->input('program_id'));
                $organization = Organization::find($this->input('organization_id'));

                if (! $department || ! $program || ! $organization) {
                    return;
                }

                if ($department->college_id !== $this->input('college_id')) {
                    $validator->errors()->add('department_id', 'Select a department that belongs to the chosen college.');
                }

                if ($program->department_id !== $department->id) {
                    $validator->errors()->add('program_id', 'Select a program that belongs to the chosen department.');
                }

                if ($organization->college_id !== null && $organization->college_id !== $this->input('college_id')) {
                    $validator->errors()->add('organization_id', 'Select a society/organization that belongs to the chosen college.');
                }

                if ($organization->status !== null && $organization->status !== 'active') {
                    $validator->errors()->add('organization_id', 'Select an active society/organization.');
                }

                if (in_array($organization->type, ['society', 'academic_unit'], true)
                    && ($organization->department_id === null || $organization->department_id !== $department->id)) {
                    $validator->errors()->add('organization_id', 'Select a society/organization that belongs to the chosen department.');
                }

                if (! $organization->programs()->where('program_id', $program->id)->exists()) {
                    $validator->errors()->add('organization_id', 'The selected society does not cover your chosen academic program.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'student_id_number.unique' => 'This Student ID number is already registered to another account.',
            'qr_code_value.unique' => 'This QR code value is already registered to another account.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'student_id_number' => trim((string) $this->input('student_id_number')),
            'qr_code_value' => trim((string) $this->input('qr_code_value')),
        ]);
    }
}
