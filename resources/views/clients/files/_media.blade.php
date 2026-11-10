{{-- Photos and videos: the team's upload, then the files with their filters; a click opens the preview --}}
<div x-show="tab === 'media'" x-cloak class="space-y-4">
    {{-- Add files: the team's own upload; files dropped anywhere on the page land here --}}
    <x-section icon="cloud-arrow-up" :title="__('Add files')" :description="__('Photos and videos get the agency name and land in the files below.')">
        <form id="upload-form" method="POST" action="{{ route('assets.store', $client) }}" enctype="multipart/form-data" class="space-y-4 py-5"
              x-data="assetUploader(@js([
                  'maxBytes' => \App\Http\Requests\InboxUploadRequest::MAX_KB * 1024,
                  'text' => [
                      'waiting' => __('Waiting'),
                      'uploading' => __('Uploading… :percent%'),
                      'added' => __('Added as :name'),
                      'tooBig' => \App\Http\Requests\InboxUploadRequest::sizeMessage(),
                      'type' => \App\Http\Requests\InboxUploadRequest::typeMessage(),
                      'expired' => __('The page expired. Reload it and try again.'),
                      'error' => __('Could not be saved, try again.'),
                      'offline' => __('No connection. Try again.'),
                      'button' => __('Upload'),
                      'buttonMany' => __('Upload :n files'),
                      'summary' => __(':done added, :failed not added.'),
                  ],
              ]))"
              x-on:submit.prevent="start()" x-on:dragover.window="dragover($event)" x-on:drop.window="if (hasFiles($event)) tab = 'media'; drop($event)" x-on:dragleave.window="$event.relatedTarget || (over = false)">
            @csrf
            <label for="upload-files" class="flex cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed border-gray-400 bg-gray-50 px-4 py-8 text-center transition hover:border-brand-500"
                   x-bind:class="over && 'border-brand-500 bg-brand-50'" data-dropzone>
                <svg class="h-8 w-8 text-gray-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/></svg>
                <span class="text-base font-medium text-gray-900">{{ __('Drop photos and videos here') }}</span>
                <span class="text-sm text-gray-600">{{ __('or') }} <span class="font-medium text-brand-600 underline">{{ __('choose them') }}</span></span>
                <span class="mt-1 text-xs text-gray-600">{{ __('JPG, PNG, WEBP, HEIC, MP4 or MOV, up to :mb MB each.', ['mb' => \App\Http\Requests\InboxUploadRequest::MAX_KB / 1024]) }}</span>
            </label>
            <input id="upload-files" name="files[]" type="file" multiple accept="image/*,video/mp4,video/quicktime" class="sr-only" x-on:change="add($event.target.files); $event.target.value = ''">

            @if ($errors->upload->any())
                <ul class="list-disc space-y-1 pl-5 text-sm text-red-600">@foreach ($errors->upload->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            @endif

            {{-- one line per file: its name and size, a progress bar, then the name it got or why it was refused --}}
            <ul x-show="items.length" x-cloak class="divide-y divide-gray-200 rounded-lg border border-gray-300 text-sm" data-upload-list>
                <template x-for="item in items" x-bind:key="item.id">
                    <li class="px-3 py-2">
                        <div class="flex items-center gap-3">
                            <span class="min-w-0 flex-1 truncate font-medium text-gray-900" x-text="item.file.name"></span>
                            <span class="flex-none text-xs text-gray-600" x-text="mb(item.file.size)"></span>
                            <button type="button" x-show="item.state === 'waiting' && ! busy" x-on:click="remove(item)" class="flex-none text-xs font-medium text-red-600 hover:underline">{{ __('Remove') }}</button>
                        </div>
                        <div x-show="item.state !== 'failed'" class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-gray-200">
                            <div class="h-full rounded-full transition-all" x-bind:class="item.state === 'done' ? 'bg-green-600' : 'bg-brand-500'" x-bind:style="`width: ${item.progress}%`"></div>
                        </div>
                        <p class="mt-1 break-words text-xs" x-bind:class="{ 'text-gray-600': item.state === 'waiting' || item.state === 'uploading', 'text-green-700': item.state === 'done', 'text-red-600': item.state === 'failed' }" x-text="status(item)"></p>
                    </li>
                </template>
            </ul>

            <fieldset>
                <legend class="text-sm font-medium text-gray-900">{{ __('For every file in this upload') }}</legend>
                <div class="mt-2 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <x-input-label for="upload-pillar" :value="__('Pillar')" />
                        <select id="upload-pillar" name="pillar" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">
                            <option value="">{{ __('No pillar') }}</option>
                            @foreach ($pillars as $pillar)
                                <option value="{{ $pillar }}" @selected(old('pillar') === $pillar)>{{ $pillarName($pillar) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="upload-season" :value="__('Season or event (optional)')" />
                        <x-text-input id="upload-season" name="season" type="text" maxlength="30" class="mt-1 block w-full text-sm" :value="old('season')" :placeholder="__('e.g. summer 2027, opening party')" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label for="upload-note" :value="__('Note (optional)')" />
                        <x-text-input id="upload-note" name="note" type="text" maxlength="500" class="mt-1 block w-full text-sm" :value="old('note')" />
                    </div>
                    <label class="flex items-start gap-2 text-sm text-gray-900 sm:col-span-2">
                        <input type="checkbox" name="people_consent" value="1" class="mt-0.5 rounded border-gray-300 text-brand-600" @checked(old('people_consent'))>
                        <span>{{ __('The people shown have agreed to appear on social media.') }}</span>
                    </label>
                    <label class="flex items-start gap-2 text-sm text-gray-900 sm:col-span-2">
                        <input type="checkbox" name="ugc_permission" value="1" class="mt-0.5 rounded border-gray-300 text-brand-600" @checked(old('ugc_permission'))>
                        <span>{{ __('This was made by a guest and they allow us to repost it.') }}</span>
                    </label>
                </div>
            </fieldset>

            <div class="flex flex-wrap items-center gap-3">
                <x-primary-button x-bind:disabled="busy"><x-icon name="cloud-arrow-up" class="h-4 w-4" /><span x-text="buttonLabel()">{{ __('Upload') }}</span></x-primary-button>
                <button type="button" x-show="items.length && ! busy" x-cloak x-on:click="items = []" class="text-sm font-medium text-gray-900 hover:text-brand-600">{{ __('Clear the list') }}</button>
                <p x-show="summary()" x-cloak x-text="summary()" class="text-sm text-gray-600"></p>
                <button type="button" x-show="done() && ! busy" x-cloak x-on:click="location.reload()" class="{{ $btn }}">{{ __('Show the new files') }}</button>
            </div>
        </form>
    </x-section>

    {{-- The files: filters, then a grid of big thumbnails; a click opens the preview --}}
    <x-section icon="photo" :title="__('Photos and videos').' ('.$assets->count().')'" flush>
        <form method="GET" action="{{ route('clients.assets', $client) }}" class="grid grid-cols-2 gap-2 px-4 py-3 text-sm sm:flex sm:flex-wrap sm:items-center sm:px-6" data-filters>
            <div class="col-span-2 flex gap-2 sm:w-80">
                <input type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Search by name or tag') }}" aria-label="{{ __('Search by name or tag') }}" class="block min-w-0 flex-1 rounded-md border-gray-300 text-sm shadow-sm">
                <button type="submit" class="{{ $btn }} flex-none"><x-icon name="magnifying-glass" class="h-4 w-4" />{{ __('Search') }}</button>
            </div>
            <select name="kind" onchange="this.form.submit()" aria-label="{{ __('Photos and videos') }}" class="col-span-2 min-w-0 rounded-md border-gray-300 text-sm shadow-sm">
                <option value="">{{ __('Photos and videos') }}</option>
                <option value="image" @selected(request('kind') === 'image')>{{ __('Photos') }}</option>
                <option value="video" @selected(request('kind') === 'video')>{{ __('Videos') }}</option>
            </select>
            <select name="pillar" onchange="this.form.submit()" aria-label="{{ __('Pillar') }}" class="min-w-0 rounded-md border-gray-300 text-sm shadow-sm">
                <option value="">{{ __('Any pillar') }}</option>
                @foreach ($pillars as $pillar)
                    <option value="{{ $pillar }}" @selected(request('pillar') === $pillar)>{{ $pillarName($pillar) }}</option>
                @endforeach
            </select>
            <select name="used" onchange="this.form.submit()" aria-label="{{ __('Used in a post or not') }}" class="min-w-0 rounded-md border-gray-300 text-sm shadow-sm">
                <option value="">{{ __('Used or not') }}</option>
                <option value="yes" @selected(request('used') === 'yes')>{{ __('Used in a post') }}</option>
                <option value="no" @selected(request('used') === 'no')>{{ __('Not used yet') }}</option>
            </select>
            @if ($filtered)
                <a href="{{ route('clients.assets', $client) }}" class="col-span-2 px-1 font-medium text-gray-900 underline hover:text-brand-600">{{ __('Clear filters') }}</a>
            @endif
        </form>

        <div class="grid grid-cols-2 gap-3 p-4 sm:grid-cols-3 sm:gap-4 sm:p-6 lg:grid-cols-4">
            @forelse ($assets as $asset)
                @php
                    $usedIn = $asset->posts->map(fn ($post) => $post->trashed() ? $post->name.' ('.__('recycle bin').')' : $post->name)->join(', ');
                    $confirm = $usedIn === '' ? __('Delete this file?') : __('Delete this file? It is used in :posts and will be removed from it.', ['posts' => $usedIn]);
                    $open = [
                        'kind' => $asset->kind,
                        'name' => $asset->name,
                        'meta' => $asset->original_name.' · '.$mb($asset->size).' · '.$asset->created_at->format('d-m-Y'),
                        'url' => route('assets.view', $asset),
                        'download' => route('assets.download', $asset),
                        'broken' => false,
                    ];
                @endphp
                <article class="flex min-w-0 flex-col overflow-hidden rounded-lg border border-gray-300" data-asset="{{ $asset->id }}">
                    @if ($asset->kind === 'image')
                        <button type="button" x-on:click="preview = @js($open)" class="relative block aspect-square w-full overflow-hidden bg-gray-100" title="{{ __('Open') }}: {{ $asset->original_name }}">
                            <span class="absolute inset-0 flex items-center justify-center text-xs font-medium uppercase text-gray-600">{{ pathinfo($asset->name, PATHINFO_EXTENSION) }}</span>
                            <img src="{{ route('assets.thumb', $asset) }}" alt="{{ $asset->original_name }}" loading="lazy" class="relative h-full w-full object-cover" onerror="this.remove()">
                        </button>
                    @else
                        <button type="button" x-on:click="preview = @js($open)" class="flex aspect-square w-full flex-col items-center justify-center gap-2 bg-gray-900 text-white" title="{{ __('Open') }}: {{ $asset->original_name }}">
                            <span class="flex h-12 w-12 items-center justify-center rounded-full bg-white/15"><svg class="ml-0.5 h-6 w-6" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.14v13.72a1 1 0 001.5.86l11-6.86a1 1 0 000-1.72l-11-6.86A1 1 0 008 5.14z"/></svg></span>
                            <span class="text-xs font-medium">{{ __('video') }}</span>
                        </button>
                    @endif
                    <div class="flex min-w-0 flex-1 flex-col gap-1 p-3 text-xs">
                        <p class="truncate font-mono text-gray-900" title="{{ $asset->name }}">{{ $asset->name }}</p>
                        <p class="text-gray-600">{{ $pillarName($asset->pillar) }} · {{ $asset->created_at->format('d-m-Y') }} · {{ $sources[$asset->uploaded_via] ?? $asset->uploaded_via }}</p>
                        @if ($asset->season || $asset->people_consent || $asset->ugc_permission)
                            <p class="flex flex-wrap gap-1">
                                @if ($asset->season)<span class="rounded bg-gray-100 px-1.5 py-0.5 text-gray-700">{{ $asset->season }}</span>@endif
                                @if ($asset->people_consent)<span class="rounded bg-green-100 px-1.5 py-0.5 text-green-800">{{ __('consent') }}</span>@endif
                                @if ($asset->ugc_permission)<span class="rounded bg-green-100 px-1.5 py-0.5 text-green-800">{{ __('UGC ok') }}</span>@endif
                            </p>
                        @endif
                        @if ($asset->note) <p class="break-words text-gray-600">{{ $asset->note }}</p> @endif
                        <div>
                            @forelse ($asset->posts as $post)
                                @if ($post->trashed())
                                    <a href="{{ route('clients.bin', $client) }}" class="block truncate text-gray-600 hover:text-brand-600" title="{{ $post->name }}">{{ __('In the recycle bin:') }} {{ $post->name }}</a>
                                @else
                                    <a href="{{ route('posts.edit', $post) }}" class="block truncate font-medium text-gray-900 hover:text-brand-600" title="{{ $post->name }}">{{ __('In') }} {{ $post->name }}</a>
                                @endif
                            @empty
                                <span class="text-gray-500">{{ __('Not used in a post yet') }}</span>
                            @endforelse
                        </div>
                        <div class="mt-auto flex flex-wrap items-center gap-x-2.5 gap-y-1 pt-2 sm:gap-x-3">
                            <button type="button" x-on:click="preview = @js($open)" class="inline-flex items-center gap-1 font-medium text-gray-900 hover:text-brand-600"><x-icon name="eye" class="h-4 w-4" />{{ __('Open') }}</button>
                            <a href="{{ route('assets.download', $asset) }}" class="inline-flex items-center gap-1 font-medium text-gray-900 hover:text-brand-600"><x-icon name="arrow-down-tray" class="h-4 w-4" />{{ __('Download') }}</a>
                            <form method="POST" action="{{ route('assets.destroy', $asset) }}" data-confirm="{{ $confirm }}" onsubmit="return confirm(this.dataset.confirm)">
                                @csrf
                                @method('DELETE')
                                @foreach ($keep as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
                                <button type="submit" class="inline-flex items-center gap-1 text-red-600 hover:underline"><x-icon name="trash" class="h-4 w-4" />{{ __('Delete') }}</button>
                            </form>
                        </div>
                    </div>
                </article>
            @empty
                @if ($filtered)
                    <x-empty-state icon="funnel" class="col-span-full" :action="__('Show everything')" :href="route('clients.assets', $client)">{{ __('Nothing matches these filters.') }}</x-empty-state>
                @else
                    <x-empty-state icon="photo" class="col-span-full" :action="__('Upload link')" href="#sources">{{ __('Nothing uploaded yet. Add files above, or send the client the upload link.') }}</x-empty-state>
                @endif
            @endforelse
        </div>
    </x-section>
</div>
