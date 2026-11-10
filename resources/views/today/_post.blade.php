{{-- One post to post by hand: when and for whom, the media to download, per platform the text to paste (TodayController::texts()), then Mark published. --}}
@php($labels = ['instagram' => 'Instagram', 'facebook' => 'Facebook', 'tiktok' => 'TikTok', 'linkedin' => 'LinkedIn'])
@php($late = $post->scheduled_at->lt(today()))
@php($label = 'mb-1 text-xs font-semibold uppercase tracking-wide text-gray-600')
@php($box = 'whitespace-pre-wrap break-words rounded-md bg-gray-50 p-3 text-[15px] leading-relaxed text-gray-900')
@php($download = 'flex min-h-11 items-center justify-center gap-1.5 rounded-lg border border-gray-400 bg-white px-3 text-sm font-medium text-gray-900 hover:bg-gray-50')
<article id="post-{{ $post->id }}" data-post="{{ $post->id }}" class="rounded-xl border border-gray-300 bg-white">
    <header class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-gray-300 px-4 py-3 sm:px-6">
        <span class="text-xl font-semibold tabular-nums text-gray-900">{{ $post->scheduled_at->format('H:i') }}</span>
        <span class="text-base font-semibold text-gray-900">{{ $post->client->name }}</span>
        <x-state-badge :state="$post->state" />
        @if ($late)
            <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800">{{ __('Late: due :date', ['date' => $post->scheduled_at->translatedFormat('D d-m-Y')]) }}</span>
        @elseif ($post->scheduled_at->isPast())
            <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800">{{ __('Past its time') }}</span>
        @endif
        <p class="w-full text-sm text-gray-600">
            {{ collect($post->platforms)->map(fn ($p) => $labels[$p] ?? $p)->join(', ') }} · {{ __(ucfirst(str_replace('_', ' ', $post->format))) }}
            · <a href="{{ route('posts.edit', $post) }}" class="break-all font-mono text-xs text-gray-700 underline decoration-gray-400 underline-offset-2 hover:text-brand-600">{{ $post->name }}</a>
        </p>
    </header>

    <div class="space-y-5 px-4 py-4 sm:px-6 lg:grid lg:grid-cols-[17rem_1fr] lg:items-start lg:gap-6 lg:space-y-0">
        <div class="space-y-3">
            <p class="{{ $label }}">{{ __('Media') }}</p>
            @if ($post->assets->isEmpty())
                <p class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-600">{{ __('No media on this post.') }}</p>
            @else
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-2">
                    @foreach ($post->assets as $asset)
                        <div>
                            <a href="{{ route('assets.download', $asset) }}" class="block aspect-square overflow-hidden rounded-lg bg-gray-100" title="{{ $asset->name }}">
                                @if ($asset->kind === 'image')
                                    <img src="{{ route('assets.thumb', $asset) }}" alt="{{ $asset->name }}" loading="lazy" class="h-full w-full object-cover">
                                @else
                                    <span class="flex h-full w-full items-center justify-center text-sm text-gray-600">&#9654; {{ __('video') }}</span>
                                @endif
                            </a>
                            <a href="{{ route('assets.download', $asset) }}" class="{{ $download }} mt-1.5"><x-icon name="arrow-down-tray" class="h-4 w-4" />{{ $loop->count > 1 ? __('Download :n', ['n' => $loop->iteration]) : __('Download') }}</a>
                        </div>
                    @endforeach
                </div>
            @endif
            @if ($post->assets->firstWhere('kind', 'image'))
                @foreach (array_keys(array_filter($post->settings['design'] ?? [])) as $lang)
                    <a href="{{ route('posts.image', [$post, $lang]) }}" class="{{ $download }}" title="{{ __('The first photo with the design text burned in, ready to post') }}">{{ __('Image with text :lang', ['lang' => strtoupper($lang)]) }}</a>
                @endforeach
            @endif
            <a href="{{ route('posts.export', $post) }}" class="{{ $download }}" title="{{ __('Media files plus a captions.txt, to post by hand or design in Canva') }}"><x-icon name="archive-box" class="h-4 w-4" />{{ __('Everything as a ZIP') }}</a>
        </div>

        <div class="min-w-0 space-y-4">
            @foreach ($platforms as $platform => $t)
                <section class="rounded-lg border border-gray-300" data-platform="{{ $platform }}">
                    <header class="flex items-center justify-between gap-2 border-b border-gray-300 px-3 py-2">
                        <h3 class="font-semibold text-gray-900">{{ $labels[$platform] ?? $platform }}</h3>
                        <span class="text-xs tabular-nums {{ mb_strlen($t['text']) > $t['max'] ? 'font-semibold text-red-600' : 'text-gray-600' }}" title="{{ __('Characters, with every language, the signature and the hashtags') }}">{{ mb_strlen($t['text']) }} / {{ $t['max'] }}</span>
                    </header>
                    <div class="space-y-4 p-3">
                        @if ($t['problem'])
                            <p class="text-sm font-medium text-red-600">{{ $t['problem'] }}</p>
                        @endif
                        @if ($t['text'] !== '')
                            <div>
                                <p class="{{ $label }}">{{ __('Caption') }}</p>
                                <div class="{{ $box }}">{{ $t['text'] }}</div>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <x-copy-button :text="$t['text']" main class="w-full sm:w-auto">{{ __('Copy caption') }}</x-copy-button>
                                    @foreach ($t['languages'] as $lang => $text)
                                        <x-copy-button :text="$text" class="flex-1 sm:flex-none" title="{{ __('Only this language, with the signature and the hashtags') }}">{{ __('Copy :lang only', ['lang' => strtoupper($lang)]) }}</x-copy-button>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        @if ($t['first_comment'] !== '')
                            <div>
                                <p class="{{ $label }}">{{ __('First comment') }}</p>
                                <div class="{{ $box }}">{{ $t['first_comment'] }}</div>
                                <x-copy-button :text="$t['first_comment']" class="mt-2 w-full sm:w-auto">{{ __('Copy first comment') }}</x-copy-button>
                            </div>
                        @endif
                        @if ($t['alt'])
                            <div>
                                <p class="{{ $label }}">{{ __('Alt text') }}</p>
                                <div class="{{ $box }}">{{ $t['alt'] }}</div>
                                <x-copy-button :text="$t['alt']" class="mt-2 w-full sm:w-auto">{{ __('Copy alt text') }}</x-copy-button>
                            </div>
                        @endif
                        @if ($platform === 'instagram' && $post->collaborators())
                            <p class="text-sm text-gray-900"><span class="font-semibold">{{ __('Invite as collaborators') }}:</span> {{ collect($post->collaborators())->map(fn ($h) => '@'.$h)->join(' ') }}</p>
                        @endif
                    </div>
                </section>
            @endforeach

            @if (! empty($post->settings['products']))
                <section class="rounded-lg border border-gray-300 p-3" data-products>
                    <p class="{{ $label }}">{{ __('Product links') }}</p>
                    <ul class="space-y-2">
                        @foreach ($post->settings['products'] as $product)
                            <li class="flex flex-wrap items-center gap-2"><a href="{{ $product['url'] }}" target="_blank" rel="noopener" class="min-w-0 flex-1 break-words text-sm font-medium text-gray-900 hover:text-brand-600">{{ $product['name'] }}</a><x-copy-button :text="$product['url']">{{ __('Copy link') }}</x-copy-button></li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($post->brief || array_filter($post->settings['design'] ?? []))
                <details class="group rounded-lg border border-gray-300 text-sm">
                    <summary class="flex min-h-11 cursor-pointer list-none items-center [&::-webkit-details-marker]:hidden justify-between px-3 font-medium text-gray-900"><span class="inline-flex items-center gap-1.5"><x-icon name="document-text" class="h-4 w-4 text-gray-600" />{{ __('Brief and design text') }}</span> <span aria-hidden="true" class="text-gray-600 transition group-open:rotate-180">&#9662;</span></summary>
                    <div class="space-y-3 border-t border-gray-300 p-3">
                        @if ($post->brief)
                            <p class="whitespace-pre-line text-gray-900">{{ $post->brief }}</p>
                        @endif
                        @foreach (array_filter($post->settings['design'] ?? []) as $lang => $design)
                            <div>
                                <p class="{{ $label }}">{{ __('Design text') }} {{ strtoupper($lang) }}</p>
                                <div class="{{ $box }}">{{ $design }}</div>
                            </div>
                        @endforeach
                    </div>
                </details>
            @endif

            <form method="POST" action="{{ route('posts.transition', $post) }}" onsubmit="return confirm(@js(__('Did you post it in the app? This marks it published.')))">
                @csrf
                <input type="hidden" name="state" value="published">
                <input type="hidden" name="return" value="{{ $return }}">
                <button type="submit" class="flex min-h-12 w-full items-center justify-center gap-2 rounded-lg bg-brand-500 px-6 text-base font-semibold text-white hover:bg-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 sm:w-auto"><x-icon name="check-circle" class="h-5 w-5" />{{ __('Mark published') }}</button>
            </form>
        </div>
    </div>
</article>
