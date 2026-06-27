<x-layouts.app :title="'Admin Applications - EAES'">
    <div class="flex flex-col lg:flex-row lg:items-start gap-6">
        <section class="card flex-1">
            <div class="card-header">
                <h1 class="text-xl font-display text-(--color-text-primary)">Apply for Admin Role</h1>
            </div>

            <form method="POST" action="{{ route('portal.admin-applications.store') }}" enctype="multipart/form-data" class="card-body space-y-5">
                @csrf

                @if($errors->any())
                    <div class="proposal-error-summary" role="alert">
                        <p>Review the form fields and try again.</p>
                    </div>
                @endif

                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label for="request_type" class="form-label">Application Type <span class="text-(--color-destructive)">*</span></label>
                        <select name="request_type" id="request_type" class="form-input form-select @error('request_type') form-input-error @enderror" required>
                            <option value="">Select application</option>
                            <option value="existing_usg" @selected(old('request_type') === 'existing_usg')>USG Admin</option>
                            <option value="existing_aro" @selected(old('request_type') === 'existing_aro')>ARO Admin</option>
                            <option value="existing_lsg" @selected(old('request_type') === 'existing_lsg')>LSG Admin</option>
                            <option value="existing_society" @selected(old('request_type') === 'existing_society')>Society Admin - Existing Organization</option>
                            <option value="new_society" @selected(old('request_type') === 'new_society')>Register New Society</option>
                        </select>
                        @error('request_type') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="academic_year" class="form-label">Academic Year <span class="text-(--color-destructive)">*</span></label>
                        <input type="text" name="academic_year" id="academic_year" value="{{ old('academic_year', now()->year . '-' . (now()->year + 1)) }}" class="form-input @error('academic_year') form-input-error @enderror" required>
                        @error('academic_year') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="college_id" class="form-label">College</label>
                        <select name="college_id" id="college_id" class="form-input form-select @error('college_id') form-input-error @enderror">
                            <option value="">Select college</option>
                            @foreach($colleges as $college)
                                <option value="{{ $college->id }}" @selected(old('college_id') === $college->id)>{{ $college->code }} - {{ $college->name }}</option>
                            @endforeach
                        </select>
                        @error('college_id') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="organization_id" class="form-label">Existing Organization</label>
                        <select name="organization_id" id="organization_id" class="form-input form-select @error('organization_id') form-input-error @enderror">
                            <option value="">Select organization</option>
                            @foreach($organizations as $org)
                                <option value="{{ $org->id }}" @selected(old('organization_id') === $org->id)>
                                    {{ $org->acronym }} - {{ $org->name }} @if($org->college) ({{ $org->college->code }}) @endif
                                </option>
                            @endforeach
                        </select>
                        @error('organization_id') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="organization_name" class="form-label">New Society Name</label>
                        <input type="text" name="organization_name" id="organization_name" value="{{ old('organization_name') }}" class="form-input @error('organization_name') form-input-error @enderror" maxlength="255">
                        @error('organization_name') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="organization_acronym" class="form-label">New Society Acronym</label>
                        <input type="text" name="organization_acronym" id="organization_acronym" value="{{ old('organization_acronym') }}" class="form-input @error('organization_acronym') form-input-error @enderror" maxlength="50">
                        @error('organization_acronym') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="adviser_name" class="form-label">Adviser Name</label>
                        <input type="text" name="adviser_name" id="adviser_name" value="{{ old('adviser_name') }}" class="form-input @error('adviser_name') form-input-error @enderror" maxlength="255">
                        @error('adviser_name') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="position_title" class="form-label">Position Title</label>
                        <input type="text" name="position_title" id="position_title" value="{{ old('position_title') }}" class="form-input @error('position_title') form-input-error @enderror" maxlength="120">
                        @error('position_title') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="term_start" class="form-label">Term Start</label>
                        <input type="date" name="term_start" id="term_start" value="{{ old('term_start') }}" class="form-input @error('term_start') form-input-error @enderror">
                        @error('term_start') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="term_end" class="form-label">Term End</label>
                        <input type="date" name="term_end" id="term_end" value="{{ old('term_end') }}" class="form-input @error('term_end') form-input-error @enderror">
                        @error('term_end') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label for="program_ids" class="form-label">Covered Programs</label>
                    <select name="program_ids[]" id="program_ids" multiple class="form-input form-select min-h-36 @error('program_ids') form-input-error @enderror">
                        @foreach($programs as $program)
                            <option value="{{ $program->id }}" @selected(in_array($program->id, old('program_ids', []), true))>
                                {{ $program->college?->code }} - {{ $program->code }} - {{ $program->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('program_ids') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                    @error('program_ids.*') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>

                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label for="proof_document" class="form-label">Proof Document <span class="text-(--color-destructive)">*</span></label>
                        <input type="file" name="proof_document" id="proof_document" class="form-input @error('proof_document') form-input-error @enderror" required accept=".pdf,.jpg,.jpeg,.png">
                        @error('proof_document') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="logo" class="form-label">Organization Logo</label>
                        <input type="file" name="logo" id="logo" class="form-input @error('logo') form-input-error @enderror" accept=".png,.jpg,.jpeg,.webp">
                        @error('logo') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="btn btn-primary">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M5 13l4 4L19 7"/></svg>
                        Submit Application
                    </button>
                </div>
            </form>
        </section>

        <aside class="card lg:w-96">
            <div class="card-header">
                <h2 class="font-semibold text-(--color-text-primary)">My Applications</h2>
            </div>
            <div class="card-body p-0">
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Role</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($applications as $application)
                                <tr>
                                    <td>
                                        <p class="font-medium text-(--color-text-primary)">{{ $application->role_name }}</p>
                                        <p class="text-xs text-(--color-text-secondary)">{{ $application->organization?->acronym ?? $application->organization_acronym ?? $application->college?->code ?? 'University-wide' }}</p>
                                    </td>
                                    <td><span class="badge badge-{{ $application->status === 'approved' ? 'approved' : ($application->status === 'rejected' ? 'rejected' : 'pending') }}">{{ ucfirst($application->status) }}</span></td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="2" class="text-sm text-(--color-text-secondary)">No applications submitted.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @if($applications->hasPages())
                <div class="card-footer">{{ $applications->links() }}</div>
            @endif
        </aside>
    </div>
</x-layouts.app>
