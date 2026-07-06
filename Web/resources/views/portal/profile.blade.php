<x-layouts.app :title="'My Profile — EAES'">
    @php
        $collegeId = old('college_id', $user->college_id ?? $user->organization?->college_id);
        $programId = old('program_id', $user->program_id);
        $organizationId = old('organization_id', $user->organization_id);
        $studentId = old('student_id_number', $user->student_id_number);
        $qrValue = old('qr_code_value', $user->qr_code_value);

        $checklist = [
            [
                'label' => 'College selected',
                'note' => 'Your academic unit at USM',
                'done' => filled($collegeId),
            ],
            [
                'label' => 'Program selected',
                'note' => 'Must match your enrolled course',
                'done' => filled($programId),
            ],
            [
                'label' => 'Organization linked',
                'note' => 'Society or university-wide org',
                'done' => filled($organizationId),
            ],
            [
                'label' => 'Student ID saved',
                'note' => 'Official USM ID number',
                'done' => filled($studentId),
            ],
            [
                'label' => 'Physical QR registered',
                'note' => 'Scan or enter your ID QR value',
                'done' => filled($qrValue),
            ],
        ];

        $completedSteps = collect($checklist)->where('done', true)->count();
        $totalSteps = count($checklist);
        $progressPercent = $totalSteps > 0 ? round(($completedSteps / $totalSteps) * 100) : 0;
        $progressOffset = 100 - $progressPercent;
        $profileComplete = $completedSteps === $totalSteps;
        $initials = collect(explode(' ', trim($user->name)))
            ->filter()
            ->take(2)
            ->map(fn ($part) => strtoupper(substr($part, 0, 1)))
            ->implode('');
    @endphp

    <div x-data="{
        isEditing: @js($errors->any() || !$profileComplete),
        showQrInputs: @js($errors->any() || !filled($qrValue))
    }">
        <!-- Header Section (Outside and above the main content grid) -->
        <div class="portal-page-header mb-6 flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="portal-page-kicker" x-text="isEditing && !@js($profileComplete) ? 'Student onboarding' : 'Student workspace'"></p>
                <h1 class="text-2xl font-display text-(--color-text-primary)">My Profile</h1>
                <p class="text-sm text-(--color-text-secondary) mt-1 max-w-2xl"
                   x-text="isEditing ? 'Register your academic details and physical ID QR code so attendance scans can be matched to your account.' : 'View your academic profile and attendance identity information.'">
                </p>
                <div class="portal-page-meta mt-2 flex flex-wrap gap-2">
                    <span class="portal-meta-chip">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        {{ $user->name }}
                    </span>
                    <span class="portal-meta-chip">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        {{ $user->email }}
                    </span>
                </div>
            </div>
            <button type="button"
                    x-show="!isEditing"
                    @click="isEditing = true"
                    class="btn btn-primary cursor-pointer shrink-0">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                Edit Profile
            </button>
        </div>

        <div class="portal-layout">
            <!-- Left Side: Read-Only OR Edit Form -->
            <div class="min-w-0">
                <!-- Read-Only Profile View Mode -->
                <div x-show="!isEditing" x-cloak class="card portal-form-stack overflow-hidden">
                    <div class="admin-access-section-header">
                        <div>
                            <h2 class="font-semibold text-(--color-text-primary)">Profile Summary</h2>
                            <p class="text-xs text-(--color-text-secondary) mt-0.5">Your official academic and scanning identity</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="badge badge-approved">Complete</span>
                        </div>
                    </div>

                    <div class="card-body p-6 space-y-6">
                        <!-- Profile Summary Row -->
                        <div class="flex items-center gap-4 p-4 rounded-xl bg-(--color-surface-raised) border border-(--color-border)">
                            <div class="eaes-avatar w-16 h-16 rounded-xl flex items-center justify-center text-xl font-bold text-white shrink-0" style="background: linear-gradient(135deg, var(--color-accent), #6366f1);">
                                {{ $initials ?: 'ST' }}
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-lg font-bold text-(--color-text-primary) truncate">{{ $user->name }}</h3>
                                <p class="text-sm text-(--color-text-secondary) truncate">{{ $user->email }}</p>
                                <div class="flex items-center gap-2 mt-2 flex-wrap">
                                    <span class="badge badge-approved text-[10px] px-2 py-0.5 font-semibold">Profile Complete</span>
                                    <span class="badge badge-approved text-[10px] px-2 py-0.5 font-semibold">QR Registered</span>
                                </div>
                            </div>
                        </div>

                        <!-- Academic Affiliation Info -->
                        <div class="space-y-3">
                            <h3 class="text-xs font-semibold uppercase tracking-wider text-(--color-text-tertiary) flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                                Academic Affiliation
                            </h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="p-4 rounded-xl border border-(--color-border) bg-(--color-surface)">
                                    <p class="text-xs text-(--color-text-secondary) font-medium">College</p>
                                    <p class="font-semibold text-(--color-text-primary) mt-1 text-sm">{{ $user->college?->name ?? 'Not set' }}</p>
                                    @if($user->college?->code)
                                        <span class="text-xs text-(--color-text-tertiary) font-data block mt-1">{{ $user->college->code }}</span>
                                    @endif
                                </div>
                                <div class="p-4 rounded-xl border border-(--color-border) bg-(--color-surface)">
                                    <p class="text-xs text-(--color-text-secondary) font-medium">Academic Program</p>
                                    <p class="font-semibold text-(--color-text-primary) mt-1 text-sm">{{ $user->program?->name ?? 'Not set' }}</p>
                                    @if($user->program?->code)
                                        <span class="text-xs text-(--color-text-tertiary) font-data block mt-1">{{ $user->program->code }}</span>
                                    @endif
                                </div>
                                <div class="p-4 rounded-xl border border-(--color-border) bg-(--color-surface) md:col-span-2">
                                    <p class="text-xs text-(--color-text-secondary) font-medium">Organization / Society</p>
                                    <p class="font-semibold text-(--color-text-primary) mt-1 text-sm">{{ $user->organization?->name ?? 'Not set' }}</p>
                                    @if($user->organization?->acronym)
                                        <span class="text-xs text-(--color-text-tertiary) font-data block mt-1">{{ $user->organization->acronym }} · {{ ucfirst($user->organization->type) }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <!-- Attendance Identity Info -->
                        <div class="space-y-3">
                            <h3 class="text-xs font-semibold uppercase tracking-wider text-(--color-text-tertiary) flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m0 11v1m5-10v.01M7 4v.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                Attendance Identity
                            </h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="p-4 rounded-xl border border-(--color-border) bg-(--color-surface)">
                                    <p class="text-xs text-(--color-text-secondary) font-medium">Student ID Number</p>
                                    <p class="font-data font-bold text-(--color-text-primary) mt-1.5 text-base">{{ $user->student_id_number ?? 'Not set' }}</p>
                                </div>
                                <div class="p-4 rounded-xl border border-(--color-border) bg-(--color-surface)">
                                    <p class="text-xs text-(--color-text-secondary) font-medium">Physical QR Registration</p>
                                    <div class="flex items-center gap-2 mt-1.5">
                                        <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                        </svg>
                                        <span class="font-semibold text-emerald-400 text-sm">QR Code Registered</span>
                                    </div>
                                    <span class="text-xs text-(--color-text-tertiary) block mt-1">Matched scans will automatically credit attendance</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card-footer border-t border-(--color-border) bg-(--color-surface-raised) p-4 flex justify-end">
                        <button type="button" @click="isEditing = true" class="btn btn-primary">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            Edit Profile
                        </button>
                    </div>
                </div>

                <!-- Onboarding/Edit Mode -->
                <form method="POST" action="{{ route('portal.profile.update') }}" class="card portal-form-stack" x-show="isEditing" x-transition x-cloak>
                    @csrf

                    @if($errors->any())
                        <div class="proposal-error-summary mx-6 mt-6" role="alert" aria-live="assertive">
                            <p>Please fix the highlighted fields before saving.</p>
                            <ul>
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="card-body space-y-4">
                        <div class="portal-info-callout">
                            <svg class="w-5 h-5 shrink-0" style="color: var(--color-accent);" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <div>
                                <strong>Use your official USM records</strong>
                                <p class="text-sm">College, program, and organization must match your enrollment. Your physical ID QR code is scanned at events for attendance.</p>
                            </div>
                        </div>

                        <section class="proposal-modal-section">
                            <h2 class="proposal-section-title">
                                <span class="proposal-section-step">1</span>
                                Academic affiliation
                            </h2>

                            <div x-data="dependentSelect(
                                @js(json_encode($orgsByCollege)),
                                @js(json_encode($programsByCollege)),
                                @js($collegeId),
                                @js($programId),
                                @js($organizationId)
                            )" class="proposal-grid">
                                <div>
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

                                <div>
                                    <label for="program_id" class="form-label">Academic program <span class="text-(--color-destructive)">*</span></label>
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

                                <div class="proposal-grid-full">
                                    <label for="organization_id" class="form-label">Organization / society <span class="text-(--color-destructive)">*</span></label>
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
                        </section>

                        <section class="proposal-modal-section">
                            <h2 class="proposal-section-title">
                                <span class="proposal-section-step">2</span>
                                Student identity
                            </h2>

                            <div>
                                <label for="student_id_number" class="form-label">Student ID number <span class="text-(--color-destructive)">*</span></label>
                                <input type="text" name="student_id_number" id="student_id_number"
                                       value="{{ $studentId }}"
                                       placeholder="e.g. 23-72634"
                                       class="form-input font-data @error('student_id_number') form-input-error @enderror"
                                       maxlength="20" required autocomplete="off">
                                <p class="form-helper">Enter the ID number printed on your USM student ID card.</p>
                                @error('student_id_number') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                            </div>
                        </section>

                        <section class="proposal-modal-section" x-data="profileQrScanner(@js($qrValue))">
                            <h2 class="proposal-section-title">
                                <span class="proposal-section-step">3</span>
                                Physical ID QR code
                            </h2>

                            @if(filled($qrValue))
                                <div x-show="!showQrInputs" class="p-4 rounded-xl border border-emerald-500/20 bg-emerald-500/5 mb-4">
                                    <div class="flex items-start gap-3">
                                        <div class="w-8 h-8 rounded-full bg-emerald-500/10 text-emerald-400 flex items-center justify-center shrink-0 mt-0.5">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                            </svg>
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-sm font-semibold text-emerald-400">QR Code Registered</p>
                                            <p class="text-xs text-(--color-text-secondary) mt-0.5">Your physical ID QR code value is securely registered. You do not need to scan again unless you are changing IDs.</p>
                                            <button type="button" @click="showQrInputs = true; startScanner();" class="btn btn-secondary btn-sm mt-3">
                                                Update / Rescan QR Code
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            @endif

                            <div x-show="showQrInputs" x-cloak>
                                <p class="text-sm text-(--color-text-secondary) mb-4">
                                    Scan the QR code on your physical ID or enter the value manually if camera access is unavailable.
                                </p>

                                <div class="portal-segmented mb-4" role="tablist" aria-label="QR capture method">
                                    <button type="button"
                                            @click="mode = 'scanner'; startScanner();"
                                            :class="mode === 'scanner' ? 'is-active' : ''"
                                            class="portal-segment"
                                            role="tab"
                                            :aria-selected="mode === 'scanner'">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                        Webcam scan
                                    </button>
                                    <button type="button"
                                            @click="mode = 'manual'; stopScanner();"
                                            :class="mode === 'manual' ? 'is-active' : ''"
                                            class="portal-segment"
                                            role="tab"
                                            :aria-selected="mode === 'manual'">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                        Manual entry
                                    </button>
                                </div>

                                <div x-show="mode === 'scanner'" x-cloak>
                                    <div x-ref="reader" class="qr-scanner-container mb-3" aria-label="QR scanner preview"></div>
                                    <div class="flex flex-wrap gap-2">
                                        <button type="button" @click="startScanner()" x-show="!scanning"
                                                class="btn btn-secondary btn-sm">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                            Start camera
                                        </button>
                                        <button type="button" @click="stopScanner()" x-show="scanning"
                                                class="btn btn-destructive btn-sm">
                                            Stop camera
                                        </button>
                                    </div>
                                    <p x-show="cameraError" x-text="cameraError" class="form-error mt-2" role="alert"></p>
                                </div>

                                <div x-show="mode === 'manual'" x-cloak>
                                    <label for="qr_manual_value" class="form-label">QR code value</label>
                                    <input type="text" id="qr_manual_value" x-model="scannedValue"
                                           placeholder="Enter QR code value manually"
                                           class="form-input font-data">
                                    <p class="form-helper">Type or paste the value encoded in your physical ID QR code.</p>
                                </div>
                            </div>

                            <input type="hidden" name="qr_code_value" :value="scannedValue">

                            <div x-show="scannedValue" x-cloak class="mt-4 p-3 rounded-lg flex items-center gap-3" style="background-color: var(--color-success-light);">
                                <svg class="w-5 h-5 shrink-0" style="color: var(--color-success);" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <div>
                                    <p class="text-xs font-medium" style="color: var(--color-success);">QR value captured</p>
                                    <p class="font-data text-sm text-(--color-text-primary) mt-0.5 break-all" x-text="scannedValue"></p>
                                </div>
                            </div>

                            @error('qr_code_value') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        </section>
                    </div>

                    <div class="card-footer portal-form-actions">
                        <button type="button"
                                @click="if (@js($profileComplete)) { isEditing = false; } else { window.location.href = @js(route('portal')); }"
                                class="btn btn-secondary cursor-pointer">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary cursor-pointer">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            Save profile
                        </button>
                    </div>
                </form>
            </div>

            <!-- Right Column: Sidebar (Progress and Info) -->
            <aside class="portal-side-panel">
                <!-- Progress Card -->
                <div class="card portal-progress-card">
                    <div class="portal-progress-ring">
                        <div class="portal-progress-meter" aria-hidden="true">
                            <svg viewBox="0 0 36 36">
                                <circle class="portal-progress-track" cx="18" cy="18" r="15.9155"></circle>
                                <circle class="portal-progress-fill" cx="18" cy="18" r="15.9155"
                                        stroke-dasharray="100"
                                        stroke-dashoffset="{{ $progressOffset }}"></circle>
                            </svg>
                            <span class="portal-progress-value">{{ $progressPercent }}%</span>
                        </div>
                        <div>
                            <p class="font-semibold text-(--color-text-primary)"
                               x-text="isEditing ? '{{ $profileComplete ? 'Profile complete' : 'Profile in progress' }}' : 'Identity Verified'">
                            </p>
                            <p class="text-xs text-(--color-text-secondary) mt-0.5"
                               x-text="isEditing ? '{{ $completedSteps }} of {{ $totalSteps }} required items saved' : 'All required academic and identity records are linked.'">
                            </p>
                        </div>
                    </div>
                    <div class="portal-checklist" x-show="isEditing">
                        @foreach($checklist as $item)
                            <div class="portal-check-item {{ $item['done'] ? 'is-complete' : '' }}">
                                <span class="portal-check-icon {{ $item['done'] ? 'is-complete' : 'is-pending' }}">
                                    @if($item['done'])
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                    @else
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01"/></svg>
                                    @endif
                                </span>
                                <div>
                                    <p class="portal-check-label">{{ $item['label'] }}</p>
                                    <p class="portal-check-note">{{ $item['note'] }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <!-- Account snapshot card -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="font-semibold text-(--color-text-primary)"
                            x-text="isEditing ? 'Account snapshot' : 'Identity summary'">
                        </h2>
                    </div>
                    <div class="card-body space-y-4">
                        <div class="flex items-center gap-3">
                            <div class="eaes-avatar w-12 h-12 rounded-lg flex items-center justify-center text-sm font-bold text-white shrink-0">
                                {{ $initials ?: 'ST' }}
                            </div>
                            <div class="min-w-0">
                                <p class="font-semibold text-(--color-text-primary) truncate">{{ $user->name }}</p>
                                <p class="text-xs text-(--color-text-secondary) truncate">{{ $user->email }}</p>
                            </div>
                        </div>

                        <div class="proposal-detail-grid">
                            <div class="proposal-detail-item">
                                <span class="proposal-detail-label">Student ID</span>
                                <span class="proposal-detail-value font-data">{{ $studentId ?: 'Not set' }}</span>
                            </div>
                            <div class="proposal-detail-item">
                                <span class="proposal-detail-label">Organization</span>
                                <span class="proposal-detail-value">{{ $user->organization?->acronym ?? 'Not linked' }}</span>
                            </div>
                            <div class="proposal-detail-item proposal-detail-wide">
                                <span class="proposal-detail-label">QR status</span>
                                <span class="proposal-detail-value">{{ filled($qrValue) ? 'Registered' : 'Pending registration' }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Privacy card/callout -->
                <div class="portal-info-callout">
                    <svg class="w-5 h-5 shrink-0" style="color: var(--color-accent);" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    <div>
                        <strong>Your data stays private</strong>
                        <p class="text-sm">Profile details are used only for attendance matching and event evaluation within EAES.</p>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</x-layouts.app>
