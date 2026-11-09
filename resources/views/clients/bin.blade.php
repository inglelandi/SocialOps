<x-app-layout :client="$client">
    @php($platforms = ['instagram' => 'Instagram', 'facebook' => 'Facebook', 'tiktok' => 'TikTok', 'linkedin' => 'LinkedIn'])
    <x-slot name="header">
        <h2 class="text-2xl font-semibold tracking-tight text-gray-900">{{ $client->name }}: {{ __('recycle bin') }}</h2>
    </x-slot>
    @if ($posts->isNotEmpty())
        <x-slot name="actions">
            <form method="POST" action="{{ route('bin.restore-all', $client) }}">
                @csrf
                <x-secondary-button type="submit"><x-icon name="arrow-uturn-left" class="h-4 w-4" />{{ __('Restore all') }}</x-secondary-button>
            </form>
            <form method="POST" action="{{ route('bin.empty', $client) }}" class="ml-auto" onsubmit="return confirm('{{ __('Delete every post in the recycle bin for good? This cannot be undone.') }}')">
                @csrf
                @method('DELETE')
                <button type="submit" class="text-sm text-red-600 hover:underline">{{ __('Empty the recycle bin') }}</button>
            </form>
        </x-slot>
    @endif

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <p class="text-sm text-green-600">
                    {{ __(ucfirst(str_replace('-', ' ', session('status')))) }}.
                    @if ($restored = $client->posts()->find(session('restored')))
                        <a href="{{ route('posts.edit', $restored) }}" class="font-medium underline">{{ __('Open the post') }}</a>
                    @endif
                </p>
            @endif

            @if ($posts->isEmpty())
                <x-empty-state icon="trash" class="bg-white">{{ __('The recycle bin is empty. A deleted post of this client waits here until someone restores it or deletes it for good.') }}</x-empty-state>
            @else
                <x-section icon="trash" :title="__('Deleted posts')" :description="__('A deleted post waits here with its captions, media and notes. Restore puts it back on the calendar as it was. Nothing is emptied on its own.')" flush>
                    <ul class="divide-y divide-gray-200 text-sm">
                        @foreach ($posts as $post)
                            {{-- what the post is and when it was deleted, then the two buttons: side by side, stacked on a phone --}}
                            <li class="grid gap-3 px-6 py-3 sm:grid-cols-6 sm:items-center">
                                <div class="flex min-w-0 items-center gap-3 sm:col-span-4">
                                    @if ($thumb = $post->assets->firstWhere('kind', 'image'))
                                        <img src="{{ route('assets.thumb', $thumb) }}" alt="" loading="lazy" class="h-12 w-12 flex-none rounded object-cover">
                                    @else
                                        <span class="flex h-12 w-12 flex-none items-center justify-center rounded bg-gray-100 text-center text-[10px] uppercase leading-tight text-gray-500">{{ $post->assets->isEmpty() ? __('no media') : __('video') }}</span>
                                    @endif
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate font-mono text-xs text-gray-700">{{ $post->name }}</p>
                                        <p class="mt-1 flex flex-wrap items-center gap-2 text-xs text-gray-600">
                                            <x-state-badge :state="$post->state" />
                                            <span>{{ $post->scheduled_at?->translatedFormat('D d-m-Y H:i') ?? __('No date yet') }}</span>
                                            <span>{{ collect($post->platforms)->map(fn ($p) => $platforms[$p] ?? ucfirst($p))->join(', ') }}</span>
                                        </p>
                                        @if ($post->brief)
                                            <p class="mt-1 truncate text-gray-600">{{ $post->brief }}</p>
                                        @endif
                                        <p class="mt-1 text-xs text-gray-600">{{ __('Deleted :date', ['date' => $post->deleted_at->format('d-m-Y H:i')]) }}@if ($post->deleter) · {{ $post->deleter->name }}@endif</p>
                                    </div>
                                </div>
                                <div class="flex flex-wrap items-center gap-3 sm:col-span-2">
                                    <span class="hidden grow sm:block"></span>{{-- pushes the buttons to the right edge on a wide screen --}}
                                    <form method="POST" action="{{ route('posts.restore', $post) }}">
                                        @csrf
                                        <x-secondary-button type="submit"><x-icon name="arrow-uturn-left" class="h-4 w-4" />{{ __('Restore') }}</x-secondary-button>
                                    </form>
                                    <form method="POST" action="{{ route('posts.forget', $post) }}" onsubmit="return confirm('{{ __('Delete this post for good? This cannot be undone.') }}')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="inline-flex items-center gap-1 text-xs text-red-600 hover:underline"><x-icon name="trash" class="h-4 w-4" />{{ __('Delete for good') }}</button>
                                    </form>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </x-section>
            @endif
        </div>
    </div>
</x-app-layout>
