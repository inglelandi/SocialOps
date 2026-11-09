{{-- One Heroicons outline icon (App\View\Icons) in currentColor, hidden from screen readers: the text next to it says what it is. h-5 w-5 unless the class gives a size. --}}
@props(['name'])
<svg {{ $attributes->class(['h-5 w-5' => ! str_contains((string) $attributes->get('class'), 'h-'), 'shrink-0']) }} xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" focusable="false">{!! \App\View\Icons::path($name) !!}</svg>
