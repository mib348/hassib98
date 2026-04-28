@php
    $text = trim((string) ($text ?? ''));
    $placement = trim((string) ($placement ?? 'top'));
    $class = trim((string) ($class ?? ''));
@endphp

@if($text !== '')
    <span
        class="admin-help-tooltip {{ $class }}"
        tabindex="0"
        role="button"
        aria-label="{{ $text }}"
        data-bs-toggle="tooltip"
        data-bs-placement="{{ $placement }}"
        data-bs-title="{{ $text }}"
    >i</span>
@endif
