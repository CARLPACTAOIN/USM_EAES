<x-layouts.app :title="'Performance Intelligence — EAES'">
    @livewire('dashboard.analytics-overview-filters', [
        'selectedSchoolYear' => request('school_year', request('school_year_id', '')),
        'selectedSemester' => request('semester', ''),
        'selectedCollegeId' => request('college_id', ''),
        'selectedDepartmentId' => request('department_id', ''),
        'selectedOrgId' => request('organization_id', ''),
        'selectedProgramId' => request('program_id', ''),
        'selectedYearLevel' => request('year_level', ''),
        'selectedEventType' => request('event_type', ''),
    ])
</x-layouts.app>
