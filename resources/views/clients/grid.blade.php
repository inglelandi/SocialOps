<x-app-layout :client="$client">
    <x-slot name="header">
        <h2 class="text-2xl font-semibold tracking-tight text-gray-900">{{ $client->name }}: {{ __('Instagram grid') }}</h2>
    </x-slot>
    <x-slot name="actions">
        <span class="text-sm text-gray-600">{{ __('Newest first, as it will look. Drag a post on the calendar to change the order.') }}</span>
    </x-slot>

    <div class="py-6">
        <div class="max-w-md mx-auto px-4">
            {{-- one of the calendar's views: the same switch, Grid on --}}
            <p class="mb-4 flex justify-center">
                <span class="inline-flex rounded-lg bg-gray-100 p-0.5 text-sm">
                    @foreach (['month' => [__('Month'), 'calendar-days'], 'week' => [__('Week'), 'calendar'], 'list' => [__('List'), 'list-bullet']] as $one => [$label, $icon])
                        <a class="inline-flex items-center gap-1.5 rounded-md px-3 py-1 text-gray-600 hover:text-gray-900" href="{{ route('calendar', ['client' => $client->slug, 'view' => $one]) }}"><x-icon :name="$icon" class="h-4 w-4" />{{ $label }}</a>
                    @endforeach
                    <span class="inline-flex items-center gap-1.5 rounded-md bg-white px-3 py-1 font-medium text-gray-900 shadow-sm" aria-current="page"><x-icon name="squares-2x2" class="h-4 w-4" />{{ __('Grid') }}</span>
                </span>
            </p>
            @if ($posts->isEmpty())
                <x-empty-state icon="squares-2x2" action-icon="plus" class="mb-4 bg-white" :action="__('New post')" :href="route('posts.create', $client)">{{ __('No Instagram feed posts with a date yet. Feed, carousel and Reel posts show here as the grid will look, newest first.') }}</x-empty-state>
            @endif
            <div class="grid grid-cols-3 gap-px overflow-hidden rounded-xl border border-gray-300 bg-gray-200">
                @foreach ($posts as $post)
                    @php($asset = $post->assets->first())
                    <a href="{{ route('posts.edit', $post) }}" class="relative block aspect-square bg-gray-900 overflow-hidden" title="{{ $post->name }}: {{ __($post->state->label()) }}">
                        @if ($asset?->kind === 'image')
                            <img src="{{ route('assets.thumb', $asset) }}" alt="" class="w-full h-full object-cover" loading="lazy">
                        @else
                            <span class="absolute inset-0 flex items-center justify-center text-xs text-gray-300">{{ $asset ? __('video') : __('no image yet') }}</span>
                        @endif
                        @if ($post->format !== 'feed')
                            <span class="absolute top-1 right-1 rounded bg-black/60 px-1 text-[10px] text-white">{{ $post->format === 'reel' ? '▶' : '▣' }}</span>
                        @endif
                        <span class="absolute bottom-0 inset-x-0 flex items-center justify-between gap-1 bg-black/60 px-1 py-0.5 text-[10px] text-white"><span class="whitespace-nowrap">{{ $post->scheduled_at->format('d-m-Y') }}</span> <x-state-badge :state="$post->state" class="min-w-0 truncate" /></span>
                    </a>
                @endforeach
            </div>
        </div>
    </div>
</x-app-layout>
