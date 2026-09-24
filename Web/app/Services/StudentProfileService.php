<?php

namespace App\Services;

use App\Models\OrganizationMembership;
use App\Models\Program;
use App\Models\SchoolYear;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StudentProfileService
{
    /**
     * @param  array{college_id: string, department_id: string, program_id: string, organization_id: string, year_level: string, student_id_number: string, qr_code_value: string}  $data
     */
    public function update(User $user, array $data): User
    {
        $activeSchoolYear = SchoolYear::query()
            ->where('status', SchoolYear::STATUS_ACTIVE)
            ->first();

        if (! $activeSchoolYear) {
            throw ValidationException::withMessages([
                'school_year' => 'Student onboarding requires an active School Year.',
            ]);
        }

        $program = Program::findOrFail($data['program_id']);

        return DB::transaction(function () use ($user, $data, $program, $activeSchoolYear): User {
            $user->update([
                'college_id' => $data['college_id'],
                'department_id' => $data['department_id'],
                'program_id' => $data['program_id'],
                'organization_id' => $data['organization_id'],
                'program_code' => $program->code,
                'year_level' => $data['year_level'],
                'student_id_number' => $data['student_id_number'],
                'qr_code_value' => $data['qr_code_value'],
            ]);

            $user->organizationMemberships()
                ->where('source', 'profile')
                ->where('membership_type', 'primary_academic')
                ->where(function ($query) use ($activeSchoolYear): void {
                    $query->where('school_year_id', $activeSchoolYear->id)
                        ->orWhereNull('school_year_id');
                })
                ->update(['status' => 'inactive']);

            $user->organizationMemberships()->updateOrCreate(
                [
                    'organization_id' => $data['organization_id'],
                    'membership_type' => 'primary_academic',
                    'source' => 'profile',
                    'school_year_id' => $activeSchoolYear->id,
                ],
                [
                    'college_id' => $data['college_id'],
                    'department_id' => $data['department_id'],
                    'program_id' => $data['program_id'],
                    'year_level' => $data['year_level'],
                    'status' => OrganizationMembership::STATUS_ACTIVE,
                    'verified_at' => now(),
                ]
            );

            return $user->fresh();
        });
    }
}
