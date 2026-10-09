<x-layouts.app :title="'Proposal draft — EAES'">
    <div class="mx-auto max-w-3xl space-y-6">
        <div class="dashboard-page-header">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-(--color-accent)">Organizer workspace</p>
                <h1 class="dashboard-page-title">Proposal draft</h1>
                <p class="dashboard-page-subtitle">Your organizer and governance context is ready. Add details when you are ready to continue.</p>
            </div>
            <a href="{{ route('dashboard.events') }}" class="btn btn-secondary">Exit draft</a>
        </div>

        <section class="card" x-data="proposalDraftAutosave({ saveUrl: @js(route('dashboard.proposal-drafts.save', $event)) })">
            <div class="card-body space-y-6">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-base font-semibold text-(--color-text-primary)">Basics</h2>
                        <p class="mt-1 text-sm text-(--color-text-secondary)">Changes save automatically when you pause typing.</p>
                    </div>
                    <p class="text-sm font-medium text-(--color-success)" x-text="status" aria-live="polite">Saved just now</p>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="draft-title" class="form-label">Event title</label>
                        <input id="draft-title" name="title" type="text" class="form-input" value="{{ $event->title }}" autocomplete="off" @input.debounce.500ms="save($event.target)" @change="save($event.target)">
                    </div>

                    <div>
                        <label for="draft-category" class="form-label">PPA category</label>
                        <select id="draft-category" name="proposal_category" class="form-input form-select" @change="save($event.target)">
                            <option value="">Select later</option>
                            @foreach(['program' => 'Program', 'project' => 'Project', 'activity' => 'Activity'] as $value => $label)
                                <option value="{{ $value }}" @selected($event->proposal_category === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="draft-location-type" class="form-label">Location type</label>
                        <select id="draft-location-type" name="location_type" class="form-input form-select" @change="save($event.target)">
                            <option value="">Select later</option>
                            @foreach(['on-campus' => 'On campus', 'off-campus' => 'Off campus', 'online' => 'Online'] as $value => $label)
                                <option value="{{ $value }}" @selected($event->location_type === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="rounded-lg border border-(--color-border) bg-(--color-surface-raised) p-4 text-sm text-(--color-text-secondary)">
                    <p><span class="font-medium text-(--color-text-primary)">Prepared by:</span> {{ $event->canonicalPpaPacket?->preparedBy?->name ?? auth()->user()?->name }}</p>
                    <p class="mt-1"><span class="font-medium text-(--color-text-primary)">Proponent:</span> {{ $event->proponent_name_snapshot ?? 'To be confirmed' }}</p>
                    <p class="mt-1"><span class="font-medium text-(--color-text-primary)">Organizer:</span> {{ $event->organization_name_snapshot }}</p>
                </div>
            </div>
        </section>
    </div>

</x-layouts.app>
