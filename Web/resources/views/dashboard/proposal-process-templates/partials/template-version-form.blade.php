@php
    $formUid = $formId ?? ('wf_' . substr(md5(uniqid('', true)), 0, 8));

    // Normalize and filter steps to eliminate empty ghost rows
    $cleanSteps = collect($steps ?? [])->filter(function ($s) {
        return filled($s['step_key'] ?? null)
            || filled($s['title'] ?? null)
            || filled($s['description'] ?? null)
            || !empty($s['prerequisites'] ?? []);
    })->values()->map(function ($s, $idx) {
        return [
            'display_order' => (int) ($s['display_order'] ?? ($idx + 1)),
            'step_key' => (string) ($s['step_key'] ?? ''),
            'title' => (string) ($s['title'] ?? ''),
            'description' => (string) ($s['description'] ?? ''),
            'step_type' => (string) ($s['step_type'] ?? 'approval'),
            'phase' => (string) ($s['phase'] ?? ($s['step_type'] ?? 'approval')),
            'assigned_role' => (string) ($s['assigned_role'] ?? ''),
            'assigned_scope' => (string) ($s['assigned_scope'] ?? 'organization'),
            'is_required' => (bool) ($s['is_required'] ?? true),
            'is_gate' => (bool) ($s['is_gate'] ?? false),
            'prerequisites' => array_values(array_filter((array) ($s['prerequisites'] ?? []))),
        ];
    })->all();

    if (empty($cleanSteps)) {
        $cleanSteps = [
            [
                'display_order' => 1,
                'step_key' => 'adviser_endorsement',
                'title' => 'Adviser Endorsement',
                'description' => 'Review and endorsement by the faculty adviser.',
                'step_type' => 'endorsement',
                'phase' => 'endorsement',
                'assigned_role' => 'adviser',
                'assigned_scope' => 'organization',
                'is_required' => true,
                'is_gate' => false,
                'prerequisites' => [],
            ],
            [
                'display_order' => 2,
                'step_key' => 'osa_review',
                'title' => 'OSA Review',
                'description' => 'Final review and institutional approval by OSA.',
                'step_type' => 'approval',
                'phase' => 'approval',
                'assigned_role' => 'osa',
                'assigned_scope' => 'university',
                'is_required' => true,
                'is_gate' => true,
                'prerequisites' => ['adviser_endorsement'],
            ],
        ];
    }

    // Normalize and filter rules to eliminate empty ghost rows
    $cleanRules = collect($rules ?? [])->filter(function ($r) {
        return !empty($r['is_default'])
            || filled($r['organization_type'] ?? null)
            || filled($r['college_id'] ?? null)
            || filled($r['event_domain'] ?? null)
            || filled($r['location_type'] ?? null)
            || filled($r['activity_category'] ?? null)
            || (int) ($r['priority'] ?? 0) !== 0;
    })->values()->map(function ($r) {
        return [
            'priority' => (int) ($r['priority'] ?? 0),
            'organizer_role' => (string) ($r['organizer_role'] ?? ''),
            'organization_type' => (string) ($r['organization_type'] ?? ''),
            'college_id' => (string) ($r['college_id'] ?? ''),
            'event_domain' => (string) ($r['event_domain'] ?? ''),
            'activity_category' => (string) ($r['activity_category'] ?? ''),
            'location_type' => (string) ($r['location_type'] ?? ''),
            'status' => (string) ($r['status'] ?? 'active'),
            'effective_start' => (string) ($r['effective_start'] ?? ''),
            'effective_end' => (string) ($r['effective_end'] ?? ''),
            'is_default' => (bool) ($r['is_default'] ?? false),
        ];
    })->all();

    if (empty($cleanRules)) {
        $cleanRules = [
            [
                'priority' => 10,
                'organizer_role' => '',
                'organization_type' => '',
                'college_id' => '',
                'event_domain' => 'student_affairs',
                'activity_category' => '',
                'location_type' => '',
                'status' => 'active',
                'effective_start' => '',
                'effective_end' => '',
                'is_default' => false,
            ],
            [
                'priority' => 0,
                'organizer_role' => '',
                'organization_type' => '',
                'college_id' => '',
                'event_domain' => '',
                'activity_category' => '',
                'location_type' => '',
                'status' => 'active',
                'effective_start' => '',
                'effective_end' => '',
                'is_default' => true,
            ],
        ];
    }
@endphp

<script>
    if (typeof window.workflowBuilder !== 'function') {
        window.workflowBuilder = function(config) {
            return {
                activeTab: 'steps',
                templateName: config.templateName || '',
                templateDescription: config.templateDescription || '',
                summary: config.summary || '',
                steps: (config.steps && config.steps.length) ? JSON.parse(JSON.stringify(config.steps)) : [],
                rules: (config.rules && config.rules.length) ? JSON.parse(JSON.stringify(config.rules)) : [],

                addStep() {
                    if (this.steps.length >= 20) return;
                    const nextOrder = this.steps.length + 1;
                    this.steps.push({
                        display_order: nextOrder,
                        step_key: '',
                        title: '',
                        description: '',
                        step_type: 'approval',
                        phase: 'approval',
                        assigned_role: '',
                        assigned_scope: 'organization',
                        is_required: true,
                        is_gate: false,
                        prerequisites: []
                    });
                },
                removeStep(index) {
                    if (this.steps.length <= 1) return;
                    const removedKey = this.steps[index].step_key;
                    this.steps.splice(index, 1);
                    this.syncOrdersAndPrereqs(removedKey);
                },
                moveUp(index) {
                    if (index <= 0) return;
                    const temp = this.steps[index];
                    this.steps.splice(index, 1);
                    this.steps.splice(index - 1, 0, temp);
                    this.syncOrdersAndPrereqs();
                },
                moveDown(index) {
                    if (index >= this.steps.length - 1) return;
                    const temp = this.steps[index];
                    this.steps.splice(index, 1);
                    this.steps.splice(index + 1, 0, temp);
                    this.syncOrdersAndPrereqs();
                },
                duplicateStep(index) {
                    if (this.steps.length >= 20) return;
                    const orig = this.steps[index];
                    const copy = JSON.parse(JSON.stringify(orig));
                    copy.display_order = this.steps.length + 1;
                    copy.step_key = orig.step_key ? `${orig.step_key}_copy` : '';
                    copy.title = orig.title ? `${orig.title} (Copy)` : '';
                    this.steps.push(copy);
                },
                syncOrdersAndPrereqs(removedKey = null) {
                    this.steps.forEach((step, idx) => {
                        step.display_order = idx + 1;
                        const earlierKeys = this.steps.slice(0, idx).map(s => s.step_key).filter(Boolean);
                        step.prerequisites = (step.prerequisites || []).filter(k => k !== removedKey && earlierKeys.includes(k));
                    });
                },
                autoSlug(index) {
                    if (!this.steps[index].step_key || this.steps[index].step_key.trim() === '') {
                        this.steps[index].step_key = (this.steps[index].title || '')
                            .toLowerCase()
                            .replace(/[^a-z0-9_-]+/g, '_')
                            .replace(/^_+|_+$/g, '');
                    }
                },
                getEarlierSteps(currentIndex) {
                    return this.steps.slice(0, currentIndex).filter(s => s.step_key && s.step_key.trim().length > 0);
                },
                togglePrereq(stepIndex, prereqKey) {
                    if (!this.steps[stepIndex].prerequisites) {
                        this.steps[stepIndex].prerequisites = [];
                    }
                    const i = this.steps[stepIndex].prerequisites.indexOf(prereqKey);
                    if (i > -1) {
                        this.steps[stepIndex].prerequisites.splice(i, 1);
                    } else {
                        this.steps[stepIndex].prerequisites.push(prereqKey);
                    }
                },
                hasPrereq(stepIndex, prereqKey) {
                    return Array.isArray(this.steps[stepIndex].prerequisites) && this.steps[stepIndex].prerequisites.includes(prereqKey);
                },
                addRule() {
                    if (this.rules.length >= 12) return;
                    this.rules.push({
                        priority: 5,
                        organizer_role: '',
                        organization_type: '',
                        college_id: '',
                        event_domain: '',
                        activity_category: '',
                        location_type: '',
                        status: 'active',
                        effective_start: '',
                        effective_end: '',
                        is_default: false
                    });
                },
                removeRule(index) {
                    if (this.rules.length <= 1) return;
                    this.rules.splice(index, 1);
                },
                applyPreset(type) {
                    if (type === 'standard') {
                        this.steps = [
                            { display_order: 1, step_key: 'adviser_endorsement', title: 'Adviser Endorsement', description: 'Review and endorsement by the faculty adviser.', step_type: 'endorsement', phase: 'endorsement', assigned_role: 'adviser', assigned_scope: 'organization', is_required: true, is_gate: false, prerequisites: [] },
                            { display_order: 2, step_key: 'osa_review', title: 'OSA Review', description: 'Final review and institutional approval by OSA.', step_type: 'approval', phase: 'approval', assigned_role: 'osa', assigned_scope: 'university', is_required: true, is_gate: true, prerequisites: ['adviser_endorsement'] }
                        ];
                    } else if (type === 'multi_tier') {
                        this.steps = [
                            { display_order: 1, step_key: 'adviser_endorsement', title: 'Adviser Endorsement', description: 'Review and endorsement by the faculty adviser.', step_type: 'endorsement', phase: 'endorsement', assigned_role: 'adviser', assigned_scope: 'organization', is_required: true, is_gate: false, prerequisites: [] },
                            { display_order: 2, step_key: 'dept_chair_endorsement', title: 'Department Chair Endorsement', description: 'Academic department head endorsement.', step_type: 'endorsement', phase: 'endorsement', assigned_role: 'dept_chair', assigned_scope: 'department', is_required: true, is_gate: false, prerequisites: ['adviser_endorsement'] },
                            { display_order: 3, step_key: 'dean_clearance', title: 'College Dean Clearance', description: 'College dean academic and facility clearance.', step_type: 'clearance', phase: 'clearance', assigned_role: 'college_dean', assigned_scope: 'college', is_required: true, is_gate: false, prerequisites: ['dept_chair_endorsement'] },
                            { display_order: 4, step_key: 'security_clearance', title: 'Security Clearance', description: 'Campus security office safety clearance.', step_type: 'clearance', phase: 'clearance', assigned_role: 'chief_security', assigned_scope: 'university', is_required: true, is_gate: false, prerequisites: ['dean_clearance'] },
                            { display_order: 5, step_key: 'osa_approval', title: 'OSA Approval', description: 'Final institutional approval and digital permit generation.', step_type: 'approval', phase: 'approval', assigned_role: 'osa', assigned_scope: 'university', is_required: true, is_gate: true, prerequisites: ['security_clearance'] }
                        ];
                    }
                    this.syncOrdersAndPrereqs();
                }
            };
        };
    }
</script>

<div x-data="workflowBuilder({
    templateName: @js($templateName ?? ''),
    templateDescription: @js($templateDescription ?? ''),
    summary: @js($summary ?? ''),
    steps: @js($cleanSteps),
    rules: @js($cleanRules),
})" class="workflow-builder-wrapper">

    {{-- Builder Tabs Navigation --}}
    <div class="workflow-builder-tabs" role="tablist" aria-label="Template builder sections">
        <button type="button"
                @click="activeTab = 'general'"
                :class="{ 'is-active': activeTab === 'general' }"
                class="workflow-builder-tab-btn"
                role="tab"
                :aria-selected="activeTab === 'general'">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span>1. Basic Details</span>
        </button>
        <button type="button"
                @click="activeTab = 'steps'"
                :class="{ 'is-active': activeTab === 'steps' }"
                class="workflow-builder-tab-btn"
                role="tab"
                :aria-selected="activeTab === 'steps'">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
            <span>2. Review Steps (<span x-text="steps.length"></span>)</span>
        </button>
        <button type="button"
                @click="activeTab = 'rules'"
                :class="{ 'is-active': activeTab === 'rules' }"
                class="workflow-builder-tab-btn"
                role="tab"
                :aria-selected="activeTab === 'rules'">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
            <span>3. Who Uses This (<span x-text="rules.length"></span>)</span>
        </button>
    </div>

    {{-- Tab 1: Basic Details --}}
    <div x-show="activeTab === 'general'" x-cloak class="p-5 space-y-4">
        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <label for="{{ $formUid }}_template_name" class="form-label">
                    Workflow Name <span class="text-red-500">*</span>
                </label>
                <input type="text"
                       id="{{ $formUid }}_template_name"
                       name="template_name"
                       x-model="templateName"
                       class="form-input @error('template_name') form-input-error @enderror"
                       placeholder="e.g. Student Organization Campus Event Approval"
                       required>
                @error('template_name') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                <p class="form-helper">The name shown to student officers, faculty advisers, and evaluating offices.</p>
            </div>

            <div>
                <label for="{{ $formUid }}_summary" class="form-label">
                    Summary of Changes <span class="text-xs text-slate-400 font-normal">(Optional note)</span>
                </label>
                <input type="text"
                       id="{{ $formUid }}_summary"
                       name="summary"
                       x-model="summary"
                       class="form-input @error('summary') form-input-error @enderror"
                       placeholder="e.g. Added College Dean clearance step">
                @error('summary') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                <p class="form-helper">A quick note explaining what was updated in this version.</p>
            </div>
        </div>

        <div>
            <label for="{{ $formUid }}_template_description" class="form-label">Description</label>
            <textarea id="{{ $formUid }}_template_description"
                      name="template_description"
                      x-model="templateDescription"
                      rows="2"
                      class="form-input @error('template_description') form-input-error @enderror"
                      placeholder="Explain what activities or student groups this approval workflow is designed for..."></textarea>
            @error('template_description') <p class="form-error" role="alert">{{ $message }}</p> @enderror
        </div>

        <div class="flex justify-end pt-2">
            <button type="button" @click="activeTab = 'steps'" class="btn btn-secondary text-xs">
                Next: Set Up Review Steps &rarr;
            </button>
        </div>
    </div>

    {{-- Tab 2: Approval Steps --}}
    <div x-show="activeTab === 'steps'" class="p-5 space-y-4">
        {{-- Toolbar with Presets & Add Step --}}
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between p-3 rounded-lg bg-(--color-surface-raised) border border-(--color-border)">
            <div>
                <h4 class="text-xs font-bold uppercase tracking-wider text-(--color-text-tertiary)">Approval Steps in Order</h4>
                <p class="text-xs text-(--color-text-secondary) mt-0.5">Proposals move through these steps in sequence. You can specify which steps must finish first before later reviewers are invited.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <div class="inline-flex rounded-md shadow-xs" role="group">
                    <button type="button"
                            @click="applyPreset('standard')"
                            class="px-2.5 py-1 text-xs font-medium text-(--color-text-secondary) bg-(--color-surface) border border-(--color-border) rounded-l-md hover:bg-(--color-surface-raised)">
                        Simple 2-Step (Adviser &rarr; OSA)
                    </button>
                    <button type="button"
                            @click="applyPreset('multi_tier')"
                            class="px-2.5 py-1 text-xs font-medium text-(--color-text-secondary) bg-(--color-surface) border-t border-b border-r border-(--color-border) rounded-r-md hover:bg-(--color-surface-raised)">
                        Complete 5-Step (Adviser to OSA)
                    </button>
                </div>
                <button type="button"
                        @click="addStep()"
                        :disabled="steps.length >= 20"
                        class="btn btn-primary btn-sm">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    <span>+ Add Step</span>
                </button>
            </div>
        </div>

        @error('steps')
            <div class="rounded-md border border-red-200 bg-red-50 p-3 text-xs text-red-700" role="alert">
                {{ $message }}
            </div>
        @enderror

        {{-- Step Cards List --}}
        <div class="space-y-3">
            <template x-for="(step, index) in steps" :key="index">
                <div class="workflow-builder-step-card" :class="`workflow-pipeline-node--${step.step_type}`">
                    {{-- Hidden order input ensuring 1-based order is submitted --}}
                    <input type="hidden" :name="`steps[${index}][display_order]`" :value="step.display_order">

                    {{-- Step Header Row --}}
                    <div class="flex items-center justify-between gap-3 pb-3 border-b border-(--color-border)">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="workflow-node-order" x-text="step.display_order"></span>
                            <span class="workflow-node-type-badge" x-text="step.step_type"></span>
                            <span class="text-xs font-semibold text-(--color-text-primary) truncate" x-text="step.title || 'Untitled Step'"></span>
                        </div>

                        <div class="workflow-step-actions">
                            <button type="button"
                                    @click="moveUp(index)"
                                    :disabled="index === 0"
                                    class="workflow-step-btn"
                                    title="Move earlier">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                            </button>
                            <button type="button"
                                    @click="moveDown(index)"
                                    :disabled="index === steps.length - 1"
                                    class="workflow-step-btn"
                                    title="Move later">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </button>
                            <button type="button"
                                    @click="duplicateStep(index)"
                                    :disabled="steps.length >= 20"
                                    class="workflow-step-btn"
                                    title="Duplicate step">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                            </button>
                            <button type="button"
                                    @click="removeStep(index)"
                                    :disabled="steps.length <= 1"
                                    class="workflow-step-btn workflow-step-btn--delete"
                                    title="Delete step">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            </button>
                        </div>
                    </div>

                    {{-- Step Configuration Form Fields --}}
                    <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <label class="form-label text-xs">Step Title <span class="text-red-500">*</span></label>
                            <input type="text"
                                   :name="`steps[${index}][title]`"
                                   x-model="step.title"
                                   @input="autoSlug(index)"
                                   class="form-input text-xs"
                                   placeholder="e.g. Faculty Adviser Endorsement"
                                   required>
                        </div>

                        <div>
                            <label class="form-label text-xs">Step Code (System ID) <span class="text-red-500">*</span></label>
                            <input type="text"
                                   :name="`steps[${index}][step_key]`"
                                   x-model="step.step_key"
                                   class="form-input text-xs font-mono"
                                   placeholder="e.g. adviser_endorsement"
                                   pattern="^[A-Za-z0-9_-]+$"
                                   required>
                            <p class="form-helper text-2xs">Auto-generated code (letters, numbers, and underscores only)</p>
                        </div>

                        <div>
                            <label class="form-label text-xs">Type of Review</label>
                            <select :name="`steps[${index}][step_type]`"
                                    x-model="step.step_type"
                                    class="form-input form-select text-xs">
                                <option value="endorsement">Endorsement (Adviser or officer recommendation)</option>
                                <option value="clearance">Clearance (Safety, venue, or budget verification)</option>
                                <option value="review">Document Review (Requirements and paperwork check)</option>
                                <option value="approval">Official Approval (Administrative sign-off)</option>
                                <option value="intake">Submission Intake (Initial submission verification)</option>
                            </select>
                        <div>
                            <label class="form-label text-xs">Approval Stage</label>
                            <input type="text"
                                   :name="`steps[${index}][phase]`"
                                   x-model="step.phase"
                                   class="form-input text-xs"
                                   placeholder="e.g. Endorsement, Clearance, Final Approval">
                        </div>

                        <div>
                            <label class="form-label text-xs">Who Reviews This Step?</label>
                            <input type="text"
                                   :name="`steps[${index}][assigned_role]`"
                                   x-model="step.assigned_role"
                                   list="assigned-roles-list"
                                   class="form-input text-xs"
                                   placeholder="e.g. adviser, college_dean, osa">
                        </div>

                        <div>
                            <label class="form-label text-xs">Office / Scope Level</label>
                            <select :name="`steps[${index}][assigned_scope]`"
                                    x-model="step.assigned_scope"
                                    class="form-input form-select text-xs">
                                <option value="organization">Within the Student Organization</option>
                                <option value="department">Academic Department</option>
                                <option value="college">Academic College</option>
                                <option value="university">University-Wide (OSA / Admin)</option>
                            </select>
                        </div>

                        <div class="sm:col-span-2">
                            <label class="form-label text-xs">Reviewer Guidelines &amp; Instructions</label>
                            <input type="text"
                                   :name="`steps[${index}][description]`"
                                   x-model="step.description"
                                   class="form-input text-xs"
                                   placeholder="Instructions or criteria shown to the reviewer when evaluating proposals...">
                        </div>
                    </div>

                    {{-- Step Flags & Prerequisites Dependencies --}}
                    <div class="mt-3 pt-3 border-t border-(--color-border) flex flex-col md:flex-row md:items-start justify-between gap-4">
                        {{-- Flags --}}
                        <div class="flex items-center gap-4">
                            <label class="inline-flex items-center gap-2 text-xs font-medium text-(--color-text-primary) cursor-pointer">
                                <input type="hidden" :name="`steps[${index}][is_required]`" value="0">
                                <input type="checkbox"
                                       :name="`steps[${index}][is_required]`"
                                       value="1"
                                       x-model="step.is_required"
                                       class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                                <span>Required step (Proposal cannot complete without this approval)</span>
                            </label>

                            <label class="inline-flex items-center gap-2 text-xs font-medium text-rose-700 dark:text-rose-400 cursor-pointer" title="Must be approved before an official event permit can be issued">
                                <input type="hidden" :name="`steps[${index}][is_gate]`" value="0">
                                <input type="checkbox"
                                       :name="`steps[${index}][is_gate]`"
                                       value="1"
                                       x-model="step.is_gate"
                                       class="rounded border-slate-300 text-rose-600 focus:ring-rose-500">
                                <span class="flex items-center gap-1">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                    Permit Gate (Must be approved before official permit can be issued)
                                </span>
                            </label>
                        </div>

                        {{-- Prerequisites Selection from Earlier Steps --}}
                        <div class="flex-1 max-w-xl">
                            <p class="text-xs font-semibold text-(--color-text-secondary) mb-1.5 flex items-center justify-between">
                                <span>Steps that must finish first:</span>
                                <span class="text-2xs text-(--color-text-tertiary)" x-show="getEarlierSteps(index).length > 0">Select any earlier steps that must approve first</span>
                            </p>

                            {{-- Render submitted hidden inputs for prerequisites --}}
                            <template x-for="prereq in (step.prerequisites || [])" :key="prereq">
                                <input type="hidden" :name="`steps[${index}][prerequisites][]`" :value="prereq">
                            </template>

                            <div class="workflow-prereq-checkbox-group">
                                <template x-for="earlier in getEarlierSteps(index)" :key="earlier.step_key">
                                    <label class="workflow-prereq-label" :class="{ 'is-selected': hasPrereq(index, earlier.step_key) }">
                                        <input type="checkbox"
                                               :checked="hasPrereq(index, earlier.step_key)"
                                               @change="togglePrereq(index, earlier.step_key)"
                                               class="sr-only">
                                        <span class="text-2xs font-bold" x-text="`Step ${earlier.display_order}:`"></span>
                                        <span x-text="earlier.title || earlier.step_key"></span>
                                        <svg x-show="hasPrereq(index, earlier.step_key)" class="w-3 h-3 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                    </label>
                                </template>
                                <span x-show="getEarlierSteps(index).length === 0" class="text-2xs text-(--color-text-tertiary) italic">
                                    First step (Review begins immediately as soon as the student submits the proposal)
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        {{-- Live Stepper Flow Preview --}}
        <div class="p-4 rounded-lg bg-(--color-surface-raised) border border-(--color-border)">
            <h5 class="text-xs font-bold uppercase tracking-wider text-(--color-text-tertiary) mb-3 flex items-center gap-1.5">
                <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                <span>Live Approval Flow Preview</span>
            </h5>
            <div class="flex flex-wrap items-center gap-2 text-xs">
                <template x-for="(s, i) in steps" :key="i">
                    <div class="flex items-center gap-2">
                        <div class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-md border border-(--color-border) bg-(--color-surface) shadow-2xs">
                            <span class="workflow-node-order text-2xs" x-text="s.display_order"></span>
                            <span class="font-semibold text-(--color-text-primary)" x-text="s.title || 'Step ' + s.display_order"></span>
                            <span x-show="s.is_gate" class="text-rose-600 font-bold" title="Gate step">&bull; Gate</span>
                            <span class="text-2xs text-(--color-text-tertiary)" x-text="s.assigned_role ? `(${s.assigned_role})` : ''"></span>
                        </div>
                        <span x-show="i < steps.length - 1" class="text-(--color-text-tertiary) font-bold">&rarr;</span>
                    </div>
                </template>
            </div>
        </div>

        <div class="flex justify-between pt-2">
            <button type="button" @click="activeTab = 'general'" class="btn btn-secondary text-xs">
                &larr; Back to Basic Details
            </button>
            <button type="button" @click="activeTab = 'rules'" class="btn btn-secondary text-xs">
                Next: Who Uses This Workflow &rarr;
            </button>
        </div>
    </div>

    {{-- Tab 3: Who Uses This Workflow --}}
    <div x-show="activeTab === 'rules'" class="p-5 space-y-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between p-3 rounded-lg bg-(--color-surface-raised) border border-(--color-border)">
            <div>
                <h4 class="text-xs font-bold uppercase tracking-wider text-(--color-text-tertiary)">Automatic Matching Rules</h4>
                <p class="text-xs text-(--color-text-secondary) mt-0.5">When students submit an event proposal, the system checks these rules to automatically select this workflow.</p>
            </div>
            <button type="button"
                    @click="addRule()"
                    :disabled="rules.length >= 12"
                    class="btn btn-primary btn-sm">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                <span>+ Add Matching Rule</span>
            </button>
        </div>

        @error('rules')
            <div class="rounded-md border border-red-200 bg-red-50 p-3 text-xs text-red-700" role="alert">
                {{ $message }}
            </div>
        @enderror

        <div class="space-y-3">
            <template x-for="(rule, index) in rules" :key="index">
                <div class="workflow-rule-card" :class="{ 'is-fallback': rule.is_default }">
                    <div class="workflow-rule-header pb-2 border-b border-(--color-border)">
                        <div class="flex items-center gap-2">
                            <span class="workflow-priority-badge" x-text="rule.is_default ? 'Default Workflow' : `Priority Level ${rule.priority}`"></span>
                            <span class="text-xs font-semibold text-(--color-text-secondary)" x-text="rule.is_default ? 'Used when no specific rule applies' : `Matching Rule #${index + 1}`"></span>
                        </div>
                        <button type="button"
                                @click="removeRule(index)"
                                :disabled="rules.length <= 1"
                                class="workflow-step-btn workflow-step-btn--delete"
                                title="Remove Rule">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        </button>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 mt-2">
                        <div>
                            <label class="form-label text-xs">Match Priority</label>
                            <input type="number"
                                   :name="`rules[${index}][priority]`"
                                   x-model="rule.priority"
                                   min="-1000"
                                   max="1000"
                                   class="form-input text-xs"
                                   required>
                            <p class="form-helper text-2xs">Higher numbers are checked first</p>
                        </div>

                        <div>
                            <label class="form-label text-xs">Organizer Role</label>
                            <select :name="`rules[${index}][organizer_role]`"
                                    x-model="rule.organizer_role"
                                    class="form-input form-select text-xs">
                                <option value="">Any Organizer Role</option>
                                <option value="organizer">Primary Lead Organizer</option>
                                <option value="co_organizer">Co-Organizer</option>
                                <option value="participant">Participating Group</option>
                            </select>
                        </div>

                        <div>
                            <label class="form-label text-xs">Organization Type</label>
                            <select :name="`rules[${index}][organization_type]`"
                                    x-model="rule.organization_type"
                                    class="form-input form-select text-xs">
                                <option value="">Any Organization Type</option>
                                @foreach($organizationTypes as $type)
                                    <option value="{{ $type }}">{{ str($type)->headline() }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="form-label text-xs">College Affiliation</label>
                            <select :name="`rules[${index}][college_id]`"
                                    x-model="rule.college_id"
                                    class="form-input form-select text-xs">
                                <option value="">Any College</option>
                                @foreach($colleges as $college)
                                    <option value="{{ $college->id }}">{{ $college->code }} — {{ $college->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="form-label text-xs">Event Scope</label>
                            <select :name="`rules[${index}][event_domain]`"
                                    x-model="rule.event_domain"
                                    class="form-input form-select text-xs">
                                <option value="">Any Event Scope</option>
                                <option value="student_affairs">Student Affairs &amp; Campus Life</option>
                                <option value="academic_unit">Academic &amp; Departmental</option>
                                <option value="academic_records">Academic Records</option>
                                <option value="hr">Faculty / Staff Affairs</option>
                            </select>
                        </div>

                        <div>
                            <label class="form-label text-xs">Event Location</label>
                            <select :name="`rules[${index}][location_type]`"
                                    x-model="rule.location_type"
                                    class="form-input form-select text-xs">
                                <option value="">Any Location</option>
                                <option value="on-campus">On-Campus (University Grounds &amp; Facilities)</option>
                                <option value="off-campus">Off-Campus (Outside the University)</option>
                                <option value="online">Online / Virtual Event</option>
                            </select>
                        </div>

                        <div>
                            <label class="form-label text-xs">Activity Type</label>
                            <input type="text"
                                   :name="`rules[${index}][activity_category]`"
                                   x-model="rule.activity_category"
                                   class="form-input text-xs"
                                   placeholder="e.g. General Assembly, Workshop, Sportsfest">
                        </div>

                        <div>
                            <label class="form-label text-xs">Rule Status</label>
                            <select :name="`rules[${index}][status]`"
                                    x-model="rule.status"
                                    class="form-input form-select text-xs">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>

                        <div>
                            <label class="form-label text-xs">Active From</label>
                            <input type="datetime-local"
                                   :name="`rules[${index}][effective_start]`"
                                   x-model="rule.effective_start"
                                   class="form-input text-xs">
                        </div>

                        <div>
                            <label class="form-label text-xs">Active Until</label>
                            <input type="datetime-local"
                                   :name="`rules[${index}][effective_end]`"
                                   x-model="rule.effective_end"
                                   class="form-input text-xs">
                        </div>

                        <div class="sm:col-span-2 flex items-center pt-5">
                            <label class="inline-flex items-center gap-2 text-xs font-semibold text-(--color-text-primary) cursor-pointer">
                                <input type="hidden" :name="`rules[${index}][is_default]`" value="0">
                                <input type="checkbox"
                                       :name="`rules[${index}][is_default]`"
                                       value="1"
                                       x-model="rule.is_default"
                                       class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                                <span>Default Workflow (Use whenever no other rule applies)</span>
                            </label>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <div class="flex justify-between pt-2">
            <button type="button" @click="activeTab = 'steps'" class="btn btn-secondary text-xs">
                &larr; Back to Review Steps
            </button>
            <div class="flex items-center gap-2">
                <button type="submit" class="btn btn-primary">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    <span>{{ $submitLabel }}</span>
                </button>
            </div>
        </div>
    </div>

    {{-- Datalist for Assigned Roles Autocomplete --}}
    <datalist id="assigned-roles-list">
        <option value="adviser">Faculty Adviser</option>
        <option value="dept_chair">Department Chairperson</option>
        <option value="college_dean">College Dean</option>
        <option value="chief_security">Chief of Security</option>
        <option value="osa">OSA Review Officer</option>
        <option value="osas_director">OSAS Director</option>
        <option value="vp_academic_affairs">Vice President for Academic Affairs</option>
        <option value="university_president">University President</option>
    </datalist>
</div>
