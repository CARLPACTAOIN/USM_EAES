<x-layouts.app :title="'My Profile — EAES'">
    <div class="mb-8">
        <h1 class="text-2xl font-display text-(--color-text-primary)">My Profile</h1>
        <p class="text-sm text-(--color-text-secondary) mt-1">Register your student details and physical ID QR code</p>
    </div>

    <div class="max-w-2xl">
        <form method="POST" action="{{ route('portal.profile.update') }}" class="card">
            @csrf
            <div class="card-header">
                <h2 class="font-semibold text-(--color-text-primary)">Student Information</h2>
            </div>

            <div class="card-body space-y-5">
                <div x-data="dependentSelect(
                    @js(json_encode($orgsByCollege)),
                    @js(json_encode($programsByCollege)),
                    @js(old('college_id', $user->college_id ?? $user->organization?->college_id)),
                    @js(old('program_id', $user->program_id)),
                    @js(old('organization_id', $user->organization_id))
                )">
                    {{-- College --}}
                    <div class="mb-5">
                        <label for="college_id" class="form-label">College <span class="text-(--color-destructive)">*</span></label>
                        <select name="college_id" id="college_id"
                                x-model="selectedCollege"
                                class="form-input form-select @error('college_id') form-input-error @enderror">
                            <option value="">Select your college</option>
                            @foreach($colleges as $college)
                            <option value="{{ $college->id }}">
                                {{ $college->code }} — {{ $college->name }}
                            </option>
                            @endforeach
                        </select>
                        @error('college_id') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                    </div>

                    {{-- Program --}}
                    <div class="mb-5">
                        <label for="program_id" class="form-label">Academic Program <span class="text-(--color-destructive)">*</span></label>
                        <select name="program_id" id="program_id"
                                x-model="selectedProgram"
                                class="form-input form-select @error('program_id') form-input-error @enderror">
                            <option value="">Select your program</option>
                            @foreach($programsByCollege as $colId => $progs)
                                @foreach($progs as $prog)
                                    <option value="{{ $prog['id'] }}" x-show="selectedCollege === '{{ $colId }}'">
                                        {{ $prog['code'] }} — {{ $prog['name'] }}
                                    </option>
                                @endforeach
                            @endforeach
                        </select>
                        @error('program_id') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                    </div>

                    {{-- Organization --}}
                    <div class="mb-5">
                        <label for="organization_id" class="form-label">Organization / Society <span class="text-(--color-destructive)">*</span></label>
                        <select name="organization_id" id="organization_id"
                                x-model="selectedOrg"
                                class="form-input form-select @error('organization_id') form-input-error @enderror">
                            <option value="">Select your organization</option>
                            @foreach($orgsByCollege as $colId => $orgs)
                                @foreach($orgs as $org)
                                    <option value="{{ $org['id'] }}" x-show="selectedCollege === '{{ $colId }}' || '{{ $colId }}' === 'university_wide'">
                                        {{ $org['acronym'] }} — {{ $org['name'] }}
                                    </option>
                                @endforeach
                            @endforeach
                        </select>
                        @error('organization_id') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                    </div>
                </div>

                {{-- Student ID Number --}}
                <div>
                    <label for="student_id_number" class="form-label">Student ID Number <span class="text-(--color-destructive)">*</span></label>
                    <input type="text" name="student_id_number" id="student_id_number"
                           value="{{ old('student_id_number', $user->student_id_number) }}"
                           placeholder="e.g. 26-72659"
                           class="form-input font-data @error('student_id_number') form-input-error @enderror"
                           maxlength="20" required>
                    @error('student_id_number') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>

                {{-- QR Code Value --}}
                <div x-data="profileQrScanner(@js(old('qr_code_value', $user->qr_code_value)))">
                    <label class="form-label">Physical ID QR Code <span class="text-(--color-destructive)">*</span></label>

                    {{-- Mode Toggle --}}
                    <div class="flex gap-2 mb-3">
                        <button type="button" @click="mode = 'scanner'"
                                :class="mode === 'scanner' ? 'btn-primary' : 'btn-secondary'"
                                class="btn btn-sm">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            Webcam Scan
                        </button>
                        <button type="button" @click="mode = 'manual'"
                                :class="mode === 'manual' ? 'btn-primary' : 'btn-secondary'"
                                class="btn btn-sm">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            Manual Entry
                        </button>
                    </div>

                    {{-- Webcam Scanner --}}
                    <div x-show="mode === 'scanner'" x-cloak>
                        <div x-ref="reader" class="qr-scanner-container mb-3"></div>
                        <div class="flex gap-2">
                            <button type="button" @click="startScanner()" x-show="!scanning"
                                    class="btn btn-secondary btn-sm">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                Start Camera
                            </button>
                            <button type="button" @click="stopScanner()" x-show="scanning"
                                    class="btn btn-destructive btn-sm">
                                Stop Camera
                            </button>
                        </div>
                        <p x-show="cameraError" x-text="cameraError" class="form-error mt-2" role="alert"></p>
                    </div>

                    {{-- Manual Entry --}}
                    <div x-show="mode === 'manual'" x-cloak>
                        <input type="text" x-model="scannedValue"
                               placeholder="Enter QR code value manually"
                               class="form-input font-data">
                        <p class="form-helper">Type or paste the value from your physical ID QR code</p>
                    </div>

                    {{-- Hidden input for form submission --}}
                    <input type="hidden" name="qr_code_value" :value="scannedValue">

                    {{-- Scanned Value Display --}}
                    <div x-show="scannedValue" x-cloak class="mt-3 p-3 rounded-lg flex items-center gap-3" style="background-color: var(--color-success-light);">
                        <svg class="w-5 h-5 shrink-0" style="color: var(--color-success);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <div>
                            <p class="text-xs font-medium" style="color: var(--color-success);">QR Value Captured</p>
                            <p class="font-data text-sm text-(--color-text-primary) mt-0.5" x-text="scannedValue"></p>
                        </div>
                    </div>

                    @error('qr_code_value') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="card-footer flex items-center justify-end gap-3">
                <a href="{{ route('portal') }}" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    Save Profile
                </button>
            </div>
        </form>
    </div>
</x-layouts.app>
