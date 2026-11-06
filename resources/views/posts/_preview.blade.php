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
            const id = form.querySelector('input[name="assets[]"]:checked')?.value, current = id ? `${kinds[id]}:${id}` : '';
            document.querySelectorAll('[data-preview-media]').forEach(box => {
                if (box.dataset.current === current) return;
                box.dataset.current = current;
                box.innerHTML = ! id ? '<span class="text-xs text-gray-600">{{ __('Photo or video to follow') }}</span>'
                    : kinds[id] === 'video' ? `<video src="${urls.video.replace('/0/', `/${id}/`)}" controls playsinline class="h-full w-full object-cover"></video>`
                    : `<img src="${urls.image.replace('/0/', `/${id}/`)}" alt="" class="h-full w-full object-cover">`;
            });
        };
        form.addEventListener('input', update);
        form.addEventListener('change', update);
        update();
    })();
</script>
