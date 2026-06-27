<x-layouts.app :title="'Admin Access - EAES'">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8">
        <div>
            <h1 class="text-2xl font-display text-(--color-text-primary)">Admin Access</h1>
            <p class="text-sm text-(--color-text-secondary) mt-1">Review applications and manage active admin assignments</p>
        </div>
        <button type="button" onclick="document.getElementById('assign-admin-modal').showModal()" class="btn btn-primary shrink-0">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 4v16m8-8H4"/></svg>
            Assign Admin
        </button>
    </div>

    <div class="grid xl:grid-cols-3 gap-6 mb-6">
        <section class="card xl:col-span-2">
            <div class="card-header">
                <h2 class="font-semibold text-(--color-text-primary)">Pending Applications</h2>
            </div>
            <div class="card-body p-0">
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Applicant</th>
                                <th>Request</th>
                                <th>Scope</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($pendingApplications as $application)
                                <tr>
                                    <td>
                                        <p class="font-medium text-(--color-text-primary)">{{ $application->applicant->name }}</p>
                                        <p class="text-xs text-(--color-text-secondary) font-data">{{ $application->applicant->email }}</p>
                                    </td>
                                    <td>
                                        <p class="font-medium text-(--color-text-primary)">{{ $application->role_name }}</p>
                                        <p class="text-xs text-(--color-text-secondary)">{{ $application->position_title ?? 'Officer' }} · {{ $application->academic_year }}</p>
                                    </td>
                                    <td class="text-sm text-(--color-text-secondary)">
                                        {{ $application->organization?->acronym ?? $application->organization_acronym ?? $application->college?->code ?? 'University-wide' }}
                                    </td>
                                    <td>
                                        <div class="flex flex-wrap gap-2">
                                            <form method="POST" action="{{ route('dashboard.admin-applications.approve', $application) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-primary btn-sm">Approve</button>
                                            </form>
                                            <form method="POST" action="{{ route('dashboard.admin-applications.reject', $application) }}" class="flex gap-2">
                                                @csrf
                                                <input type="text" name="review_remarks" class="form-input h-9 min-w-40" placeholder="Remarks" required>
                                                <button type="submit" class="btn btn-secondary btn-sm">Reject</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4">
                                        <div class="empty-state py-8">
                                            <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                            <p class="empty-state-title">No pending applications</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="card">
            <div class="card-header">
                <h2 class="font-semibold text-(--color-text-primary)">Recent Reviews</h2>
            </div>
            <div class="card-body space-y-3">
                @forelse($reviewedApplications as $application)
                    <div class="border-b border-(--color-border) pb-3 last:border-0 last:pb-0">
                        <div class="flex items-center justify-between gap-3">
                            <p class="font-medium text-sm text-(--color-text-primary)">{{ $application->applicant->name }}</p>
                            <span class="badge badge-{{ $application->status === 'approved' ? 'approved' : 'rejected' }}">{{ ucfirst($application->status) }}</span>
                        </div>
                        <p class="text-xs text-(--color-text-secondary) mt-1">{{ $application->role_name }} · {{ $application->reviewed_at?->format('M d, Y') }}</p>
                    </div>
                @empty
                    <p class="text-sm text-(--color-text-secondary)">No reviewed applications.</p>
                @endforelse
            </div>
        </section>
    </div>

    <section class="card overflow-hidden">
        <div class="card-header">
            <h2 class="font-semibold text-(--color-text-primary)">Admin Assignments</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Role</th>
                        <th>Scope</th>
                        <th>Term</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($assignments as $assignment)
                        <tr>
                            <td>
                                <p class="font-medium text-(--color-text-primary)">{{ $assignment->user->name }}</p>
                                <p class="text-xs text-(--color-text-secondary) font-data">{{ $assignment->user->email }}</p>
                            </td>
                            <td>
                                <span class="badge badge-approved">{{ $assignment->role_name }}</span>
                                @if($assignment->is_primary_admin)
                                    <span class="badge badge-pending">Primary</span>
                                @endif
                            </td>
                            <td class="text-sm text-(--color-text-secondary)">{{ $assignment->organization?->acronym ?? $assignment->college?->code ?? 'University-wide' }}</td>
                            <td class="text-sm text-(--color-text-secondary)">
                                <span class="font-data">{{ $assignment->academic_year }}</span>
                                @if($assignment->term_start || $assignment->term_end)
                                    <br>{{ $assignment->term_start?->format('M d, Y') ?? 'Open' }} - {{ $assignment->term_end?->format('M d, Y') ?? 'Open' }}
                                @endif
                            </td>
                            <td><span class="badge badge-{{ $assignment->status === 'active' ? 'approved' : ($assignment->status === 'revoked' ? 'rejected' : 'pending') }}">{{ ucfirst($assignment->status) }}</span></td>
                            <td>
                                @if($assignment->status === 'active')
                                    <div class="flex flex-wrap gap-2">
                                        <form method="POST" action="{{ route('dashboard.admin-assignments.expire', $assignment) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-secondary btn-sm">Expire</button>
                                        </form>
                                        <form method="POST" action="{{ route('dashboard.admin-assignments.revoke', $assignment) }}">
                                            @csrf
                                            <input type="hidden" name="reason" value="Revoked by OSA">
                                            <button type="submit" class="btn btn-destructive btn-sm">Revoke</button>
                                        </form>
                                    </div>
                                @else
                                    <span class="text-xs text-(--color-text-secondary)">Closed</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <div class="empty-state py-8">
                                    <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1z"/></svg>
                                    <p class="empty-state-title">No assignments</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($assignments->hasPages())
            <div class="card-footer">{{ $assignments->links() }}</div>
        @endif
    </section>

    @php
        $assignmentOrganizations = $organizations->map(fn ($org) => [
            'id' => $org->id,
            'name' => $org->name,
            'acronym' => $org->acronym,
            'type' => $org->type,
            'college_id' => $org->college_id,
            'college_code' => $org->college?->code,
        ])->values();

        $assignmentColleges = $colleges->map(fn ($college) => [
            'id' => $college->id,
            'code' => $college->code,
            'name' => $college->name,
        ])->values();
    @endphp

    <dialog id="assign-admin-modal"
            class="proposal-modal modal-sm"
            aria-labelledby="assign-admin-modal-title"
            onclick="if (event.target === this) this.close()">
        <form method="POST"
              action="{{ route('dashboard.admin-users.create') }}"
              class="proposal-modal-form"
              onclick="event.stopPropagation()"
              x-data="{
                  role: @js(old('role', '')),
                  collegeId: @js(old('college_id', '')),
                  organizationId: @js(old('organization_id', '')),
                  organizations: @js($assignmentOrganizations),
                  colleges: @js($assignmentColleges),
                  roleTypes: {
                      'USG Admin': 'usg',
                      'ARO Admin': 'aro',
                      'LSG Admin': 'lsg',
                      'Society Admin': 'society'
                  },
                  roleUsesCollege() {
                      return ['LSG Admin', 'Society Admin'].includes(this.role);
                  },
                  roleUsesOrganization() {
                      return Object.keys(this.roleTypes).includes(this.role);
                  },
                  filteredOrganizations() {
                      if (!this.roleUsesOrganization()) {
                          return [];
                      }

                      const targetType = this.roleTypes[this.role];

                      return this.organizations.filter((org) => {
                          if (org.type !== targetType) {
                              return false;
                          }

                          if (this.roleUsesCollege() && this.collegeId) {
                              return org.college_id === this.collegeId;
                          }

                          if (this.roleUsesCollege() && !this.collegeId) {
                              return false;
                          }

                          return true;
                      });
                  },
                  organizationPlaceholder() {
                      if (!this.role) {
                          return 'Select role first';
                      }

                      if (!this.roleUsesOrganization()) {
                          return 'No organization scope';
                      }

                      if (this.roleUsesCollege() && !this.collegeId) {
                          return 'Select college first';
                      }

                      return 'Resolve from role/college';
                  },
                  syncRole() {
                      if (!this.roleUsesCollege()) {
                          this.collegeId = '';
                      }

                      if (!this.roleUsesOrganization()) {
                          this.organizationId = '';
                          return;
                      }

                      this.syncOrganization();
                  },
                  syncCollege() {
                      this.syncOrganization();
                  },
                  syncOrganization() {
                      if (!this.filteredOrganizations().some((org) => org.id === this.organizationId)) {
                          this.organizationId = '';
                      }
                  },
                  chooseOrganization() {
                      const org = this.organizations.find((item) => item.id === this.organizationId);
                      if (org && org.college_id && this.roleUsesCollege()) {
                          this.collegeId = org.college_id;
                      }
                  },
                  init() {
                      this.syncRole();
                  }
              }">
            @csrf
            <div class="proposal-modal-header">
                <div class="proposal-modal-title-row">
                    <span class="proposal-modal-icon" aria-hidden="true">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 4v16m8-8H4"/></svg>
                    </span>
                    <div>
                        <p class="proposal-modal-kicker">OSA assignment</p>
                        <h2 id="assign-admin-modal-title" class="proposal-modal-title">Assign Admin</h2>
                    </div>
                </div>
                <button type="button" onclick="document.getElementById('assign-admin-modal').close()" class="btn-icon btn-ghost" aria-label="Close assignment modal">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="proposal-modal-body space-y-5">
                <fieldset class="proposal-modal-section">
                    <legend class="proposal-section-title">User</legend>
                    <div class="proposal-grid">
                        <div class="proposal-grid-full">
                            <label for="admin-email" class="form-label">USM Email</label>
                            <input type="email" name="email" id="admin-email" value="{{ old('email') }}" class="form-input @error('email') form-input-error @enderror">
                            @error('email') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <div class="proposal-grid-full">
                            <label for="admin-name" class="form-label">Full Name</label>
                            <input type="text" name="name" id="admin-name" value="{{ old('name') }}" class="form-input @error('name') form-input-error @enderror">
                            @error('name') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <div class="proposal-grid-full">
                            <label for="admin-student-id" class="form-label">Student ID Number</label>
                            <input type="text" name="student_id_number" id="admin-student-id" value="{{ old('student_id_number') }}" class="form-input @error('student_id_number') form-input-error @enderror">
                            @error('student_id_number') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </fieldset>

                <fieldset class="proposal-modal-section">
                    <legend class="proposal-section-title">Assignment</legend>
                    <div class="proposal-grid">
                        <div class="proposal-grid-full">
                            <label for="admin-role" class="form-label">Role <span class="text-(--color-destructive)">*</span></label>
                            <select name="role"
                                    id="admin-role"
                                    x-model="role"
                                    @change="syncRole()"
                                    class="form-input form-select @error('role') form-input-error @enderror"
                                    required>
                                <option value="">Select role</option>
                                @foreach($adminRoles as $role)
                                    <option value="{{ $role }}" @selected(old('role') === $role)>{{ $role }}</option>
                                @endforeach
                            </select>
                            @error('role') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>

                        <div class="proposal-grid-full">
                            <label for="admin-college" class="form-label">College</label>
                            <select name="college_id"
                                    id="admin-college"
                                    x-model="collegeId"
                                    @change="syncCollege()"
                                    :disabled="!roleUsesCollege()"
                                    class="form-input form-select @error('college_id') form-input-error @enderror">
                                <option value="">None</option>
                                <template x-for="college in colleges" :key="college.id">
                                    <option :value="college.id" x-text="`${college.code} - ${college.name}`"></option>
                                </template>
                            </select>
                            @error('college_id') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>

                        <div class="proposal-grid-full">
                            <label for="admin-org" class="form-label">Organization</label>
                            <select name="organization_id"
                                    id="admin-org"
                                    x-model="organizationId"
                                    @change="chooseOrganization()"
                                    :disabled="!roleUsesOrganization() || (roleUsesCollege() && !collegeId)"
                                    class="form-input form-select @error('organization_id') form-input-error @enderror">
                                <option value="" x-text="organizationPlaceholder()"></option>
                                <template x-for="org in filteredOrganizations()" :key="org.id">
                                    <option :value="org.id" x-text="`${org.acronym} - ${org.name}${org.college_code ? ' (' + org.college_code + ')' : ''}`"></option>
                                </template>
                            </select>
                            @error('organization_id') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="academic-year" class="form-label">Academic Year</label>
                            <input type="text" name="academic_year" id="academic-year" value="{{ old('academic_year', now()->year . '-' . (now()->year + 1)) }}" class="form-input @error('academic_year') form-input-error @enderror">
                            @error('academic_year') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="position-title" class="form-label">Position Title</label>
                            <input type="text" name="position_title" id="position-title" value="{{ old('position_title') }}" class="form-input @error('position_title') form-input-error @enderror">
                            @error('position_title') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </fieldset>
            </div>

            <div class="proposal-modal-footer">
                <div class="proposal-footer-actions">
                    <button type="button" onclick="document.getElementById('assign-admin-modal').close()" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-primary">Activate Assignment</button>
                </div>
            </div>
        </form>
    </dialog>

    @if($errors->any())
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const modal = document.getElementById('assign-admin-modal');
                if (modal?.showModal && !modal.open) {
                    modal.showModal();
                }
            });
        </script>
    @endif
</x-layouts.app>
