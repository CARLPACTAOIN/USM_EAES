@props([
    'organization' => null,
    'label' => null,
])

@php
    $name = $organization?->name ?? 'Organization';
    $acronym = $organization?->acronym ?: $name;
    $fallback = str($acronym)->substr(0, 2)->upper();
    $logoUrl = $organization?->logo_path
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($organization->logo_path)
        : null;
@endphp

<span {{ $attributes->merge([
    'class' => 'inline-flex items-center justify-center rounded-xl border border-(--color-border) bg-(--color-surface) text-(--color-accent) font-display font-bold overflow-hidden shrink-0',
]) }}>
    @if($logoUrl)
        <img
            src="{{ $logoUrl }}"
            alt="{{ $label ?? $acronym . ' organization logo' }}"
            class="w-full h-full object-contain p-1.5"
            loading="lazy"
        >
    @else
        <span aria-hidden="true">{{ $fallback }}</span>
        <span class="sr-only">{{ $label ?? $acronym . ' organization mark' }}</span>
    @endif
</span>
