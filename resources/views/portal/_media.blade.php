{{-- The media of a post in a preview card: nothing yet, one photo or video, or every slide of a carousel to swipe or page through, with the count and dots as in the apps (Panos, 2026-11-19: "on posts that are carousels can you also show it on preview?"). Needs $media (the post's assets in order) and $assetUrl; the staff form's script (posts/_preview) draws the same markup as the ticks change. --}}
@if ($media->isEmpty())
    <div class="flex h-full w-full items-center justify-center"><span class="text-xs text-gray-600">{{ __('Photo or video to follow') }}</span></div>
@elseif ($media->count() === 1)
    @if ($media->first()->kind === 'video')
        <video src="{{ $assetUrl($media->first()) }}" controls playsinline class="h-full w-full object-cover"></video>
    @else
        <img src="{{ $assetUrl($media->first()) }}" alt="" class="h-full w-full object-cover" />
    @endif
@else
    <div class="relative h-full w-full" x-data="{ i: 0, n: {{ $media->count() }} }" data-carousel>
        <div class="flex h-full w-full snap-x snap-mandatory overflow-x-auto scroll-smooth [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" x-ref="strip" x-on:scroll.passive="i = Math.round($el.scrollLeft / $el.clientWidth)">
            @foreach ($media as $a)
                <div class="h-full w-full flex-none snap-center">@if ($a->kind === 'video')<video src="{{ $assetUrl($a) }}" controls playsinline class="h-full w-full object-cover"></video>@else<img src="{{ $assetUrl($a) }}" alt="" class="h-full w-full object-cover" />@endif</div>
            @endforeach
        </div>
        <span class="absolute right-2 top-2 rounded-full bg-black/60 px-2 py-0.5 text-xs font-medium text-white" x-text="(i + 1) + '/' + n">1/{{ $media->count() }}</span>
        <button type="button" class="absolute left-2 top-1/2 -translate-y-1/2 rounded-full bg-white/90 p-1 text-gray-900 shadow" x-show="i > 0" x-on:click="$refs.strip.scrollBy({ left: -$refs.strip.clientWidth, behavior: 'smooth' })" aria-label="{{ __('Previous') }}"><x-icon name="chevron-left" class="h-4 w-4" /></button>
        <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 rounded-full bg-white/90 p-1 text-gray-900 shadow" x-show="i < n - 1" x-on:click="$refs.strip.scrollBy({ left: $refs.strip.clientWidth, behavior: 'smooth' })" aria-label="{{ __('Next') }}"><x-icon name="chevron-right" class="h-4 w-4" /></button>
        <div class="absolute bottom-2 left-0 right-0 flex justify-center gap-1">
            @foreach ($media as $a)<span class="h-1.5 w-1.5 rounded-full bg-white shadow" :class="i === {{ $loop->index }} ? 'opacity-100' : 'opacity-50'"></span>@endforeach
        </div>
    </div>
@endif
