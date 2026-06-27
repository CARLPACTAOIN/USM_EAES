<x-layouts.app :title="'Evaluation — ' . $event->title . ' — EAES'">
    <div class="mb-8">
        <a href="{{ route('portal') }}" class="inline-flex items-center gap-1 text-sm text-(--color-text-secondary) hover:text-(--color-accent) transition-colors mb-3">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            Back to Portal
        </a>
        <h1 class="text-2xl font-display text-(--color-text-primary)">Event Evaluation</h1>
        <p class="text-sm text-(--color-text-secondary) mt-1">{{ $event->title }}</p>
    </div>

    <div class="max-w-2xl">
        {{-- Event Info Card --}}
        <div class="card mb-6">
            <div class="card-body flex flex-col sm:flex-row items-start gap-4">
                <div class="w-10 h-10 rounded-lg flex items-center justify-center shrink-0" style="background-color: var(--color-accent-subtle);">
                    <svg class="w-5 h-5" style="color: var(--color-accent);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                </div>
                <div>
                    <p class="font-semibold text-(--color-text-primary)">{{ $event->title }}</p>
                    <p class="text-sm text-(--color-text-secondary) mt-0.5">
                        {{ $event->start_date->format('M d, Y') }}
                        @if($event->end_date && $event->end_date->ne($event->start_date))
                            — {{ $event->end_date->format('M d, Y') }}
                        @endif
                    </p>
                    @if($windowCloseAt)
                    <p class="text-xs mt-2 {{ $windowOpen ? 'text-(--color-success)' : 'text-(--color-destructive)' }}">
                        @if($windowOpen)
                            <svg class="w-3.5 h-3.5 inline-block mr-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            Evaluation open until {{ $windowCloseAt->format('M d, Y g:i A') }}
                        @else
                            <svg class="w-3.5 h-3.5 inline-block mr-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                            Evaluation window has closed
                        @endif
                    </p>
                    @endif
                </div>
            </div>
        </div>

        {{-- Already Submitted --}}
        @if($existing)
        <div class="card">
            <div class="card-body text-center py-8">
                <div class="w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4" style="background-color: var(--color-success-light);">
                    <svg class="w-8 h-8" style="color: var(--color-success);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <h3 class="text-lg font-semibold text-(--color-text-primary)">Evaluation Submitted</h3>
                <p class="text-sm text-(--color-text-secondary) mt-2">
                    You submitted your evaluation on {{ $existing->submitted_at->format('M d, Y g:i A') }}.
                    Your attendance has been validated.
                </p>
                <a href="{{ route('portal') }}" class="btn btn-secondary mt-4">Return to Portal</a>
            </div>
        </div>
        @elseif(!$windowOpen)
        <div class="card">
            <div class="card-body text-center py-8">
                <div class="w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4" style="background-color: var(--color-destructive-light);">
                    <svg class="w-8 h-8" style="color: var(--color-destructive);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                </div>
                <h3 class="text-lg font-semibold text-(--color-text-primary)">Window Closed</h3>
                <p class="text-sm text-(--color-text-secondary) mt-2">The evaluation window for this event has closed.</p>
                <a href="{{ route('portal') }}" class="btn btn-secondary mt-4">Return to Portal</a>
            </div>
        </div>
        @else
        {{-- Evaluation Form --}}
        <form method="POST" action="{{ route('portal.evaluation.submit') }}" class="card"
              x-data="confirmAction" @submit.prevent="askConfirm('Are you sure you want to submit this evaluation? This action cannot be undone.', $el)">
            @csrf
            <input type="hidden" name="event_id" value="{{ $event->id }}">

            <div class="card-header">
                <h2 class="font-semibold text-(--color-text-primary)">Rate this event</h2>
                <p class="text-xs text-(--color-text-tertiary) mt-1">1 = Poor, 5 = Excellent</p>
            </div>

            <div class="card-body space-y-6">
                @foreach($categories as $key => $category)
                <div>
                    <label class="form-label">{{ $category['label'] }} <span class="text-(--color-destructive)">*</span></label>
                    <p class="text-xs text-(--color-text-tertiary) mb-2">{{ $category['description'] }}</p>
                    <div class="likert-group">
                        @for($i = 1; $i <= 5; $i++)
                        <div class="likert-option">
                            <input type="radio" name="section_scores[{{ $key }}]" id="{{ $key }}_{{ $i }}"
                                   value="{{ $i }}" {{ old("section_scores.$key") == $i ? 'checked' : '' }} required>
                            <label for="{{ $key }}_{{ $i }}">{{ $i }}</label>
                        </div>
                        @endfor
                    </div>
                    @error("section_scores.$key") <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
                @endforeach

                {{-- Open Comment --}}
                <div>
                    <label for="open_comment" class="form-label">Additional Comments</label>
                    <textarea name="open_comment" id="open_comment" rows="4"
                              placeholder="Share your thoughts about this event (optional)..."
                              class="form-input @error('open_comment') form-input-error @enderror"
                              maxlength="2000">{{ old('open_comment') }}</textarea>
                    <p class="form-helper">Comments can be in English, Tagalog, or Taglish</p>
                    @error('open_comment') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="card-footer flex items-center justify-end gap-3">
                <a href="{{ route('portal') }}" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                    Submit Evaluation
                </button>
            </div>

            {{-- Confirmation Modal --}}
            <template x-if="showConfirm">
                <div class="modal-backdrop" @click.self="cancelConfirm()" @keydown.escape.window="cancelConfirm()">
                    <div class="modal-content p-6">
                        <div class="text-center">
                            <div class="w-12 h-12 rounded-full flex items-center justify-center mx-auto mb-4" style="background-color: var(--color-warning-light);">
                                <svg class="w-6 h-6" style="color: var(--color-warning);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                            </div>
                            <h3 class="text-lg font-semibold text-(--color-text-primary) mb-2">Confirm Submission</h3>
                            <p class="text-sm text-(--color-text-secondary)" x-text="confirmMessage"></p>
                        </div>
                        <div class="flex gap-3 mt-6 justify-center">
                            <button type="button" @click="cancelConfirm()" class="btn btn-secondary">Cancel</button>
                            <button type="button" @click="doConfirm()" class="btn btn-primary">Yes, Submit</button>
                        </div>
                    </div>
                </div>
            </template>
        </form>
        @endif
    </div>
</x-layouts.app>
