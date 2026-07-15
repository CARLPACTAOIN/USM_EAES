<x-layouts.app :title="'Organization Profile — EAES'">
@php
    $typeLabel = str($organization->type)->replace('_', ' ')->title();
    $programs = $organization->programs->sortBy('code')->values();
@endphp

<div class="flex flex-wrap items-start justify-between gap-4 mb-8">
    <div>
        <p class="portal-page-kicker">Organization workspace</p>
        <h1 class="text-2xl font-display text-(--color-text-primary)">Organization Profile</h1>
        <p class="text-sm text-(--color-text-secondary) mt-1 max-w-2xl">
            Manage the public logo used for {{ $organization->acronym }} records while OSA keeps official organization details controlled in Admin Access.
        </p>
    </div>
    <span class="badge {{ $organization->status === 'active' ? 'badge-approved' : 'badge-rejected' }}">
        {{ ucfirst($organization->status) }}
    </span>
</div>

<div class="grid grid-cols-1 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)] gap-6">
    <section class="card">
        <div class="card-header flex items-center gap-3">
            <div class="w-9 h-9 rounded-lg bg-(--color-accent)/10 border border-(--color-accent)/20 flex items-center justify-center text-(--color-accent)" aria-hidden="true">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M3 16.5l4.72-4.72a2.25 2.25 0 013.18 0L15 15.88m-1.5-1.5l1.72-1.72a2.25 2.25 0 013.18 0L21 15.25M4.5 19.5h15A1.5 1.5 0 0021 18V6a1.5 1.5 0 00-1.5-1.5h-15A1.5 1.5 0 003 6v12a1.5 1.5 0 001.5 1.5zm3-11.25h.008v.008H7.5V8.25z"/>
                </svg>
            </div>
            <div>
                <h2 class="font-semibold text-(--color-text-primary)">Organization Logo</h2>
                <p class="text-xs text-(--color-text-tertiary) mt-0.5">PNG, JPG, or WebP only. Maximum 2MB.</p>
            </div>
        </div>

        <div class="card-body space-y-6">
            <div class="rounded-2xl border border-(--color-border) bg-(--color-surface-raised)/70 p-5 flex flex-col sm:flex-row items-center gap-5">
                <x-organization-mark
                    :organization="$organization"
                    class="w-32 h-32 rounded-2xl text-3xl"
                />
                <div class="min-w-0 text-center sm:text-left">
                    <p class="text-sm font-semibold text-(--color-text-primary)">{{ $organization->name }}</p>
                    <p class="text-xs text-(--color-text-tertiary) mt-1">{{ $organization->acronym }} · {{ $typeLabel }}</p>
                    <p class="text-xs text-(--color-text-secondary) mt-3">
                        The logo is stored on the public disk and replaces the current file when a new logo is uploaded.
                    </p>
                </div>
            </div>

            <form
                method="POST"
                action="{{ route('dashboard.organization-profile.logo.update') }}"
                enctype="multipart/form-data"
                class="space-y-4"
                x-data="{
                    previewUrl: null,
                    fileName: '',
                    fileSize: '',
                    previewError: '',
                    setPreview(event) {
                        const file = event.target.files[0] || null;

                        if (this.previewUrl) {
                            URL.revokeObjectURL(this.previewUrl);
                        }

                        this.previewUrl = null;
                        this.fileName = '';
                        this.fileSize = '';
                        this.previewError = '';

                        if (!file) {
                            return;
                        }

                        if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) {
                            this.previewError = 'Choose a PNG, JPG, or WebP image.';
                            return;
                        }

                        if (file.size > 2 * 1024 * 1024) {
                            this.previewError = 'This file is over the 2MB limit.';
                            return;
                        }

                        this.previewUrl = URL.createObjectURL(file);
                        this.fileName = file.name;
                        const kilobytes = Math.max(1, Math.round(file.size / 1024));
                        this.fileSize = kilobytes >= 1024
                            ? (kilobytes / 1024).toFixed(2) + ' MB'
                            : kilobytes + ' KB';
                    },
                    clearPreview() {
                        if (this.previewUrl) {
                            URL.revokeObjectURL(this.previewUrl);
                        }

                        this.previewUrl = null;
                        this.fileName = '';
                        this.fileSize = '';
                        this.previewError = '';
                        this.$refs.logoInput.value = '';
                    }
                }"
                @beforeunload.window="if (previewUrl) URL.revokeObjectURL(previewUrl)"
            >
                @csrf
                @method('PUT')

                <div>
                    <label for="logo" class="form-label">Upload logo <span class="text-(--color-destructive)">*</span></label>
                    <input
                        id="logo"
                        name="logo"
                        type="file"
                        accept="image/png,image/jpeg,image/webp"
                        class="form-input @error('logo') form-input-error @enderror"
                        required
                        x-ref="logoInput"
                        @change="setPreview($event)"
                        aria-describedby="logo-help @error('logo') logo-error @enderror"
                    >
                    <p id="logo-help" class="mt-1 text-xs text-(--color-text-tertiary)">Use a square or transparent logo when possible. Files over 2MB are rejected.</p>
                    @error('logo')
                        <p id="logo-error" class="form-error" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div
                    x-show="previewUrl || previewError"
                    x-transition.opacity
                    x-cloak
                    class="rounded-2xl border border-(--color-accent)/25 bg-(--color-accent)/5 p-4"
                    aria-live="polite"
                >
                    <template x-if="previewUrl">
                        <div class="flex flex-col sm:flex-row items-center gap-4">
                            <div class="w-28 h-28 rounded-2xl border border-(--color-border) bg-(--color-surface) flex items-center justify-center overflow-hidden shrink-0">
                                <img :src="previewUrl" :alt="'Selected logo preview for ' + fileName" class="w-full h-full object-contain p-3">
                            </div>
                            <div class="min-w-0 text-center sm:text-left flex-1">
                                <p class="text-xs font-semibold uppercase text-(--color-accent)">Selected logo preview</p>
                                <p class="mt-1 text-sm font-semibold text-(--color-text-primary) truncate" x-text="fileName"></p>
                                <p class="mt-1 text-xs text-(--color-text-tertiary)" x-text="fileSize"></p>
                                <p class="mt-3 text-xs text-(--color-text-secondary)">This preview is local only. The organization logo changes after you upload it.</p>
                            </div>
                            <button type="button" class="btn btn-secondary btn-sm min-h-10 shrink-0" @click="clearPreview()">
                                Clear
                            </button>
                        </div>
                    </template>

                    <template x-if="previewError">
                        <div class="flex items-start gap-3 text-(--color-destructive)" role="alert">
                            <svg class="w-5 h-5 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M12 9v3.75m0 3.75h.008v.008H12V16.5zm8.25-4.5a8.25 8.25 0 11-16.5 0 8.25 8.25 0 0116.5 0z"/>
                            </svg>
                            <div>
                                <p class="text-sm font-semibold">Preview unavailable</p>
                                <p class="text-xs mt-0.5" x-text="previewError"></p>
                            </div>
                        </div>
                    </template>
                </div>

                <button type="submit" class="btn btn-primary min-h-11 gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M12 16.5V3.75m0 0L7.5 8.25M12 3.75l4.5 4.5M4.5 20.25h15"/>
                    </svg>
                    {{ $organization->logo_path ? 'Replace Logo' : 'Upload Logo' }}
                </button>
            </form>

            <div class="rounded-xl border border-(--color-destructive)/20 bg-(--color-destructive-light)/50 p-4">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-(--color-text-primary)">Remove current logo</p>
                        <p class="text-xs text-(--color-text-secondary) mt-0.5">This clears the organization logo and returns the profile to its acronym fallback.</p>
                    </div>
                    @if($organization->logo_path)
                        <form method="POST" action="{{ route('dashboard.organization-profile.logo.destroy') }}" onsubmit="return confirm('Remove the current organization logo?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-destructive btn-sm min-h-10">Remove Logo</button>
                        </form>
                    @else
                        <button type="button" class="btn btn-secondary btn-sm min-h-10 opacity-60 cursor-not-allowed" disabled>No Logo Set</button>
                    @endif
                </div>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card-header flex items-center gap-3">
            <div class="w-9 h-9 rounded-lg bg-(--color-success-light) border border-(--color-success)/20 flex items-center justify-center text-(--color-success)" aria-hidden="true">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M3.75 21h16.5M4.5 3h15l-.75 18H5.25L4.5 3zm3.75 4.5h7.5m-7.5 4h7.5m-7.5 4h4"/>
                </svg>
            </div>
            <div>
                <h2 class="font-semibold text-(--color-text-primary)">Official Details</h2>
                <p class="text-xs text-(--color-text-tertiary) mt-0.5">Read-only data controlled by OSA governance.</p>
            </div>
        </div>

        <div class="card-body space-y-5">
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="rounded-xl border border-(--color-border) bg-(--color-surface-raised)/50 p-4">
                    <dt class="text-xs font-semibold uppercase text-(--color-text-tertiary)">Name</dt>
                    <dd class="mt-1 text-sm font-medium text-(--color-text-primary)">{{ $organization->name }}</dd>
                </div>
                <div class="rounded-xl border border-(--color-border) bg-(--color-surface-raised)/50 p-4">
                    <dt class="text-xs font-semibold uppercase text-(--color-text-tertiary)">Acronym</dt>
                    <dd class="mt-1 text-sm font-data font-semibold text-(--color-text-primary)">{{ $organization->acronym }}</dd>
                </div>
                <div class="rounded-xl border border-(--color-border) bg-(--color-surface-raised)/50 p-4">
                    <dt class="text-xs font-semibold uppercase text-(--color-text-tertiary)">Type</dt>
                    <dd class="mt-1 text-sm font-medium text-(--color-text-primary)">{{ $typeLabel }}</dd>
                </div>
                <div class="rounded-xl border border-(--color-border) bg-(--color-surface-raised)/50 p-4">
                    <dt class="text-xs font-semibold uppercase text-(--color-text-tertiary)">College</dt>
                    <dd class="mt-1 text-sm font-medium text-(--color-text-primary)">{{ $organization->college?->name ?? 'University-wide' }}</dd>
                </div>
            </dl>

            <div class="rounded-xl border border-(--color-border) bg-(--color-surface-raised)/50 p-4">
                <div class="flex items-center justify-between gap-3 mb-3">
                    <h3 class="text-sm font-semibold text-(--color-text-primary)">Covered Programs</h3>
                    <span class="badge">{{ $programs->count() }} {{ Str::plural('program', $programs->count()) }}</span>
                </div>

                @if($programs->isNotEmpty())
                    <div class="flex flex-wrap gap-2">
                        @foreach($programs as $program)
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg border border-(--color-border) bg-(--color-surface) text-xs text-(--color-text-secondary)">
                                <span class="font-data font-semibold text-(--color-text-primary)">{{ $program->code }}</span>
                                {{ $program->name }}
                            </span>
                        @endforeach
                    </div>
                @else
                    <div class="empty-state py-6">
                        <p class="empty-state-title">No covered programs</p>
                        <p class="empty-state-description">University-wide or office units may not be tied to student academic programs.</p>
                    </div>
                @endif
            </div>
        </div>
    </section>
</div>
</x-layouts.app>
