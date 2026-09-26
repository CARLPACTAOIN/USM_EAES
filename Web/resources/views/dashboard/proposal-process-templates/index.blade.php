<x-layouts.app :title="'Workflow Templates - EAES'">
    @php
        $statusClass = [
            'draft' => 'bg-amber-50 text-amber-700 border-amber-200',
            'published' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'retired' => 'bg-slate-50 text-slate-700 border-slate-200',
        ];

        $blankStep = [
            'step_key' => '',
            'title' => '',
            'description' => '',
            'step_type' => 'approval',
            'phase' => '',
            'display_order' => '',
            'is_required' => true,
            'is_gate' => false,
            'assigned_role' => '',
            'assigned_scope' => '',
            'prerequisites' => [],
        ];

        $blankRule = [
            'priority' => 0,
            'organizer_role' => '',
            'organization_type' => '',
            'college_id' => '',
            'event_domain' => '',
            'activity_category' => '',
            'location_type' => '',
            'is_default' => false,
            'status' => 'active',
            'effective_start' => '',
            'effective_end' => '',
        ];

        $defaultSteps = [
            array_merge($blankStep, [
                'step_key' => 'adviser_endorsement',
                'title' => 'Adviser Endorsement',
                'description' => 'Endorsement by the organization adviser.',
                'step_type' => 'endorsement',
                'phase' => 'endorsement',
                'display_order' => 1,
                'assigned_role' => 'adviser',
                'assigned_scope' => 'organization',
            ]),
            array_merge($blankStep, [
                'step_key' => 'osa_review',
                'title' => 'OSA Review',
                'description' => 'Final review by OSA.',
                'step_type' => 'approval',
                'phase' => 'approval',
                'display_order' => 2,
                'is_gate' => true,
                'assigned_role' => 'osa',
                'assigned_scope' => 'university',
                'prerequisites' => ['adviser_endorsement'],
            ]),
            array_merge($blankStep, [
                'step_key' => '',
                'display_order' => 3,
            ]),
            array_merge($blankStep, [
                'step_key' => '',
                'display_order' => 4,
            ]),
            array_merge($blankStep, [
                'step_key' => '',
                'display_order' => 5,
            ]),
            array_merge($blankStep, [
                'step_key' => '',
                'display_order' => 6,
            ]),
            array_merge($blankStep, [
                'step_key' => '',
                'display_order' => 7,
            ]),
            array_merge($blankStep, [
                'step_key' => '',
                'display_order' => 8,
            ]),
            array_merge($blankStep, [
                'step_key' => '',
                'display_order' => 9,
            ]),
            array_merge($blankStep, [
                'step_key' => '',
                'display_order' => 10,
            ]),
        ];

        $defaultRules = array_merge(
            [array_merge($blankRule, ['is_default' => true])],
            array_fill(0, 5, $blankRule),
        );

        $stepRowsFor = function ($version = null) use ($blankStep) {
            $rows = collect($version?->steps ?? [])->map(function ($step) use ($blankStep) {
                return array_merge($blankStep, [
                    'step_key' => $step->step_key,
                    'title' => $step->title,
                    'description' => $step->description,
                    'step_type' => $step->step_type,
                    'phase' => $step->phase,
                    'display_order' => $step->display_order,
                    'is_required' => $step->is_required,
                    'is_gate' => $step->is_gate,
                    'assigned_role' => $step->condition_config['assigned_role'] ?? '',
                    'assigned_scope' => $step->condition_config['assigned_scope'] ?? '',
                    'prerequisites' => $step->prerequisiteSteps->pluck('step_key')->all(),
                ]);
            })->values();

            return $rows->concat(collect(array_fill(0, 8, $blankStep)))->values();
        };

        $ruleRowsFor = function ($version = null) use ($blankRule) {
            $rows = collect($version?->applicabilityRules ?? [])->map(function ($rule) use ($blankRule) {
                return array_merge($blankRule, [
                    'priority' => $rule->priority,
                    'organizer_role' => $rule->organizer_role,
                    'organization_type' => $rule->organization_type,
                    'college_id' => $rule->college_id,
                    'event_domain' => $rule->event_domain,
                    'activity_category' => $rule->activity_category,
                    'location_type' => $rule->location_type,
                    'is_default' => $rule->is_default,
                    'status' => $rule->status,
                    'effective_start' => $rule->effective_start?->format('Y-m-d\TH:i'),
                    'effective_end' => $rule->effective_end?->format('Y-m-d\TH:i'),
                ]);
            })->values();

            return $rows->concat(collect(array_fill(0, 5, $blankRule)))->values();
        };
    @endphp

    <div class="space-y-6">
        <header class="admin-access-hero">
            <div class="admin-access-hero-main">
                <div class="admin-access-assign-icon" aria-hidden="true">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 6.75h11.25M9 12h11.25M9 17.25h11.25M3.75 6.75h.008v.008H3.75V6.75zm0 5.25h.008v.008H3.75V12zm0 5.25h.008v.008H3.75v-.008z"/>
                    </svg>
                </div>
                <div>
                    <p class="portal-page-kicker">OSA workflow governance</p>
                    <h1 class="text-2xl font-display text-(--color-text-primary)">Workflow Templates</h1>
                    <p class="text-sm text-(--color-text-secondary) mt-1 max-w-3xl">Draft, publish, retire, and revise Proposal Process Templates while preserving the immutable route assigned to submitted proposals.</p>
                </div>
            </div>
        </header>

        @if($errors->any())
            <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ $errors->first() }}
            </div>
        @endif

        <section class="rounded-md border border-slate-200 bg-white p-4 shadow-sm">
            <h2 class="text-base font-semibold text-(--color-text-primary)">Create Template Draft</h2>
            <form method="POST" action="{{ route('dashboard.proposal-process-templates.store') }}" class="mt-4 space-y-5">
                @csrf
                @include('dashboard.proposal-process-templates.partials.template-version-form', [
                    'templateName' => old('template_name', ''),
                    'templateDescription' => old('template_description', ''),
                    'summary' => old('summary', ''),
                    'steps' => old('steps', $defaultSteps),
                    'rules' => old('rules', $defaultRules),
                    'stepTypes' => $stepTypes,
                    'organizationTypes' => $organizationTypes,
                    'colleges' => $colleges,
                    'submitLabel' => 'Create Draft',
                ])
            </form>
        </section>

        <section class="space-y-4">
            @forelse($templates as $template)
                <article class="rounded-md border border-slate-200 bg-white shadow-sm">
                    <div class="flex flex-col gap-3 border-b border-slate-200 p-4 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-(--color-text-primary)">{{ $template->name }}</h2>
                            <p class="mt-1 text-sm text-(--color-text-secondary)">{{ $template->description ?: 'No description recorded.' }}</p>
                        </div>
                        <span class="rounded-full border px-3 py-1 text-xs font-semibold {{ $template->status === 'active' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-50 text-slate-700' }}">
                            {{ str($template->status)->headline() }}
                        </span>
                    </div>

                    <div class="divide-y divide-slate-200">
                        @foreach($template->versions as $version)
                            <div id="version-{{ $version->id }}" class="p-4">
                                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                                    <div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h3 class="font-semibold text-(--color-text-primary)">Version {{ $version->version_number }}</h3>
                                            <span class="rounded-full border px-2 py-0.5 text-xs font-semibold {{ $statusClass[$version->status] ?? 'bg-slate-50 text-slate-700 border-slate-200' }}">
                                                {{ str($version->status)->headline() }}
                                            </span>
                                            @if($version->workflow_runs_count > 0)
                                                <span class="rounded-full border border-sky-200 bg-sky-50 px-2 py-0.5 text-xs font-semibold text-sky-700">{{ $version->workflow_runs_count }} bound runs</span>
                                            @endif
                                        </div>
                                        <p class="mt-1 text-sm text-(--color-text-secondary)">{{ $version->summary ?: 'No revision summary.' }}</p>
                                    </div>

                                    <div class="flex flex-wrap gap-2">
                                        @if($version->status === 'draft')
                                            <form method="POST" action="{{ route('dashboard.proposal-process-template-versions.publish', $version) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-primary">Publish</button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('dashboard.proposal-process-template-versions.draft', $version) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-secondary">New Draft</button>
                                            </form>
                                        @endif

                                        @if($version->status === 'published')
                                            <form method="POST" action="{{ route('dashboard.proposal-process-template-versions.retire', $version) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-secondary">Retire</button>
                                            </form>
                                        @endif
                                    </div>
                                </div>

                                @if($version->status === 'draft')
                                    <form method="POST" action="{{ route('dashboard.proposal-process-template-versions.update', $version) }}" class="mt-4 space-y-5">
                                        @csrf
                                        @method('PUT')
                                        @include('dashboard.proposal-process-templates.partials.template-version-form', [
                                            'templateName' => old('template_name', $template->name),
                                            'templateDescription' => old('template_description', $template->description),
                                            'summary' => old('summary', $version->summary),
                                            'steps' => old('steps', $stepRowsFor($version)->all()),
                                            'rules' => old('rules', $ruleRowsFor($version)->all()),
                                            'stepTypes' => $stepTypes,
                                            'organizationTypes' => $organizationTypes,
                                            'colleges' => $colleges,
                                            'submitLabel' => 'Save Draft',
                                        ])
                                    </form>
                                @else
                                    <div class="mt-4 grid gap-4 lg:grid-cols-[1.2fr_0.8fr]">
                                        <div class="overflow-hidden rounded-md border border-slate-200">
                                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500">
                                                    <tr>
                                                        <th class="px-3 py-2">Order</th>
                                                        <th class="px-3 py-2">Step</th>
                                                        <th class="px-3 py-2">Type</th>
                                                        <th class="px-3 py-2">Prerequisites</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-slate-100">
                                                    @foreach($version->steps as $step)
                                                        <tr>
                                                            <td class="px-3 py-2">{{ $step->display_order }}</td>
                                                            <td class="px-3 py-2">
                                                                <div class="font-medium text-slate-900">{{ $step->title }}</div>
                                                                <div class="text-xs text-slate-500">{{ $step->step_key }}</div>
                                                            </td>
                                                            <td class="px-3 py-2">{{ str($step->step_type)->headline() }}</td>
                                                            <td class="px-3 py-2 text-slate-600">{{ $step->prerequisiteSteps->pluck('step_key')->join(', ') ?: 'None' }}</td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                        <div class="rounded-md border border-slate-200 p-3">
                                            <h4 class="text-sm font-semibold text-slate-900">Applicability</h4>
                                            <div class="mt-3 space-y-2 text-sm text-slate-600">
                                                @forelse($version->applicabilityRules as $rule)
                                                    <div class="rounded-md bg-slate-50 p-2">
                                                        <div class="font-medium text-slate-900">{{ $rule->is_default ? 'Default fallback' : 'Priority '.$rule->priority }}</div>
                                                        <div>{{ $rule->organization_type ?: 'Any organization' }} · {{ $rule->event_domain ?: 'Any domain' }} · {{ $rule->location_type ?: 'Any location' }}</div>
                                                    </div>
                                                @empty
                                                    <p>No applicability rules configured.</p>
                                                @endforelse
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </article>
            @empty
                <div class="rounded-md border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-600">No proposal process templates have been created yet.</div>
            @endforelse
        </section>

        {{ $templates->links() }}
    </div>
</x-layouts.app>
