@csrf
@php($languages = array_merge($client->profile['languages'], $client->profile['extra_languages'] ?? []))
@php($selected = old('platforms', $post->platforms ?? []))
@php($control = 'mt-1 block w-full rounded-md border-gray-300 focus:border-brand-500 focus:ring-brand-500')
<x-section :title="__('Post')" :description="__('Where it goes, what it is and when it is due.')">
<div class="grid gap-6 py-5 md:grid-cols-2">
    <div>
        <x-input-label :value="__('Platforms')" />
        <div class="mt-2 flex flex-wrap gap-4 text-sm">
            @foreach (\App\Models\Post::PLATFORMS as $platform)
                <label class="flex items-center gap-1 {{ empty($client->profile['accounts'][$platform]) ? 'text-gray-500' : '' }}">
                    <input type="checkbox" name="platforms[]" value="{{ $platform }}" class="rounded border-gray-300" @checked(in_array($platform, $selected)) />
                    {{ str_replace('_', ' ', $platform) }}
                </label>
            @endforeach
        </div>
        <x-input-error class="mt-2" :messages="$errors->get('platforms')" />
    </div>
    <div>
        <x-input-label for="format" :value="__('Format')" />
        <select id="format" name="format" class="{{ $control }}">
            @foreach (\App\Models\Post::FORMATS as $format)
                <option value="{{ $format }}" @selected(old('format', $post->format) === $format)>{{ __(ucfirst(str_replace('_', ' ', $format))) }}</option>
            @endforeach
        </select>
        <x-input-error class="mt-2" :messages="$errors->get('format')" />
    </div>
    <div>
        <x-input-label for="pillar" :value="__('Pillar')" />
        <select id="pillar" name="pillar" class="{{ $control }}">
            <option value="">—</option>
            @foreach (array_keys($client->profile['pillars']) as $pillar)
                <option value="{{ $pillar }}" @selected(old('pillar', $post->pillar) === $pillar)>{{ str_replace('_', ' ', $pillar) }}</option>
            @endforeach
        </select>
        <x-input-error class="mt-2" :messages="$errors->get('pillar')" />
    </div>
    <div>
        <x-input-label for="scheduled_at" :value="__('Scheduled for')" />
        <x-text-input id="scheduled_at" name="scheduled_at" type="datetime-local" class="mt-1 block w-full" :value="old('scheduled_at', $post->scheduled_at?->format('Y-m-d\TH:i'))" />
        <x-input-error class="mt-2" :messages="$errors->get('scheduled_at')" />
        <div class="mt-1 flex flex-wrap gap-3 text-xs">
            @if ($slot = $client->nextFreeSlot())
                <button type="button" class="font-medium text-gray-900 hover:text-brand-600" onclick="document.getElementById('scheduled_at').value = '{{ $slot->format('Y-m-d\TH:i') }}'">{{ __('Next free slot') }}: {{ $slot->translatedFormat('D d-m-Y H:i') }}</button>
            @endif
            @if ($best = \App\Planning\BestTimes::for($client))
                @php($at = \App\Planning\BestTimes::next($best[0]))
                <button type="button" class="font-medium text-gray-900 hover:text-brand-600" onclick="document.getElementById('scheduled_at').value = '{{ $at->format('Y-m-d\TH:i') }}'">{{ __('Best time from our numbers') }}: {{ $at->translatedFormat('D d-m-Y H:i') }}</button>
            @endif
        </div>
    </div>
    <div>
        {{-- Post::spawnRepeat(): once published, the next copy is drafted and goes through approval again; changing only this keeps an approved post approved --}}
        @php($repeat = old('settings.repeat', $post->settings['repeat'] ?? ''))
        <x-input-label for="repeat" :value="__('Repeat')" />
        <select id="repeat" name="settings[repeat]" class="{{ $control }}">
            <option value="">{{ __('Does not repeat') }}</option>
            <option value="weekly" @selected($repeat === 'weekly')>{{ __('Every week') }}</option>
            <option value="monthly" @selected($repeat === 'monthly')>{{ __('Every month') }}</option>
        </select>
        <p class="mt-1 text-xs text-gray-600">{{ __('Once it is published, a copy is drafted for a week or a month later and goes through approval again.') }}</p>
        <x-input-error class="mt-2" :messages="$errors->get('settings.repeat')" />
    </div>
</div>
<div class="py-5">
    <x-input-label for="brief" :value="__('What the post is about')" />
    <textarea id="brief" name="brief" rows="3" class="{{ $control }}">{{ old('brief', $post->brief) }}</textarea>
    <x-input-error class="mt-2" :messages="$errors->get('brief')" />
</div>

@if ($client->assets->isNotEmpty())
    <div class="py-5">
        <x-input-label :value="__('Assets')" />
        @php($chosen = old('assets', $post->exists ? $post->assets->pluck('id')->map(fn ($id) => (string) $id)->all() : []))
        <div class="mt-2 grid gap-1 md:grid-cols-2 text-xs font-mono">
            @foreach ($client->assets as $asset)
                <label class="flex items-center gap-2"><input type="checkbox" name="assets[]" value="{{ $asset->id }}" class="rounded border-gray-300" @checked(in_array((string) $asset->id, $chosen)) />@if ($asset->kind === 'image')<img src="{{ route('assets.thumb', $asset) }}" alt="" loading="lazy" class="h-10 w-10 rounded object-cover">@endif {{ $asset->name }}</label>
            @endforeach
        </div>
        <x-input-error class="mt-2" :messages="$errors->get('assets')" />
    </div>
@endif
</x-section>

@if (config('features.publishing'))
<x-section data-for="instagram tiktok" :title="__('Platform settings')" flush>
<fieldset class="px-6 py-5 text-sm" data-for="instagram">
    <legend class="px-1 text-sm font-medium">Instagram</legend>
    <x-input-label for="ig-collab" :value="__('Collab invite: up to 3 Instagram usernames, feed, carousel and Reel only (they accept in the app, then the post shows on both profiles)')" />
    <x-text-input id="ig-collab" name="settings[instagram][collaborators]" type="text" class="mt-1 block w-full" placeholder="@hotel, @chef" :value="old('settings.instagram.collaborators', $post->settings['instagram']['collaborators'] ?? '')" />
    <x-input-error class="mt-1" :messages="$errors->get('settings.instagram.collaborators')" />
</fieldset>

@php($tiktok = $client->accounts->firstWhere('platform', 'tiktok'))
@php($tt = old('settings.tiktok', $post->settings['tiktok'] ?? []))
<fieldset class="px-6 py-5 text-sm" data-for="tiktok">
    <legend class="px-1 text-sm font-medium">TikTok {{ $tiktok ? __('(posting as :name)', ['name' => $tiktok->name]) : '' }}</legend>
    <div class="grid gap-4 md:grid-cols-3">
        <div>
            <x-input-label for="tt-privacy" :value="__('Who can see it')" />
            <select id="tt-privacy" name="settings[tiktok][privacy_level]" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                @foreach ($tiktok->meta['privacy_level_options'] ?? \App\Publishing\TikTokPublisher::PRIVACY as $level)
                    <option value="{{ $level }}" @selected(($tt['privacy_level'] ?? 'PUBLIC_TO_EVERYONE') === $level)>{{ ucfirst(strtolower(str_replace('_', ' ', $level))) }}</option>
                @endforeach
            </select>
        </div>
        <div class="space-y-1">
            <input type="hidden" name="settings[tiktok][allow_comment]" value="0"><input type="hidden" name="settings[tiktok][allow_duet]" value="0"><input type="hidden" name="settings[tiktok][allow_stitch]" value="0">
            <label class="flex items-center gap-2"><input type="checkbox" name="settings[tiktok][allow_comment]" value="1" class="rounded border-gray-300" @checked($tt['allow_comment'] ?? true) /> {{ __('Allow comments') }}</label>
            <label class="flex items-center gap-2"><input type="checkbox" name="settings[tiktok][allow_duet]" value="1" class="rounded border-gray-300" @checked($tt['allow_duet'] ?? true) /> {{ __('Allow duet') }}</label>
            <label class="flex items-center gap-2"><input type="checkbox" name="settings[tiktok][allow_stitch]" value="1" class="rounded border-gray-300" @checked($tt['allow_stitch'] ?? true) /> {{ __('Allow stitch') }}</label>
        </div>
        <div>
            <x-input-label for="tt-commercial" :value="__('Commercial content')" />
            <select id="tt-commercial" name="settings[tiktok][commercial]" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                <option value="none" @selected(($tt['commercial'] ?? 'none') === 'none')>{{ __('None') }}</option>
                <option value="your_brand" @selected(($tt['commercial'] ?? '') === 'your_brand')>{{ __('Your brand (promotes the client\'s own business)') }}</option>
                <option value="branded_content" @selected(($tt['commercial'] ?? '') === 'branded_content')>{{ __('Branded content (paid partnership with a third party)') }}</option>
            </select>
        </div>
    </div>
    <label class="mt-3 flex items-start gap-2">
        <input type="hidden" name="settings[tiktok][consent]" value="0">
        <input type="checkbox" name="settings[tiktok][consent]" value="1" class="mt-1 rounded border-gray-300" @checked($tt['consent'] ?? false) />
        <span>{{ __('The creator agrees to TikTok\'s') }} <a href="https://www.tiktok.com/legal/page/global/music-usage-confirmation/en" target="_blank" class="underline">Music Usage Confirmation</a> {{ __('and, for branded content, the') }} <a href="https://www.tiktok.com/legal/page/global/bc-policy/en" target="_blank" class="underline">Branded Content Policy</a>. {{ __('The creator sees the preview in the approval link before it goes out.') }}</span>
    </label>
    <x-input-error class="mt-1" :messages="$errors->get('settings.tiktok.consent')" />
</fieldset>
</x-section>
@endif


<x-section data-for="instagram facebook" :title="__('First comment')" :description="__('Hashtags or a link that go under the post as its first comment.')">
<div class="py-5">
<textarea name="settings[first_comment]" rows="2" class="{{ $control }} text-sm">{{ old('settings.first_comment', $post->settings['first_comment'] ?? '') }}</textarea>
<x-input-error class="mt-1" :messages="$errors->get('settings.first_comment')" />
</div>
</x-section>

@php($design = old('settings.design', $post->settings['design'] ?? []))
<x-section :title="__('Design text')" :description="__('The words on the graphic or in the video: the headline, the slide texts or the Reel script.')">
<div class="grid gap-4 py-5 md:grid-cols-{{ count($languages) }}">
    @foreach ($languages as $lang)
        <div>
            <x-input-label :value="strtoupper($lang)" />
            <textarea name="settings[design][{{ $lang }}]" rows="4" class="{{ $control }} text-sm">{{ $design[$lang] ?? '' }}</textarea>
        </div>
    @endforeach
</div>
</x-section>

<x-section :title="__('Captions')" :description="__('Only the ticked platforms are kept. A person checks the Greek before the client sees it.')">
<div class="space-y-2 py-4">
<label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" id="sync-captions" class="rounded border-gray-300"> {{ __('Same text on every platform (untick to write each one separately)') }}</label>
@if (! config('features.claude_api'))
    <label class="flex items-center gap-2 text-sm text-gray-700"><input type="hidden" name="settings[ask_claude]" value="0"><input type="checkbox" name="settings[ask_claude]" value="1" class="rounded border-gray-300" @checked(old('settings.ask_claude', $post->settings['ask_claude'] ?? false))> {{ __('Ask Claude to write the captions') }}</label>
    @if ($post->exists)
        <p class="pl-6 text-xs text-gray-600">{{ __('Or ask in a chat with the Social Ops connector: "write the captions for post :id".', ['id' => $post->id]) }}</p>
    @endif
@endif
@if ($post->exists && config('features.claude_api'))
    <div class="mt-3 flex gap-2">
        <x-text-input id="instruction" type="text" class="block w-full text-sm" placeholder="{{ __('e.g. make it shorter and more playful, add the opening hours, mention the full moon party on Saturday') }}" onkeydown="if (event.key === 'Enter') { event.preventDefault(); document.getElementById('rewrite').click(); }" />
        <x-secondary-button type="button" id="rewrite" class="whitespace-nowrap" data-url="{{ route('posts.rewrite', $post) }}">{{ __('Change with Claude') }}</x-secondary-button>
    </div>
    <p class="mt-1 text-xs text-gray-600">{{ __('Rewrites the captions of every ticked platform as you say; nothing is saved until you press Save.') }}</p>
@endif
</div>
@foreach (\App\Models\Post::PLATFORMS as $platform)
    <fieldset class="py-5" data-for="{{ $platform }}" data-platform="{{ $platform }}" data-max="{{ \App\Http\Requests\PostRequest::CAPTION_MAX[$platform] }}" data-signature="{{ trim($client->profile['signatures'][$platform] ?? '') }}" data-fields="{{ json_encode((object) ($client->profile['fields'] ?? [])) }}">
        <legend class="float-left w-full pb-2 text-sm font-medium text-gray-900">{{ str_replace('_', ' ', $platform) }} <span class="count font-normal text-gray-500" title="{{ __('Characters of the published text: every language, then the hashtags') }}"></span>
            @if ($post->exists && config('features.claude_api'))
                <button type="button" class="shorten ml-2 text-xs font-medium text-gray-900 hover:text-brand-600" data-url="{{ route('posts.shorten', $post) }}">{{ __('Shorten with Claude') }}</button>
            @endif
        </legend>
        <div class="clear-both grid gap-4 md:grid-cols-{{ count($languages) }}">
            @foreach ($languages as $lang)
                @php($v = $variants->get("$platform.$lang"))
                <div>
                    <x-input-label :value="strtoupper($lang)" />
                    <textarea name="variants[{{ $platform }}][{{ $lang }}][caption]" data-lang="{{ $lang }}" rows="5" class="{{ $control }} text-sm">{{ old("variants.$platform.$lang.caption", $v?->caption) }}</textarea>
                    <x-input-error class="mt-1" :messages="$errors->get('variants.'.$platform.'.'.$lang.'.caption')" />
                    <x-text-input name="variants[{{ $platform }}][{{ $lang }}][hashtags]" type="text" class="mt-2 block w-full text-sm" placeholder="#chania #crete" :value="old('variants.'.$platform.'.'.$lang.'.hashtags', implode(' ', $v?->hashtags ?? []))" />
                    @if ($platform !== 'tiktok')
                        <x-text-input name="variants[{{ $platform }}][{{ $lang }}][alt_text]" type="text" class="mt-2 block w-full text-sm" placeholder="{{ __('Alt text') }}" :value="old('variants.'.$platform.'.'.$lang.'.alt_text', $v?->alt_text)" />
                    @endif
                </div>
            @endforeach
        </div>
    </fieldset>
@endforeach
</x-section>

<script>
    // Live count of the text that will be published per platform (languages joined by blank lines, the profile's signature, then the hashtags), and the Shorten button.
    // shortcut: JS counts UTF-16 units, so an emoji counts 2 where the server counts 1; the server's count decides at publish time
    document.querySelectorAll('fieldset[data-max]').forEach(set => {
        const max = +set.dataset.max, count = set.querySelector('.count'), fields = JSON.parse(set.dataset.fields || '{}');
        const fill = s => s.replace(/\{\{\s*([\p{L}\p{N}_]+)\s*\}\}/gu, (m, k) => fields[k.toLowerCase()] ?? m);
        const tags = () => [...new Set([...set.querySelectorAll('input[name$="[hashtags]"]')].flatMap(i => i.value.trim().split(/\s+/).filter(Boolean)).map(t => '#' + t.replace(/^#/, '')))].join(' ');
        const update = () => {
            const n = fill([...[...set.querySelectorAll('textarea')].map(t => t.value.trim()), set.dataset.signature, tags()].filter(Boolean).join('\n\n')).length;
            count.textContent = `${n} / ${max}`;
            count.classList.toggle('text-red-600', n > max);
        };
        set.addEventListener('input', update);
        update();
        set.querySelector('.shorten')?.addEventListener('click', async ({ target: button }) => {
            button.disabled = true;
            const captions = {};
            set.querySelectorAll('textarea').forEach(t => captions[t.dataset.lang] = t.value);
            const res = await fetch(button.dataset.url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                body: JSON.stringify({ platform: set.dataset.platform, captions, hashtags: tags() }),
            });
            const json = await res.json();
            if (res.ok) {
                set.querySelectorAll('textarea').forEach(t => { if (json[t.dataset.lang] !== undefined) t.value = json[t.dataset.lang]; });
                update();
            } else {
                alert(json.error || json.message);
            }
            button.disabled = false;
        });
    });

    // Only the ticked platforms' settings and captions are shown; with "Same text on every platform" on, a caption typed once is copied to the other ticked platforms.
    const boxes = [...document.querySelectorAll('input[name="platforms[]"]')], sync = document.getElementById('sync-captions');
    const ticked = () => boxes.filter(b => b.checked).map(b => b.value);
    const sets = () => [...document.querySelectorAll('fieldset[data-max]')].filter(s => !s.classList.contains('hidden'));
    const showSections = () => { const on = ticked(); document.querySelectorAll('[data-for]').forEach(el => el.classList.toggle('hidden', !el.dataset.for.split(' ').some(p => on.includes(p)))); };
    const copyFrom = field => {
        const suffix = field.name.replace(/^variants\[[^\]]+\]/, '');
        sets().forEach(s => {
            const target = s.contains(field) ? null : s.querySelector(`[name$="${suffix}"]`);
            if (target && target.value !== field.value) { target.value = field.value; target.dispatchEvent(new Event('input', { bubbles: true })); }
        });
    };
    // the text is copied from a platform that already had captions, never from the one just ticked (its boxes are empty and would wipe the others)
    const withText = list => list.find(s => [...s.querySelectorAll('textarea')].some(t => t.value.trim()));
    const copyAll = source => source?.querySelectorAll('textarea, input[type=text]').forEach(copyFrom);
    boxes.forEach(b => b.addEventListener('change', () => { const shown = sets(); showSections(); if (sync.checked) copyAll(withText(shown) ?? withText(sets())); }));
    document.querySelectorAll('fieldset[data-max]').forEach(s => s.addEventListener('input', e => { if (sync.checked && e.isTrusted) copyFrom(e.target); }));
    sync.addEventListener('change', () => sync.checked && copyAll(withText(sets())));
    showSections();

    // The rewrite box: the instruction and the captions of the ticked platforms go to the server, the rewritten captions come back into the textareas, unsaved.
    document.getElementById('rewrite')?.addEventListener('click', async ({ target: button }) => {
        const instruction = document.getElementById('instruction').value.trim();
        if (!instruction) return document.getElementById('instruction').focus();
        button.disabled = true;
        const captions = {};
        sets().forEach(s => { captions[s.dataset.platform] = {}; s.querySelectorAll('textarea').forEach(t => captions[s.dataset.platform][t.dataset.lang] = t.value); });
        const res = await fetch(button.dataset.url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            body: JSON.stringify({ instruction, brief: document.getElementById('brief').value, captions }),
        });
        const json = await res.json();
        if (res.ok) {
            sets().forEach(s => s.querySelectorAll('textarea').forEach(t => { const v = json[s.dataset.platform]?.[t.dataset.lang]; if (v !== undefined) { t.value = v; t.dispatchEvent(new Event('input', { bubbles: true })); } }));
        } else {
            alert(json.error || json.message);
        }
        button.disabled = false;
    });
    // On by default when the ticked platforms already share the same text (a new post, or one never customised).
    const texts = sets().map(s => [...s.querySelectorAll('textarea')].map(t => t.value.trim()).join('\u0001'));
    sync.checked = texts.every(t => t === texts[0]);
</script>

<div class="sticky bottom-0 flex items-center gap-4 rounded-xl border border-gray-300 bg-white/95 px-6 py-3 backdrop-blur">
    <x-primary-button>{{ __('Save') }}</x-primary-button>
    <a href="{{ route('calendar', ['client' => $client->slug]) }}" class="text-sm text-gray-600 hover:underline">{{ __('Back to calendar') }}</a>
</div>
