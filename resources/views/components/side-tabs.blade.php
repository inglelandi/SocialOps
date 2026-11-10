{{-- A page's side list of sections (the Files page): one button per section, in groups under an optional heading, with an optional count. `tab` lives on the page's x-data; phones get a scrolling row. --}}
@props(['groups', 'label' => null])
<nav {{ $attributes->class(['mb-4 flex gap-1 overflow-x-auto text-sm md:sticky md:top-6 md:mb-0 md:w-56 md:shrink-0 md:flex-col md:self-start']) }} role="tablist" @if ($label) aria-label="{{ $label }}" @endif>
    @foreach ($groups as [$heading, $tabs])
        @if ($heading)
            <p class="mb-1 mt-4 hidden px-3 text-xs font-semibold uppercase tracking-wide text-gray-500 md:block">{{ $heading }}</p>
        @endif
        @foreach ($tabs as $key => [$name, $icon, $count])
            <button type="button" role="tab" data-tab="{{ $key }}" class="inline-flex items-center gap-2 whitespace-nowrap rounded-md px-3 py-2 text-left" :class="tab === '{{ $key }}' ? 'bg-white font-medium text-gray-900 shadow-sm ring-1 ring-gray-200' : 'text-gray-600 hover:text-gray-900'" :aria-selected="tab === '{{ $key }}'" x-on:click="tab = '{{ $key }}'"><x-icon :name="$icon" class="h-4 w-4" />{{ $name }}@if ($count)<span class="ml-auto rounded-full bg-brand-100 px-1.5 text-xs text-brand-700">{{ $count }}</span>@endif</button>
        @endforeach
    @endforeach
</nav>
