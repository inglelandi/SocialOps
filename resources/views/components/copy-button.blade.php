{{-- Copies its text to the clipboard (window.copyText on the Post today page) and keeps saying "Copied" until the page reloads, so after a trip to the app it shows what was already taken; where the browser refuses, the text comes up to copy by hand. `main` marks the copy most people want (brand border). --}}
@props(['text', 'main' => false])
@php($idle = $main ? 'border-brand-500 bg-white text-brand-700 hover:bg-brand-50' : 'border-gray-400 bg-white text-gray-900 hover:bg-gray-50')
<button type="button" x-data="{ copied: false }" data-copy="{{ $text }}"
        x-on:click="copyText($el.dataset.copy).then(() => copied = true).catch(() => prompt('', $el.dataset.copy))"
        :class="copied ? 'border-green-600 bg-green-50 text-green-800' : '{{ $idle }}'"
        {{ $attributes->class(['inline-flex min-h-11 items-center justify-center gap-1.5 rounded-lg px-4 py-2 text-sm font-semibold transition', $main ? 'border-2' : 'border']) }}>
    <span x-show="! copied" class="inline-flex items-center gap-1.5"><x-icon name="clipboard-document" class="h-4 w-4" />{{ $slot }}</span>
    <span x-show="copied" x-cloak class="inline-flex items-center gap-1.5"><x-icon name="check" class="h-4 w-4" />{{ __('Copied') }}</span>
</button>
