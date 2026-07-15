{{-- Audit History Tab Content --}}
<div x-show="activeTab === 'audit'" x-cloak x-transition>
    <div class="admin-access-section-header">
        <div>
            <h2 class="font-semibold text-(--color-text-primary)">Audit History</h2>
            <p class="text-xs text-(--color-text-secondary) mt-0.5">Immutable Admin Access governance actions</p>
        </div>
    </div>

    <form method="GET" action="{{ route('dashboard.admin-users') }}" class="p-4 border-b border-(--color-border) bg-(--color-surface-raised)">
        <input type="hidden" name="tab" value="audit">
        @if(request()->filled('audit_page'))
            <input type="hidden" name="audit_page" value="{{ request('audit_page') }}">
        @endif
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-3">
            <div class="sm:col-span-2">
                <label for="audit-search" class="sr-only">Search audit history</label>
                <input type="search"
                       id="audit-search"
                       name="audit_search"
                       value="{{ request('audit_search') }}"
                       placeholder="Search actor, action, role, scope, or reason"
                       class="form-input">
            </div>
            <div>
                <label for="audit-action" class="sr-only">Audit action</label>
                <select id="audit-action" name="audit_action" class="form-input form-select">
                    <option value="">All actions</option>
                    @foreach($auditActions as $action)
                        <option value="{{ $action }}" @selected(request('audit_action') === $action)>{{ $auditActionLabels[$action] ?? str($action)->headline() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="audit-from" class="sr-only">From date</label>
                <input type="date"
                       id="audit-from"
                       name="audit_from"
                       value="{{ request('audit_from') }}"
                       class="form-input">
            </div>
            <div>
                <label for="audit-to" class="sr-only">To date</label>
                <input type="date"
                       id="audit-to"
                       name="audit_to"
                       value="{{ request('audit_to') }}"
                       class="form-input">
            </div>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 mt-3">
            <p class="text-xs text-(--color-text-tertiary) font-data">
                Showing {{ $auditLogs->firstItem() ?? 0 }}-{{ $auditLogs->lastItem() ?? 0 }} of {{ $auditLogs->total() }}
            </p>
            <div class="flex items-center gap-2">
                @if(request()->filled('audit_search') || request()->filled('audit_action') || request()->filled('audit_from') || request()->filled('audit_to'))
                    <a href="{{ route('dashboard.admin-users', ['tab' => 'audit']) }}" class="btn btn-secondary btn-sm">Clear</a>
                @endif
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
            </div>
        </div>
    </form>

    <div class="admin-access-record-list">
        @forelse($auditLogs as $auditLog)
            @php
                $details = $auditLog->details ?? [];
                $label = $auditActionLabels[$auditLog->action] ?? str($auditLog->action)->headline();
                $affectedUser = !empty($details['user_id']) ? $auditUsers->get($details['user_id']) : null;
                $organization = !empty($details['organization_id']) ? $auditOrganizations->get($details['organization_id']) : null;
                $college = !empty($details['college_id']) ? $auditColleges->get($details['college_id']) : null;
                $organizationName = $organization?->acronym ?? $details['acronym'] ?? $details['name'] ?? null;
                $scopeLabel = $organizationName ?? $college?->code ?? (!empty($details['college_id']) ? str($details['college_id'])->substr(0, 8) : 'University-wide');
                $initials = str($label)->explode(' ')->filter()->map(fn ($part) => str($part)->substr(0, 1))->take(2)->join('');
            @endphp
            <article class="admin-access-record-row admin-access-audit-row">
                <div class="admin-access-record-main">
                    <div class="admin-access-entity">
                        <span class="admin-access-entity-mark">{{ $initials }}</span>
                        <span class="min-w-0">
                            <span class="block font-medium text-(--color-text-primary) truncate">{{ $label }}</span>
                            <span class="block text-xs text-(--color-text-secondary) font-data truncate">
                                {{ $auditLog->created_at?->format('M d, Y g:i A') ?? 'Timestamp unavailable' }}
                            </span>
                        </span>
                    </div>
                    <div class="admin-access-record-meta">
                        <span class="admin-access-type-pill">{{ str($auditLog->action)->replace('-', ' ')->upper() }}</span>
                        <span class="admin-access-meta-chip">{{ $auditLog->admin?->name ?? 'Unknown actor' }}</span>
                    </div>
                </div>
                <div class="admin-access-record-detail admin-access-audit-detail">
                    @if($affectedUser)
                        <span class="admin-access-detail-label">Account</span>
                        <span>{{ $affectedUser->name }}</span>
                        <span class="text-xs text-(--color-text-tertiary) font-data">{{ $affectedUser->email }}</span>
                    @elseif(!empty($details['user_id']))
                        <span class="admin-access-detail-label">Account</span>
                        <span class="font-data">{{ str($details['user_id'])->substr(0, 8) }}</span>
                    @endif

                    @if(!empty($details['role_name']))
                        <span class="admin-access-detail-label">Role</span>
                        <span>{{ $details['role_name'] }}</span>
                    @endif

                    @if($organizationName || $college || !empty($details['college_id']))
                        <span class="admin-access-detail-label">Scope</span>
                        <span>{{ $scopeLabel }}</span>
                    @endif

                    @if(!empty($details['academic_year']))
                        <span class="admin-access-detail-label">AY</span>
                        <span class="font-data">{{ $details['academic_year'] }}</span>
                    @endif

                    @if(array_key_exists('status', $details))
                        <span class="admin-access-detail-label">Status</span>
                        <span>{{ ucfirst((string) $details['status']) }}</span>
                    @endif

                    @if(!empty($details['reason']))
                        <span class="admin-access-detail-label">Reason</span>
                        <span>{{ $details['reason'] }}</span>
                    @endif

                    @if(empty($details))
                        <span class="text-(--color-text-tertiary)">No structured details were stored for this older audit record.</span>
                    @endif
                </div>
                <div class="admin-access-record-actions">
                    <span class="text-xs text-(--color-text-tertiary) font-data">{{ str($auditLog->target_id)->substr(0, 8) }}</span>
                </div>
            </article>
        @empty
            <div class="empty-state py-8">
                <p class="empty-state-title">No audit history found</p>
                <p class="empty-state-description">Admin Access actions will appear here after OSA creates, updates, revokes, or expires governance records.</p>
            </div>
        @endforelse
    </div>

    @if($auditLogs->hasPages())
        <div class="card-footer">{{ $auditLogs->links() }}</div>
    @endif
</div>
