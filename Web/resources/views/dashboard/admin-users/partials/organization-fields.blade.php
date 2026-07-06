<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div>
        <label class="form-label">Type <span class="text-(--color-destructive)">*</span></label>
        <select name="type" x-model="type" class="form-input form-select @error('type', 'organization') form-input-error @enderror" required>
            <option value="society">Society</option>
            <option value="non_academic_org">Non-academic Organization</option>
            <option value="lsg">LSG</option>
            <option value="academic_unit">Academic Unit</option>
            <option value="usg">USG</option>
            <option value="administrative_office">Administrative Office</option>
            <option value="hr_unit">HR Unit</option>
        </select>
        @error('type', 'organization') <p class="form-error" role="alert">{{ $message }}</p> @enderror
    </div>
    <div x-show="typeUsesCollege()" x-cloak>
        <label class="form-label">College <span class="text-(--color-destructive)">*</span></label>
        <select name="college_id" x-model="collegeId" class="form-input form-select @error('college_id', 'organization') form-input-error @enderror" :required="typeUsesCollege()">
            <option value="">Select college</option>
            <template x-for="college in colleges" :key="college.id">
                <option :value="college.id" x-text="college.code"></option>
            </template>
        </select>
        @error('college_id', 'organization') <p class="form-error" role="alert">{{ $message }}</p> @enderror
    </div>
    <div class="md:col-span-2">
        <label class="form-label">Name <span class="text-(--color-destructive)">*</span></label>
        <input type="text" name="name" value="{{ old('name') }}" class="form-input @error('name', 'organization') form-input-error @enderror" required>
        @error('name', 'organization') <p class="form-error" role="alert">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="form-label">Acronym <span class="text-(--color-destructive)">*</span></label>
        <input type="text" name="acronym" value="{{ old('acronym') }}" class="form-input uppercase @error('acronym', 'organization') form-input-error @enderror" required>
        @error('acronym', 'organization') <p class="form-error" role="alert">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="form-label">Status</label>
        <select name="status" class="form-input form-select @error('status', 'organization') form-input-error @enderror">
            <option value="active" @selected(old('status', 'active') === 'active')>Active</option>
            <option value="inactive" @selected(old('status') === 'inactive')>Inactive</option>
        </select>
        @error('status', 'organization') <p class="form-error" role="alert">{{ $message }}</p> @enderror
    </div>
    <div class="md:col-span-2" x-show="typeUsesPrograms()" x-cloak>
        <label class="form-label">Covered Programs</label>
        <select name="program_ids[]" multiple class="form-input min-h-28 @error('program_ids', 'organization') form-input-error @enderror">
            <template x-for="program in programsForCollege()" :key="program.id">
                <option :value="program.id" x-text="`${program.code} - ${program.name}`"></option>
            </template>
        </select>
        @error('program_ids', 'organization') <p class="form-error" role="alert">{{ $message }}</p> @enderror
        <p class="text-xs text-(--color-text-tertiary) mt-1">Hold Ctrl/Cmd to select multiple programs.</p>
    </div>
    <div class="md:col-span-2">
        <label class="form-label">Logo <span class="text-(--color-text-tertiary)">(optional)</span></label>
        <input type="file" name="logo" accept="image/*" class="form-input @error('logo', 'organization') form-input-error @enderror">
        @error('logo', 'organization') <p class="form-error" role="alert">{{ $message }}</p> @enderror
    </div>
</div>
