<x-app-layout :client="$client">
    @php($btn = 'inline-flex items-center rounded-lg border border-gray-400 bg-white px-3 py-1.5 text-sm font-medium text-gray-900 hover:bg-gray-50')
    @php($main = 'inline-flex items-center rounded-lg bg-brand-500 px-3.5 py-1.5 text-sm font-semibold text-white hover:bg-brand-600')
    {{-- marked published by hand, never sent through a platform API: it can go back (PostState::back()) --}}
    @php($handMarked = $post->state === \App\Posts\PostState::Published && ! $post->external_ids)
    <x-slot name="header">
        <h2 class="text-2xl font-semibold tracking-tight text-gray-900">{{ $client->name }} <span class="font-mono text-sm text-gray-600">{{ $post->name }}</span></h2>
    </x-slot>
    <x-slot name="actions">
        <x-state-badge :state="$post->state" />
        @foreach (collect($post->state->next())->sortBy(fn ($s) => $s === $post->state->forward() ? 0 : 1) as $next)
            @continue($next->needsPublishing() && ! config('features.publishing'))
            <form method="POST" action="{{ route('posts.transition', $post) }}" @if ($next === \App\Posts\PostState::Withdrawn) onsubmit="return confirm('{{ __('Take this post down on every platform? Instagram and TikTok have no API for it, so those stay until deleted by hand.') }}')" @elseif ($next === \App\Posts\PostState::Published) onsubmit="return confirm(@js(__('Did you post it in the app? This marks it published.')))" @endif>
                @csrf
                <input type="hidden" name="state" value="{{ $next->value }}">
                <button type="submit" class="{{ $next === $post->state->forward() ? $main : $btn }}">{{ __($next->action()) }}</button>
            </form>
        @endforeach
        @if ($handMarked)
            <form method="POST" action="{{ route('posts.transition', $post) }}">
                @csrf
                <input type="hidden" name="state" value="{{ $post->state->back()->value }}">
                <button type="submit" class="{{ $btn }}" title="{{ __('Back to Scheduled, or Approved when it has no date, so it can be edited again') }}">{{ __('Not published after all') }}</button>
            </form>
        @endif
        <a class="{{ $btn }}" href="{{ route('posts.export', $post) }}" title="{{ __('Media files plus a captions.txt, to post by hand or design in Canva') }}">{{ __('Export ZIP') }}</a>
        <details class="relative">
            <summary class="{{ $btn }} cursor-pointer list-none">{{ __('Duplicate') }}</summary>
            <form method="POST" action="{{ route('posts.duplicate', $post) }}" class="absolute left-0 z-10 mt-1 w-72 space-y-2 rounded-xl border border-gray-300 bg-white p-3 text-sm shadow-lg">
                @csrf
                <select name="client_id" class="block w-full border-gray-300 rounded-md shadow-sm text-sm">
                    @foreach (auth()->user()->clients()->get() as $other)
                        <option value="{{ $other->id }}" @selected($other->is($client))>{{ $other->name }}</option>
                    @endforeach
                </select>
                <input type="date" name="date" class="block w-full border-gray-300 rounded-md shadow-sm text-sm">
                <p class="text-xs text-gray-600">{{ __('A Draft with the captions and settings; the media comes along only within the same client.') }}</p>
                <x-primary-button>{{ __('Duplicate') }}</x-primary-button>
            </form>
        </details>
        @if ($post->assets->firstWhere('kind', 'image'))
            @foreach (array_keys(array_filter($post->settings['design'] ?? [])) as $lang)
                <a class="{{ $btn }}" href="{{ route('posts.image', [$post, $lang]) }}" title="{{ __('The first photo with the design text burned in, ready to post') }}">{{ __('Image :lang', ['lang' => strtoupper($lang)]) }}</a>
            @endforeach
        @endif
        @if ($post->state !== \App\Posts\PostState::Published)
            @if (config('features.claude_api'))
            <form method="POST" action="{{ route('posts.draft', $post) }}" onsubmit="this.querySelector('button').disabled = true">
                @csrf
                <button type="submit" class="{{ $btn }} border-brand-300 text-brand-700 hover:bg-brand-50">{{ __('Draft captions with Claude') }}</button>
            </form>
            @endif
            <form method="POST" action="{{ route('posts.destroy', $post) }}" class="ml-auto" onsubmit="return confirm('{{ __('Move this post to the recycle bin? It can be restored from there.') }}')">
                @csrf
                @method('DELETE')
                <button type="submit" class="text-sm text-red-600 hover:underline">{{ __('Delete') }}</button>
            </form>
        @endif
    </x-slot>
    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <p class="text-sm text-green-600">{{ __(ucfirst(str_replace('-', ' ', session('status')))) }}.</p>
            @endif
            @include('posts._repeat-drafted')
            @error('state') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            @error('draft') <p class="text-sm text-red-600">{{ __('Drafting failed') }}: {{ $message }}</p> @enderror
            @if (session('notes'))
                <p class="text-sm text-blue-800 bg-blue-50 rounded-md p-3">{{ __('Notes from the drafter, check before sending for review') }}: {{ session('notes') }}</p>
            @endif
            @if ($post->publish_error)
                <p class="text-sm text-red-800 bg-red-50 rounded-md p-3">{{ __('Publishing') }}: {{ $post->publish_error }}</p>
            @endif
            @if ($post->external_ids)
                <p class="text-sm text-green-800 bg-green-50 rounded-md p-3">{{ __('Published') }}: {{ collect($post->external_ids)->map(fn ($id, $platform) => str_replace('_', ' ', $platform)." ($id)")->join(', ') }}</p>
            @endif
            @if ($post->state->isLocked())
                @php($locked = match (true) {
                    $handMarked => __('This post is marked published and can no longer be edited. If it did not go out, use Not published after all above.'),
                    $post->state === \App\Posts\PostState::Published => __('This post is published and can no longer be edited.'),
                    default => __('This post is approved and locked. Saving a change sends it back to review.'),
                })
                <p class="text-sm text-yellow-800 bg-yellow-50 rounded-md p-3">{{ $locked }}</p>
            @endif
            @foreach ($post->reviews()->wherePivotNotNull('comment')->get() as $review)
                <p class="text-sm text-orange-800 bg-orange-50 rounded-md p-3"><span class="font-semibold">{{ $review->pivot->decided_by }}</span> ({{ \Illuminate\Support\Carbon::parse($review->pivot->decided_at)->format('d-m-Y H:i') }}): {{ $review->pivot->comment }}</p>
            @endforeach
            <div class="lg:grid lg:grid-cols-3 lg:gap-6 lg:items-start">
            <div class="lg:col-span-2">
            @if ($post->state !== \App\Posts\PostState::Published)
                <form id="post-form" method="POST" action="{{ route('posts.update', $post) }}" class="space-y-4">
                    @method('PATCH')
                    @include('posts._form')
                </form>
            @endif
            </div>

            <div class="mt-4 lg:mt-0 space-y-4 lg:sticky lg:top-4 lg:max-h-[calc(100vh-2rem)] lg:overflow-y-auto">
            @include('posts._preview')
            @php($notesHelp = __('internal, the client never sees them; @FirstName pings a colleague on Slack'))
            <x-section id="notes" :title="__('Team notes')" :description="$notesHelp" class="text-sm">
                @foreach ($post->notes as $note)
                    <p class="py-3 whitespace-pre-line"><span class="font-semibold">{{ $note->user?->name ?? __('removed login') }}</span> <span class="text-xs text-gray-600">{{ $note->created_at->format('d-m-Y H:i') }}</span><br>{{ $note->body }}</p>
                @endforeach
                <form method="POST" action="{{ route('posts.note', $post) }}" class="py-4">
                    @csrf
                    <textarea name="body" rows="2" required maxlength="2000" class="block w-full rounded-md border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500" placeholder="{{ __('e.g. @Maria can you check the Greek?') }}">{{ old('body') }}</textarea>
                    <x-input-error class="mt-1" :messages="$errors->get('body')" />
                    <x-primary-button class="mt-2">{{ __('Add note') }}</x-primary-button>
                </form>
            </x-section>
            </div>
            </div>
        </div>
    </div>
</x-app-layout>
