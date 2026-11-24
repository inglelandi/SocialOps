{{-- The post as the client will see it on the approval link, one card per ticked platform, following the form as you type. --}}
@php($post->exists || $post->setRelation('client', $client))
<div class="space-y-4">
    <h3 class="text-sm font-medium text-gray-700">{{ __('Preview') }} <span class="font-normal text-gray-500">{{ __('(as on the approval link; placeholders filled from the profile)') }}</span></h3>
    <p data-preview-empty class="text-sm text-gray-500 {{ old('platforms', $post->platforms ?? []) ? 'hidden' : '' }}">{{ __('Tick a platform above to see the post as the client will.') }}</p>
    @foreach (\App\Models\Post::PLATFORMS as $platform)
        <div data-preview-platform="{{ $platform }}" class="{{ in_array($platform, old('platforms', $post->platforms ?? [])) ? '' : 'hidden' }}">
            @include('portal._preview', ['post' => $post, 'platform' => $platform, 'client' => $client, 'assetUrl' => fn ($a) => $a->kind === 'video' ? route('assets.download', $a) : route('assets.thumb', $a), 'live' => true])
        </div>
    @endforeach
</div>
{{-- the carousel's arrows for the script below, drawn once by the icon component --}}
<template data-carousel-icon="prev"><x-icon name="chevron-left" class="h-4 w-4" /></template>
<template data-carousel-icon="next"><x-icon name="chevron-right" class="h-4 w-4" /></template>
<script>
    (() => {
        const form = document.getElementById('post-form');
        if (! form) return;
        const fields = @json((object) ($client->profile['fields'] ?? []));
        const kinds = @json((object) $client->assets->pluck('kind', 'id')->all());
        const urls = { image: @json(route('assets.thumb', ['asset' => 0])), video: @json(route('assets.download', ['asset' => 0])) };
        // a placeholder name may be Greek (τηλέφωνο), as Client::substitute() and the counter read it
        const fill = s => s.replace(/\{\{\s*([\p{L}\p{N}_]+)\s*\}\}/gu, (m, k) => fields[k.toLowerCase()] ?? m);
        const tags = v => [...new Set(v.trim().split(/\s+/).filter(Boolean).map(t => '#' + t.replace(/^#/, '')))].join(' ');
        const icons = Object.fromEntries([...document.querySelectorAll('template[data-carousel-icon]')].map(t => [t.dataset.carouselIcon, t.innerHTML]));
        const item = id => kinds[id] === 'video' ? `<video src="${urls.video.replace('/0/', `/${id}/`)}" controls playsinline class="h-full w-full object-cover"></video>`
            : `<img src="${urls.image.replace('/0/', `/${id}/`)}" alt="" class="h-full w-full object-cover">`;
        const media = ids => ! ids.length ? '<div class="flex h-full w-full items-center justify-center"><span class="text-xs text-gray-600">{{ __('Photo or video to follow') }}</span></div>'
            : ids.length === 1 ? item(ids[0])
            : `<div class="relative h-full w-full" x-data="{ i: 0, n: ${ids.length} }" data-carousel>`
                + `<div class="flex h-full w-full snap-x snap-mandatory overflow-x-auto scroll-smooth [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" x-ref="strip" x-on:scroll.passive="i = Math.round($el.scrollLeft / $el.clientWidth)">${ids.map(id => `<div class="h-full w-full flex-none snap-center">${item(id)}</div>`).join('')}</div>`
                + `<span class="absolute right-2 top-2 rounded-full bg-black/60 px-2 py-0.5 text-xs font-medium text-white" x-text="(i + 1) + '/' + n">1/${ids.length}</span>`
                + `<button type="button" class="absolute left-2 top-1/2 -translate-y-1/2 rounded-full bg-white/90 p-1 text-gray-900 shadow" x-show="i > 0" x-on:click="$refs.strip.scrollBy({ left: -$refs.strip.clientWidth, behavior: 'smooth' })" aria-label="{{ __('Previous') }}">${icons.prev}</button>`
                + `<button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 rounded-full bg-white/90 p-1 text-gray-900 shadow" x-show="i < n - 1" x-on:click="$refs.strip.scrollBy({ left: $refs.strip.clientWidth, behavior: 'smooth' })" aria-label="{{ __('Next') }}">${icons.next}</button>`
                + `<div class="absolute bottom-2 left-0 right-0 flex justify-center gap-1">${ids.map((_, j) => `<span class="h-1.5 w-1.5 rounded-full bg-white shadow" :class="i === ${j} ? 'opacity-100' : 'opacity-50'"></span>`).join('')}</div></div>`;
        const update = () => {
            const on = [...form.querySelectorAll('input[name="platforms[]"]:checked')].map(b => b.value);
            document.querySelectorAll('[data-preview-platform]').forEach(el => el.classList.toggle('hidden', ! on.includes(el.dataset.previewPlatform)));
            document.querySelector('[data-preview-empty]').classList.toggle('hidden', on.length > 0);
            form.querySelectorAll('[name^="variants["]').forEach(f => {
                const m = f.name.match(/^variants\[(\w+)\]\[(\w+)\]\[(caption|hashtags)\]$/);
                const el = m && document.querySelector(`[data-preview="${m[1]}.${m[2]}.${m[3]}"]`);
                if (! el) return;
                el.textContent = m[3] === 'hashtags' ? tags(f.value) : fill(f.value);
                el.closest(m[3] === 'hashtags' ? '[data-preview-tags]' : '[data-preview-line]')?.classList.toggle('hidden', ! f.value.trim());
            });
            // every ticked file, in the form's order (the carousel's order): the same markup as portal/_media, which Alpine picks up as it lands
            const ids = [...form.querySelectorAll('input[name="assets[]"]:checked')].map(b => b.value), current = ids.map(id => `${kinds[id]}:${id}`).join(',');
            document.querySelectorAll('[data-preview-media]').forEach(box => {
                if (box.dataset.current === current) return;
                box.dataset.current = current;
                box.innerHTML = media(ids);
            });
        };
        form.addEventListener('input', update);
        form.addEventListener('change', update);
        update();
    })();
</script>
