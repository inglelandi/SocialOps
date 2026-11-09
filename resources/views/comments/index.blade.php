<x-app-layout :client="$client">
    <x-slot name="header">
        <h2 class="text-2xl font-semibold tracking-tight text-gray-900">{{ $client->name }}: {{ __('comments and messages') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4 text-sm">
            @if (session('status'))
                <p class="flex items-center gap-1.5 text-green-600"><x-icon name="check-circle" class="h-5 w-5" />{{ str_starts_with(session('status'), 'Claude: ') ? session('status') : __(ucfirst(str_replace('-', ' ', session('status')))).'.' }}</p>
            @endif
            @error('comment') <p class="text-red-600">{{ $message }}</p> @enderror

            @php($fetched = __('Comments under the last 30 days of Instagram and Facebook posts and direct messages of the last 30 days, fetched every hour. Claude drafts, you send. Meta takes a message reply within 24 hours of the person\'s last message.'))
            <x-section icon="inbox" :title="__('To answer').' ('.$open->count().')'" :description="$fetched">
                <form method="GET" class="flex flex-wrap items-center gap-2 py-4">
                    <nav class="flex gap-1 overflow-x-auto text-sm" aria-label="{{ __('Kinds') }}">
                        @foreach (['attention' => __('Needs attention'), 'questions' => __('Questions'), 'complaints' => __('Complaints'), 'all' => __('All')] as $key => $label)
                            <a href="{{ route('clients.comments', [$client, ...request()->only('platform', 'order'), 'tab' => $key]) }}" class="whitespace-nowrap rounded-full px-3 py-1 {{ $tab === $key ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">{{ $label }} <span class="{{ $tab === $key ? 'text-gray-300' : 'text-gray-500' }}">{{ $tabs[$key] }}</span></a>
                        @endforeach
                    </nav>
                    <input type="hidden" name="tab" value="{{ $tab }}">
                    <select name="platform" onchange="this.form.submit()" class="ml-auto rounded-md border-gray-300 py-1.5 text-sm">
                        <option value="">{{ __('Any platform') }}</option>
                        @foreach ($platforms as $platform)
                            <option value="{{ $platform }}" @selected(request('platform') === $platform)>{{ str_replace('_', ' ', ucfirst($platform)) }}</option>
                        @endforeach
                    </select>
                    <select name="order" onchange="this.form.submit()" class="rounded-md border-gray-300 py-1.5 text-sm">
                        <option value="">{{ __('Needs attention first, then newest') }}</option>
                        <option value="oldest" @selected(request('order') === 'oldest')>{{ __('Oldest first') }}</option>
                    </select>
                </form>
                @forelse ($open as $comment)
                    <div id="comment-{{ $comment->id }}" class="grid gap-3 py-4 md:grid-cols-2">
                        <div>
                            <p class="text-gray-600">{{ $comment->label() }} · <span class="font-medium text-gray-800">{{ $comment->author ?? '?' }}</span> · {{ $comment->posted_at->translatedFormat('D d-m-Y H:i') }}
                                @if ($comment->post) · <a href="{{ route('posts.edit', $comment->post) }}" class="font-mono text-xs font-medium text-gray-900 hover:text-brand-600">{{ $comment->post->name }}</a> @endif
                            </p>
                            @if ($comment->intent)
                                <p class="mt-1 text-xs">
                                    @if ($comment->needsAttention()) <span class="px-2 py-0.5 rounded bg-red-100 text-red-800">{{ __('needs attention') }}</span> @endif
                                    @if ($comment->intent === 'spam') <span class="px-2 py-0.5 rounded bg-gray-100 text-gray-600">{{ __('looks like spam') }}</span> @endif
                                    <span class="text-gray-600">{{ __($comment->sentiment) }} · {{ __($comment->intent) }}</span>
                                </p>
                            @endif
                            <p class="mt-1 whitespace-pre-line">{{ $comment->text }}</p>
                            <div class="mt-2 flex gap-3">
                                <form method="POST" action="{{ route('comments.draft', $comment) }}" onsubmit="this.querySelector('button').disabled = true">@csrf<button class="font-medium text-gray-900 hover:text-brand-600">{{ __('Draft reply with Claude') }}</button></form>
                                @if (! $comment->thread_id)
                                    <form method="POST" action="{{ route('comments.hide', $comment) }}" onsubmit="return confirm('{{ __('Hide this comment on the platform?') }}')">@csrf<button class="text-gray-600 hover:underline">{{ __('Hide') }}</button></form>
                                @endif
                            </div>
                        </div>
                        <form method="POST" action="{{ route('comments.reply', $comment) }}">
                            @csrf
                            @if ($saved = $client->profile['saved_replies'] ?? [])
                                <select class="mb-2 block w-full border-gray-300 rounded-md shadow-sm text-sm" onchange="if (this.value) this.form.reply.value = this.value; this.selectedIndex = 0">
                                    <option value="">{{ __('Saved reply…') }}</option>
                                    @foreach ($saved as $item)
                                        <option value="{{ $item['text'] }}">{{ $item['label'] }}</option>
                                    @endforeach
                                </select>
                            @endif
                            <textarea name="reply" rows="3" class="block w-full border-gray-300 focus:border-brand-500 focus:ring-brand-500 rounded-md shadow-sm text-sm" placeholder="{{ __('Your reply') }}">{{ old('reply', $comment->reply) }}</textarea>
                            <x-primary-button class="mt-2"><x-icon name="paper-airplane" class="h-4 w-4" />{{ __('Send reply') }}</x-primary-button>
                        </form>
                    </div>
                @empty
                    @if ($tab !== 'all' || request('platform'))
                        <x-empty-state icon="funnel" class="my-4" :action="__('Show everything')" :href="route('clients.comments', $client)">{{ __('Nothing here; the other tabs may still have something.') }}</x-empty-state>
                    @elseif ($client->accounts()->whereIn('platform', ['instagram', 'facebook'])->doesntExist())
                        <x-empty-state icon="link" class="my-4" :action="__('Connect an account')" :href="route('clients.accounts', $client)">{{ __('Nothing to answer yet: connect Instagram or Facebook and comments and messages arrive here every hour.') }}</x-empty-state>
                    @else
                        <x-empty-state icon="chat-bubble-left-right" class="my-4">{{ __('Nothing to answer. New comments and messages arrive every hour.') }}</x-empty-state>
                    @endif
                @endforelse
            </x-section>

            @if ($done->isNotEmpty())
                <x-section icon="archive-box" :title="__('Answered or hidden, last 30 days')">
                    @foreach ($done as $comment)
                        <div id="comment-{{ $comment->id }}" class="py-3">
                            <p class="text-gray-600">{{ $comment->label() }} · <span class="font-medium text-gray-800">{{ $comment->author ?? '?' }}</span> · {{ $comment->posted_at->translatedFormat('D d-m-Y H:i') }} · {{ $comment->hidden_at ? __('hidden') : __('replied :when', ['when' => $comment->replied_at->translatedFormat('D d-m-Y H:i')]) }}</p>
                            <p class="mt-1 whitespace-pre-line">{{ $comment->text }}</p>
                            @if ($comment->replied_at)<p class="mt-1 pl-4 border-l-2 whitespace-pre-line text-gray-700">{{ $comment->reply }}</p>@endif
                        </div>
                    @endforeach
                </x-section>
            @endif
        </div>
    </div>
</x-app-layout>
