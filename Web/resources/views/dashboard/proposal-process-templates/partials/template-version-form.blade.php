<div class="grid gap-4 lg:grid-cols-3">
    <div>
        <label class="form-label">Template Name</label>
        <input type="text" name="template_name" value="{{ $templateName }}" class="form-input @error('template_name') form-input-error @enderror" required>
        @error('template_name') <p class="form-error" role="alert">{{ $message }}</p> @enderror
    </div>
    <div class="lg:col-span-2">
        <label class="form-label">Revision Summary</label>
        <input type="text" name="summary" value="{{ $summary }}" class="form-input @error('summary') form-input-error @enderror">
        @error('summary') <p class="form-error" role="alert">{{ $message }}</p> @enderror
    </div>
    <div class="lg:col-span-3">
        <label class="form-label">Description</label>
        <textarea name="template_description" rows="2" class="form-input @error('template_description') form-input-error @enderror">{{ $templateDescription }}</textarea>
        @error('template_description') <p class="form-error" role="alert">{{ $message }}</p> @enderror
    </div>
</div>

<div class="space-y-3">
    <div class="flex items-center justify-between gap-3">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-600">Ordered Steps</h3>
        <span class="text-xs text-slate-500">Blank rows are ignored.</span>
    </div>
    <div class="overflow-x-auto rounded-md border border-slate-200">
        <table class="min-w-[1100px] divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500">
                <tr>
                    <th class="px-3 py-2">Order</th>
                    <th class="px-3 py-2">Key</th>
                    <th class="px-3 py-2">Title</th>
                    <th class="px-3 py-2">Type</th>
                    <th class="px-3 py-2">Phase</th>
                    <th class="px-3 py-2">Assignee</th>
                    <th class="px-3 py-2">Prerequisite Keys</th>
                    <th class="px-3 py-2">Flags</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 bg-white">
                @foreach($steps as $index => $step)
                    <tr>
                        <td class="px-3 py-2 align-top">
                            <input type="number" name="steps[{{ $index }}][display_order]" value="{{ $step['display_order'] ?? '' }}" min="1" class="form-input w-20">
                        </td>
                        <td class="px-3 py-2 align-top">
                            <input type="text" name="steps[{{ $index }}][step_key]" value="{{ $step['step_key'] ?? '' }}" class="form-input w-40" placeholder="osa_review">
                        </td>
                        <td class="px-3 py-2 align-top">
                            <input type="text" name="steps[{{ $index }}][title]" value="{{ $step['title'] ?? '' }}" class="form-input w-52" placeholder="OSA Review">
                            <textarea name="steps[{{ $index }}][description]" rows="2" class="form-input mt-2 w-52" placeholder="Description">{{ $step['description'] ?? '' }}</textarea>
                        </td>
                        <td class="px-3 py-2 align-top">
                            <select name="steps[{{ $index }}][step_type]" class="form-input form-select w-40">
                                @foreach($stepTypes as $type)
                                    <option value="{{ $type }}" @selected(($step['step_type'] ?? 'approval') === $type)>{{ str($type)->headline() }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td class="px-3 py-2 align-top">
                            <input type="text" name="steps[{{ $index }}][phase]" value="{{ $step['phase'] ?? '' }}" class="form-input w-36" placeholder="approval">
                        </td>
                        <td class="px-3 py-2 align-top">
                            <input type="text" name="steps[{{ $index }}][assigned_role]" value="{{ $step['assigned_role'] ?? '' }}" class="form-input w-36" placeholder="osa">
                            <input type="text" name="steps[{{ $index }}][assigned_scope]" value="{{ $step['assigned_scope'] ?? '' }}" class="form-input mt-2 w-36" placeholder="university">
                        </td>
                        <td class="px-3 py-2 align-top">
                            @for($prerequisiteIndex = 0; $prerequisiteIndex < 5; $prerequisiteIndex++)
                                <input type="text" name="steps[{{ $index }}][prerequisites][]" value="{{ ($step['prerequisites'] ?? [])[$prerequisiteIndex] ?? '' }}" class="form-input {{ $prerequisiteIndex > 0 ? 'mt-2' : '' }} w-40" placeholder="step_key">
                            @endfor
                        </td>
                        <td class="px-3 py-2 align-top">
                            <label class="flex items-center gap-2 text-xs text-slate-600">
                                <input type="hidden" name="steps[{{ $index }}][is_required]" value="0">
                                <input type="checkbox" name="steps[{{ $index }}][is_required]" value="1" @checked((bool) ($step['is_required'] ?? false)) class="rounded border-slate-300">
                                Required
                            </label>
                            <label class="mt-2 flex items-center gap-2 text-xs text-slate-600">
                                <input type="hidden" name="steps[{{ $index }}][is_gate]" value="0">
                                <input type="checkbox" name="steps[{{ $index }}][is_gate]" value="1" @checked((bool) ($step['is_gate'] ?? false)) class="rounded border-slate-300">
                                Gate
                            </label>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="space-y-3">
    <div class="flex items-center justify-between gap-3">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-600">Applicability Rules</h3>
        <span class="text-xs text-slate-500">A default rule acts as the fallback.</span>
    </div>
    <div class="overflow-x-auto rounded-md border border-slate-200">
        <table class="min-w-[1150px] divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500">
                <tr>
                    <th class="px-3 py-2">Priority</th>
                    <th class="px-3 py-2">Scope</th>
                    <th class="px-3 py-2">Domain</th>
                    <th class="px-3 py-2">Category</th>
                    <th class="px-3 py-2">Location</th>
                    <th class="px-3 py-2">Status</th>
                    <th class="px-3 py-2">Dates</th>
                    <th class="px-3 py-2">Default</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 bg-white">
                @foreach($rules as $index => $rule)
                    <tr>
                        <td class="px-3 py-2 align-top">
                            <input type="number" name="rules[{{ $index }}][priority]" value="{{ $rule['priority'] ?? 0 }}" class="form-input w-24">
                        </td>
                        <td class="px-3 py-2 align-top">
                            <select name="rules[{{ $index }}][organizer_role]" class="form-input form-select w-40">
                                <option value="">Any organizer role</option>
                                @foreach(['organizer', 'co_organizer', 'participant'] as $role)
                                    <option value="{{ $role }}" @selected(($rule['organizer_role'] ?? '') === $role)>{{ str($role)->headline() }}</option>
                                @endforeach
                            </select>
                            <select name="rules[{{ $index }}][organization_type]" class="form-input form-select mt-2 w-40">
                                <option value="">Any org type</option>
                                @foreach($organizationTypes as $type)
                                    <option value="{{ $type }}" @selected(($rule['organization_type'] ?? '') === $type)>{{ str($type)->headline() }}</option>
                                @endforeach
                            </select>
                            <select name="rules[{{ $index }}][college_id]" class="form-input form-select mt-2 w-40">
                                <option value="">Any college</option>
                                @foreach($colleges as $college)
                                    <option value="{{ $college->id }}" @selected(($rule['college_id'] ?? '') === $college->id)>{{ $college->code }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td class="px-3 py-2 align-top">
                            <select name="rules[{{ $index }}][event_domain]" class="form-input form-select w-44">
                                <option value="">Any domain</option>
                                @foreach(['student_affairs', 'hr', 'academic_records', 'academic_unit'] as $domain)
                                    <option value="{{ $domain }}" @selected(($rule['event_domain'] ?? '') === $domain)>{{ str($domain)->headline() }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td class="px-3 py-2 align-top">
                            <input type="text" name="rules[{{ $index }}][activity_category]" value="{{ $rule['activity_category'] ?? '' }}" class="form-input w-40" placeholder="activity">
                        </td>
                        <td class="px-3 py-2 align-top">
                            <select name="rules[{{ $index }}][location_type]" class="form-input form-select w-40">
                                <option value="">Any location</option>
                                @foreach(['on-campus', 'off-campus', 'online'] as $locationType)
                                    <option value="{{ $locationType }}" @selected(($rule['location_type'] ?? '') === $locationType)>{{ str($locationType)->headline() }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td class="px-3 py-2 align-top">
                            <select name="rules[{{ $index }}][status]" class="form-input form-select w-32">
                                @foreach(['active', 'inactive'] as $status)
                                    <option value="{{ $status }}" @selected(($rule['status'] ?? 'active') === $status)>{{ str($status)->headline() }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td class="px-3 py-2 align-top">
                            <input type="datetime-local" name="rules[{{ $index }}][effective_start]" value="{{ $rule['effective_start'] ?? '' }}" class="form-input w-44">
                            <input type="datetime-local" name="rules[{{ $index }}][effective_end]" value="{{ $rule['effective_end'] ?? '' }}" class="form-input mt-2 w-44">
                        </td>
                        <td class="px-3 py-2 align-top">
                            <label class="flex items-center gap-2 text-xs text-slate-600">
                                <input type="hidden" name="rules[{{ $index }}][is_default]" value="0">
                                <input type="checkbox" name="rules[{{ $index }}][is_default]" value="1" @checked((bool) ($rule['is_default'] ?? false)) class="rounded border-slate-300">
                                Fallback
                            </label>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="flex justify-end">
    <button type="submit" class="btn btn-primary">{{ $submitLabel }}</button>
</div>
