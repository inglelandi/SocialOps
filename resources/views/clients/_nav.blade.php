@php($icons = ['basics' => 'building-storefront', 'about' => 'information-circle', 'agency' => 'briefcase', 'voice' => 'microphone', 'content' => 'squares-plus', 'approval' => 'check-badge', 'timing' => 'clock', 'research' => 'magnifying-glass', 'more' => 'chat-bubble-left-ellipsis', 'plan' => 'sparkles', 'import' => 'arrow-up-tray'])
{{-- The section list of the client page. `tab` lives on the page's x-data wrapper; $tools adds the edit page's own boxes under a heading. --}}
@php($sections = ['basics' => __('Basics'), 'about' => __('About'), 'agency' => __('Agency'), 'voice' => __('Voice'), 'content' => __('Content'), 'approval' => __('Approval'), 'timing' => __('Timing'), 'research' => __('Research'), 'more' => __('Replies and webhooks')])
<nav class="mb-4 flex gap-1 overflow-x-auto text-sm md:sticky md:top-6 md:mb-0 md:w-52 md:shrink-0 md:flex-col md:self-start" role="tablist">
    @foreach ($sections + ($tools ?? []) as $key => $label)
        @if (isset($tools) && $key === array_key_first($tools))
            <p class="mb-1 mt-4 hidden px-3 text-xs font-semibold uppercase tracking-wide text-gray-500 md:block">{{ __('Tools') }}</p>
        @endif
        <button type="button" role="tab" data-tab="{{ $key }}" class="inline-flex items-center gap-2 whitespace-nowrap rounded-md px-3 py-2 text-left" :class="tab === '{{ $key }}' ? 'bg-white font-medium text-gray-900 shadow-sm ring-1 ring-gray-200' : 'text-gray-600 hover:text-gray-900'" :aria-selected="tab === '{{ $key }}'" x-on:click="tab = '{{ $key }}'">@isset($icons[$key])<x-icon :name="$icons[$key]" class="h-4 w-4" />@endisset{{ $label }}</button>
    @endforeach
</nav>
