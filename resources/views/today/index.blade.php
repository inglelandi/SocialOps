{{-- Post today: what goes out by hand today or tomorrow, each post with its media to download, the texts to paste and Mark published. Built for a phone first. --}}
<x-app-layout>
    @php($keep = fn (array $over = []) => array_filter([...['day' => $tomorrow ? 'tomorrow' : null, 'client' => $selected?->slug], ...$over]))
    <x-slot name="header">
        <h2 class="text-2xl font-semibold tracking-tight text-gray-900">{{ __('Post today') }}</h2>
        <p class="mt-1 text-sm text-gray-600">{{ __('Download the media, copy the text into the app, post, then mark it published.') }}</p>
    </x-slot>

    <script>
        // the clipboard API needs https; elsewhere (and on older phones) a hidden textarea and execCommand do it
        window.copyText = function (text) {
            if (navigator.clipboard && window.isSecureContext) {
                return navigator.clipboard.writeText(text);
            }
            return new Promise(function (resolve, reject) {
                const area = document.createElement('textarea');
                area.value = text;
                area.setAttribute('readonly', '');
                area.style.position = 'fixed';
                area.style.opacity = '0';
                document.body.appendChild(area);
                area.select();
                area.setSelectionRange(0, text.length);
                const ok = document.execCommand('copy');
                area.remove();
                ok ? resolve() : reject();
            });
        };
    </script>

    <div class="py-6">
        <div class="mx-auto max-w-7xl space-y-4 px-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-center gap-2 rounded-xl border border-gray-300 bg-white p-2 text-sm">
                <span class="inline-flex rounded-lg bg-gray-100 p-1">
                    @foreach (['today' => __('Today'), 'tomorrow' => __('Tomorrow')] as $one => $label)
                        <a href="{{ route('today', $keep(['day' => $one === 'tomorrow' ? 'tomorrow' : null])) }}" @if (($one === 'tomorrow') === $tomorrow) aria-current="page" @endif
                           class="inline-flex min-h-10 items-center rounded-md px-4 {{ ($one === 'tomorrow') === $tomorrow ? 'bg-white font-semibold text-gray-900 shadow-sm' : 'font-medium text-gray-600 hover:text-gray-900' }}">{{ $label }}</a>
                    @endforeach
                </span>
                <span class="px-1 font-medium text-gray-900">{{ $day->translatedFormat('l d-m-Y') }}</span>
                @if ($clients->count() > 1)
                    <form method="GET" action="{{ route('today') }}" class="w-full sm:ml-auto sm:w-auto">
                        @if ($tomorrow) <input type="hidden" name="day" value="tomorrow"> @endif
                        <select name="client" onchange="this.form.submit()" aria-label="{{ __('Client') }}" class="block min-h-10 w-full rounded-md text-sm sm:w-56">
                            <option value="">{{ __('All clients') }}</option>
                            @foreach ($clients as $one)
                                <option value="{{ $one->slug }}" @selected($selected?->is($one))>{{ $one->name }}</option>
                            @endforeach
                        </select>
                    </form>
                @endif
            </div>

            @if (session('done'))
                <p class="text-sm text-green-600" role="status">{{ __('Marked published.') }}</p>
            @endif
            @include('posts._repeat-drafted')
            @error('state') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

            @forelse ($todo as $row)
                @include('today._post', ['post' => $row['post'], 'platforms' => $row['platforms'], 'return' => route('today', $keep())])
            @empty
                <x-empty-state class="bg-white" :action="__('Open the calendar')" :href="route('calendar', array_filter(['date' => $day->toDateString(), 'client' => $selected?->slug]))">
                    {{ $tomorrow ? __('Nothing to post tomorrow.') : ($done->isNotEmpty() ? __('Nothing left to post today.') : __('Nothing to post today.')) }}
                </x-empty-state>
            @endforelse

            @if ($done->isNotEmpty())
                <x-section :title="$tomorrow ? __('Already posted') : __('Done today')" flush data-done>
                    <ul class="divide-y divide-gray-200 text-sm">
                        @foreach ($done as $post)
                            <li class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 sm:px-6 {{ (int) session('done') === $post->id ? 'bg-green-50' : '' }}" data-post="{{ $post->id }}">
                                <span class="shrink-0 tabular-nums text-gray-600">{{ $post->scheduled_at->isSameDay($day) ? $post->scheduled_at->format('H:i') : $post->scheduled_at->translatedFormat('D d-m-Y H:i') }}</span>
                                <span class="font-medium text-gray-900">{{ $post->client->name }}</span>
                                <x-state-badge :state="$post->state" />
                                @if ($post->published_at)
                                    <span class="text-gray-600">{{ __('marked at :time', ['time' => $post->published_at->format('H:i')]) }}</span>
                                @endif
                                <a href="{{ route('posts.edit', $post) }}" class="w-full truncate font-mono text-xs text-gray-700 hover:text-brand-600 sm:ml-auto sm:w-auto">{{ $post->name }}</a>
                            </li>
                        @endforeach
                    </ul>
                </x-section>
            @endif
        </div>
    </div>
</x-app-layout>
