{{-- A lookalike of the post on one network: enough for the client to judge image, text and hashtags. Needs $post, $platform, $client and $assetUrl (closure); $live (the staff form) renders a line per language so the form can fill it as you type. --}}
@php($media = $post->assets->values())
@php($vertical = in_array($post->format, ['reel', 'story', 'tiktok_video', 'tiktok_photos']))
@php($labels = ['instagram' => 'Instagram', 'facebook' => 'Facebook', 'tiktok' => 'TikTok', 'linkedin' => 'LinkedIn'])
@php($variants = $post->variants->where('platform', $platform)->keyBy('lang'))
@php($langs = ($live ?? false) ? array_merge($client->profile['languages'], $client->profile['extra_languages'] ?? []) : $variants->keys()->all())
@php($textFirst = $platform === 'facebook' || $platform === 'linkedin')
@php($hashtags = fn ($v) => collect($v?->hashtags ?? [])->map(fn ($h) => '#'.ltrim($h, '#'))->join(' '))
<div class="rounded-lg border border-gray-300 overflow-hidden text-sm {{ $vertical ? 'bg-black text-white' : 'bg-white' }}">
    <div class="flex items-center gap-2 px-3 py-2">
        <span class="h-8 w-8 rounded-full bg-gray-300 flex items-center justify-center text-xs font-bold text-gray-700">{{ mb_substr($client->name, 0, 1) }}</span>
        <span class="font-semibold">{{ $client->name }}</span>
        @if ($platform === 'instagram' && $post->collaborators())
            <span class="text-xs opacity-60">{{ __('with') }} {{ collect($post->collaborators())->map(fn ($h) => "@$h")->join(', ') }}</span>
        @endif
        <span class="ml-auto text-xs opacity-60">{{ $labels[$platform] }}</span>
    </div>
    @if ($textFirst)
        @foreach ($langs as $lang)
            <div class="px-3 pb-2 whitespace-pre-line {{ trim($variants[$lang]->caption ?? '') === '' ? 'hidden' : '' }}" data-preview-line><span class="text-xs uppercase opacity-60 mr-1">{{ $lang }}</span><span data-preview="{{ $platform }}.{{ $lang }}.caption">{{ $client->substitute($variants[$lang]->caption ?? '') }}</span></div>
        @endforeach
        @if ($post->signatureFor($platform))
            <div class="px-3 pb-2 whitespace-pre-line opacity-80">{{ $post->signatureFor($platform) }}</div>
        @endif
    @endif
    <div class="relative bg-gray-100 {{ $vertical ? 'aspect-[9/16]' : 'aspect-square' }} overflow-hidden" data-preview-media data-current="{{ $media->map(fn ($a) => $a->kind.':'.$a->id)->join(',') }}">
        @include('portal._media', ['media' => $media, 'assetUrl' => $assetUrl])
    </div>
    @foreach ($langs as $lang)
        @php($v = $variants[$lang] ?? null)
        @if ($textFirst)
            <div class="px-3 pb-2 text-blue-800 {{ empty($v?->hashtags) ? 'hidden' : '' }}" data-preview-tags><span data-preview="{{ $platform }}.{{ $lang }}.hashtags">{{ $hashtags($v) }}</span></div>
        @else
            <div class="px-3 py-2 whitespace-pre-line {{ trim($v?->caption ?? '') === '' ? 'hidden' : '' }}" data-preview-line><span class="text-xs uppercase opacity-60 mr-1">{{ $lang }}</span><span class="font-semibold mr-1">{{ $client->name }}</span><span data-preview="{{ $platform }}.{{ $lang }}.caption">{{ $client->substitute($v?->caption ?? '') }}</span>
                @if ($loop->last && $post->signatureFor($platform)) <span class="block opacity-80">{{ $post->signatureFor($platform) }}</span> @endif
                <span class="{{ $vertical ? 'text-blue-300' : 'text-blue-800' }}" data-preview="{{ $platform }}.{{ $lang }}.hashtags">{{ $hashtags($v) }}</span>
            </div>
        @endif
    @endforeach
</div>
