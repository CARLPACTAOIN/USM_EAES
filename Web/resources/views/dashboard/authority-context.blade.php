<x-layouts.app title="Select authority context — Eventia">
    <div class="mx-auto max-w-2xl space-y-6">
        <div class="dashboard-page-header">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-(--color-accent)">Authority Projection</p>
                <h1 class="dashboard-page-title">Choose your organization context</h1>
                <p class="dashboard-page-subtitle">Your permissions apply only to the organization and School Year you select.</p>
            </div>
        </div>

        <section class="card">
            <div class="card-body space-y-4">
                @if($authorityContexts->isEmpty())
                    <p class="text-sm text-(--color-text-secondary)">No active administrative authority is assigned to this account.</p>
                @else
                    <form method="POST" action="{{ route('authority-context.store') }}" class="space-y-4">
                        @csrf
                        <div>
                            <label for="authority-context" class="form-label">Active authority</label>
                            <select id="authority-context" name="assignment_id" class="form-input form-select" required autofocus>
                                <option value="">Select an organization</option>
                                @foreach($authorityContexts as $assignment)
                                    <option value="{{ $assignment->id }}" @selected(old('assignment_id') === $assignment->id)>
                                        {{ $assignment->organization?->name ?? $assignment->role_name }} — {{ $assignment->position_title ?? $assignment->role_name }} — {{ $assignment->schoolYear?->label ?? $assignment->academic_year }}
                                    </option>
                                @endforeach
                            </select>
                            @error('assignment_id')
                                <p class="form-error mt-2" role="alert">{{ $message }}</p>
                            @enderror
                        </div>

                        <button type="submit" class="btn btn-primary">Continue to dashboard</button>
                    </form>
                @endif
            </div>
        </section>
    </div>
</x-layouts.app>
