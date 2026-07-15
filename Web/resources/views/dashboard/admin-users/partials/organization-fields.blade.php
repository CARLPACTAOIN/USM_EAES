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
    <div x-show="typeShowsCollege()" x-cloak>
        <label class="form-label">College <span class="text-(--color-destructive)">*</span></label>
        <select name="college_id" x-model="collegeId" class="form-input form-select @error('college_id', 'organization') form-input-error @enderror" :required="typeRequiresCollege()">
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
        <div class="org-program-picker @error('program_ids', 'organization') org-program-picker-error @enderror">
            <div class="org-program-picker-head">
                <div>
                    <label id="covered-programs-label" class="form-label">Covered Programs</label>
                    <p class="org-program-picker-help">Choose the academic programs represented by this organization.</p>
                </div>
                <span class="org-program-picker-count" x-text="`${selectedProgramCount()} selected`" aria-live="polite"></span>
            </div>

            <div class="org-program-picker-toolbar">
                <div class="org-program-picker-search">
                    <label for="covered-program-search" class="sr-only">Search covered programs</label>
                    <svg aria-hidden="true" class="org-program-picker-search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21 21l-4.35-4.35M10.5 18a7.5 7.5 0 110-15 7.5 7.5 0 010 15z"/>
                    </svg>
                    <input id="covered-program-search"
                           type="search"
                           x-model="programSearch"
                           class="org-program-picker-input"
                           placeholder="Search code or program name"
                           autocomplete="off">
                </div>
                <div class="org-program-picker-actions">
                    <button type="button" class="org-program-picker-action" @click="selectAllPrograms()" :disabled="programsForCollege().length === 0">Select all</button>
                    <button type="button" class="org-program-picker-action" @click="clearPrograms()" :disabled="selectedProgramCount() === 0">Clear</button>
                </div>
            </div>

            <div class="org-program-picker-list" role="group" aria-labelledby="covered-programs-label">
                <template x-for="program in filteredProgramsForCollege()" :key="program.id">
                    <label class="org-program-option" :class="{ 'is-selected': programIds.includes(String(program.id)) }">
                        <input type="checkbox"
                               name="program_ids[]"
                               :value="program.id"
                               x-model="programIds"
                               class="org-program-option-check">
                        <span class="org-program-option-main">
                            <span class="org-program-option-code" x-text="program.code"></span>
                            <span class="org-program-option-name" x-text="program.name"></span>
                        </span>
                    </label>
                </template>

                <div class="org-program-picker-empty" x-show="typeUsesPrograms() && !collegeId">
                    Select a college to load available programs.
                </div>
                <div class="org-program-picker-empty" x-show="typeUsesPrograms() && collegeId && programsForCollege().length === 0">
                    No programs are registered under the selected college.
                </div>
                <div class="org-program-picker-empty" x-show="typeUsesPrograms() && collegeId && programsForCollege().length > 0 && filteredProgramsForCollege().length === 0">
                    No programs match your search.
                </div>
            </div>
        </div>
        @error('program_ids', 'organization') <p class="form-error" role="alert">{{ $message }}</p> @enderror
    </div>
    <div class="md:col-span-2">
        <label class="form-label">Logo <span class="text-(--color-text-tertiary)">(optional)</span></label>
        <input type="file" name="logo" accept="image/*" class="form-input @error('logo', 'organization') form-input-error @enderror">
        @error('logo', 'organization') <p class="form-error" role="alert">{{ $message }}</p> @enderror
    </div>
</div>
