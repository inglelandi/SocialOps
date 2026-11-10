@php($icons = ['basics' => 'building-storefront', 'about' => 'information-circle', 'agency' => 'briefcase', 'voice' => 'microphone', 'content' => 'squares-plus', 'approval' => 'check-badge', 'timing' => 'clock', 'research' => 'magnifying-glass', 'more' => 'chat-bubble-left-ellipsis'])
{{-- The section list of the client's Profile page. `tab` lives on the page's x-data wrapper. --}}
@php($sections = ['basics' => __('Basics'), 'about' => __('About'), 'agency' => __('Agency'), 'voice' => __('Voice'), 'content' => __('Content'), 'approval' => __('Approval'), 'timing' => __('Timing'), 'research' => __('Research'), 'more' => __('Replies and webhooks')])
<nav class="mb-4 flex gap-1 overflow-x-auto text-sm md:sticky md:top-6 md:mb-0 md:w-52 md:shrink-0 md:flex-col md:self-start" role="tablist">
    @foreach ($sections as $key => $label)
        <button type="button" role="tab" data-tab="{{ $key }}" class="inline-flex items-center gap-2 whitespace-nowrap rounded-md px-3 py-2 text-left" :class="tab === '{{ $key }}' ? 'bg-white font-medium text-gray-900 shadow-sm ring-1 ring-gray-200' : 'text-gray-600 hover:text-gray-900'" :aria-selected="tab === '{{ $key }}'" x-on:click="tab = '{{ $key }}'">@isset($icons[$key])<x-icon :name="$icons[$key]" class="h-4 w-4" />@endisset{{ $label }}</button>
    @endforeach
</nav>
