<x-app-layout :client="$client">
    <x-slot name="header">
        <h2 class="text-2xl font-semibold tracking-tight text-gray-900">{{ $client->name }}: {{ __('Instagram grid') }}</h2>
    </x-slot>
    <x-slot name="actions">
        <span class="text-sm text-gray-600">{{ __('Newest first, as it will look. Drag a post on the calendar to change the order.') }}</span>
    </x-slot>

    <div class="py-6">
        <div class="max-w-md mx-auto px-4">
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
